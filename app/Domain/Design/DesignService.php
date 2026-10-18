<?php

declare(strict_types=1);

namespace App\Domain\Design;

use App\Domain\Pricing\PricingFactory;
use App\Domain\Pricing\PricingService;
use App\Exceptions\DomainProblem;
use App\Http\Controllers\App\Support\PrintPricer;
use App\Mail\DesignProofReadyMail;
use App\Models\Business;
use App\Models\DesignAsset;
use App\Models\DesignOrder;
use App\Models\PrintOrder;
use App\Models\PrintProduct;
use App\Models\SupportThread;
use App\Models\User;
use App\Support\Audit;
use App\Support\BusinessDays;
use App\Support\Reference;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Design Studio workflow, shared by the business portal, the admin panel and the REST API.
 *
 *   draft ──pay──▶ submitted ──▶ in_design ──proof──▶ proof_ready ──approve──▶ approved ──final files──▶ completed
 *                                    ▲                    │
 *                                    └── changes_requested ◀┘  (each request uses one revision)
 *
 * Revisions beyond the package allowance are NOT charged online: the request is accepted, flagged as an
 * extra revision and invoiced separately at the rate card's `extra_revision` price (see README → Design Studio).
 */
final class DesignService
{
    /** Staff status transitions (proof upload and business approve/request-changes have their own methods). */
    public const NEXT = [
        'submitted' => ['in_design', 'cancelled'],
        'in_design' => ['cancelled'],
        'proof_ready' => ['in_design', 'cancelled'],
        'changes_requested' => ['in_design', 'cancelled'],
        'approved' => ['completed'],
    ];

    /** Statuses in which staff may upload a (new) proof. */
    public const PROOFABLE = ['submitted', 'in_design', 'proof_ready', 'changes_requested'];

    /** Print-product category → design type (for "Don't have artwork? Get it designed"). */
    public const CATEGORY_TO_TYPE = [
        'flyer' => 'flyer', 'poster' => 'flyer', 'banner' => 'flyer', 'magnet' => 'flyer',
        'brochure' => 'brochure', 'catalogue' => 'brochure', 'business_card' => 'business_card',
    ];

    /** Default print product per design type (used by "Print this design" when none was chosen). */
    public const DEFAULT_PRODUCT = [
        'flyer' => 'a5-flyers', 'brochure' => 'a4-folded-brochures', 'business_card' => 'business-cards', 'stationery' => 'business-cards',
    ];

    public const BRIEF_MIMES = ['application/pdf', 'image/png', 'image/jpeg', 'image/svg+xml'];

    public const PROOF_MIMES = ['application/pdf', 'image/png', 'image/jpeg'];

    public const MAX_BRIEF_FILES = 10;

    /** @param array<string, mixed> $attrs */
    public function createDraft(Business $business, User $user, array $attrs = []): DesignOrder
    {
        $order = new DesignOrder([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'reference' => Reference::make('DSN', 'design_orders'),
            'status' => 'draft',
            'step' => 2,
            'design_type' => 'flyer',
            'package' => 'standard',
            'sides' => 1,
            'contact_details' => trim($business->contact_name.' · '.$business->mobile.' · '.$business->email),
        ]);
        if (! empty($attrs['print_product_id']) && empty($attrs['design_type'])) {
            $product = PrintProduct::find($attrs['print_product_id']);
            $attrs['design_type'] = self::CATEGORY_TO_TYPE[$product?->category] ?? 'flyer';
        }

        return $this->update($order, $attrs);
    }

    /**
     * Fill draft fields and re-price. Unknown keys are ignored.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(DesignOrder $order, array $data): DesignOrder
    {
        if ($order->exists && $order->status !== 'draft') {
            throw new DomainProblem('conflict', 'Only draft design orders can be changed.', 409, 'Conflict');
        }
        $addons = array_key_exists('addons', $data) ? array_values((array) $data['addons']) : self::addons($order);
        $card = app(PricingService::class)->designCard();
        $errors = [];
        if (isset($data['design_type']) && ! isset($card['types'][$data['design_type']])) {
            $errors['design_type'] = ['Choose one of the listed design services.'];
        }
        if (isset($data['package']) && ! isset($card['packages'][$data['package']])) {
            $errors['package'] = ['Choose Basic, Standard or Premium.'];
        }
        foreach ($addons as $a) {
            if (! isset($card['addons'][$a])) {
                $errors['addons'] = ["Unknown add-on '{$a}'."];
            }
        }
        if (! empty($data['promo_code'])) {
            try {
                PricingFactory::promo((string) $data['promo_code'], 'design');
            } catch (DomainProblem $e) {
                $errors['promo_code'] = [$e->getMessage()];
            }
        }
        if ($errors) {
            throw new DomainProblem('validation_failed', 'Please check the highlighted fields.', 422, 'Validation failed', ['errors' => $errors]);
        }

        $fillable = ['design_type', 'package', 'size', 'print_product_id', 'sides', 'headline', 'key_message', 'call_to_action',
            'contact_details', 'brand_colours', 'style_notes', 'target_audience', 'due_date', 'promo_code', 'step'];
        $order->fill(array_intersect_key($data, array_flip($fillable)));
        if (array_key_exists('promo_code', $data) && ! $data['promo_code']) {
            $order->promo_code = null;
        }
        if ($order->print_product_id && ! $order->size) {
            $order->size = PrintProduct::whereKey($order->print_product_id)->value('size_label');
        }
        if (empty($card['types'][$order->design_type]['sides_option'])) {
            $order->sides = 1;
        }

        return $this->reprice($order, $addons);
    }

    /** @param list<string> $addons */
    public function reprice(DesignOrder $order, array $addons): DesignOrder
    {
        $promo = null;
        try {
            $promo = PricingFactory::promo($order->promo_code, 'design');
        } catch (DomainProblem) {
            $promo = null;
        }
        $quote = app(PricingService::class)->design([
            'design_type' => (string) $order->design_type,
            'package' => (string) $order->package,
            'sides' => (int) $order->sides,
            'addons' => $addons,
            'promo' => $promo,
        ])->toArray();
        $quote['meta']['estimated_first_proof'] = BusinessDays::add((int) $quote['meta']['turnaround_days'])->toDateString();

        $order->forceFill([
            'subtotal_cents' => $quote['subtotal_cents'],
            'gst_cents' => $quote['gst_cents'],
            'total_cents' => $quote['total_cents'],
            'pricing' => $quote,
            'revisions_included' => (int) $quote['meta']['revisions_included'],
        ])->save();

        return $order;
    }

    /** @return list<string> */
    public static function addons(DesignOrder $order): array
    {
        return array_values((array) ($order->pricing['meta']['addons'] ?? []));
    }

    // ------------------------------------------------------------------ files

    public function addAsset(DesignOrder $order, UploadedFile $file, string $kind, ?User $by = null, ?string $note = null): DesignAsset
    {
        $mime = (string) $file->getMimeType();
        [$w, $h] = in_array($mime, ['image/png', 'image/jpeg'], true) ? (@getimagesize($file->getRealPath()) ?: [null, null]) : [null, null];
        $version = $kind === 'brief_upload' ? 1 : ((int) $order->assets()->where('kind', $kind)->max('version')) + 1;
        $path = $file->store("design/{$order->id}/{$kind}", 'local');

        return $order->assets()->create([
            'kind' => $kind,
            'version' => $version,
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'mime' => $mime,
            'size_bytes' => (int) $file->getSize(),
            'width' => $w,
            'height' => $h,
            'uploaded_by' => $by?->id,
            'note' => $note,
        ]);
    }

    public function addBriefUpload(DesignOrder $order, UploadedFile $file, User $by): DesignAsset
    {
        if (! in_array($order->status, ['draft', 'submitted', 'in_design', 'changes_requested'], true)) {
            throw new DomainProblem('conflict', 'Reference files can no longer be added to this order — send them in a message instead.', 409, 'Conflict');
        }
        if ($order->assets()->where('kind', 'brief_upload')->count() >= self::MAX_BRIEF_FILES) {
            throw new DomainProblem('validation_failed', 'You can upload up to '.self::MAX_BRIEF_FILES.' reference files.', 422, 'Validation failed', ['errors' => ['files' => ['You can upload up to '.self::MAX_BRIEF_FILES.' reference files.']]]);
        }
        $asset = $this->addAsset($order, $file, 'brief_upload', $by);
        if ($order->status !== 'draft') {
            Audit::log('design_order.brief_file_added', $order, ['asset_id' => $asset->id, 'name' => $asset->original_name]);
        }

        return $asset;
    }

    public function deleteAsset(DesignAsset $asset): void
    {
        Storage::disk('local')->delete($asset->path);
        $asset->delete();
    }

    // ------------------------------------------------------------------ staff actions

    public function assign(DesignOrder $order, ?User $designer, User $by): DesignOrder
    {
        if ($designer && ! $designer->isStaff()) {
            throw new DomainProblem('validation_failed', 'Designers must be EPFD staff users.', 422);
        }
        $from = $order->designer_id;
        $order->forceFill(['designer_id' => $designer?->id])->save();
        Audit::log('design_order.designer_assigned', $order, ['from' => $from, 'to' => $designer?->id, 'designer' => $designer?->name]);

        return $order;
    }

    public function transition(DesignOrder $order, string $to, ?string $note = null): DesignOrder
    {
        if (! in_array($to, self::NEXT[$order->status] ?? [], true)) {
            throw new DomainProblem('conflict', "Can't move a design order from ".str_replace('_', ' ', $order->status).' to '.str_replace('_', ' ', $to).'.', 409, 'Conflict');
        }
        if ($to === 'completed' && ! $order->assets()->where('kind', 'final')->exists()) {
            throw new DomainProblem('validation_failed', 'Upload the final files before completing the order.', 422);
        }
        $from = $order->status;
        $order->forceFill(['status' => $to, 'completed_at' => $to === 'completed' ? now() : $order->completed_at])->save();
        Audit::log('design_order.status_changed', $order, ['from' => $from, 'to' => $to, 'note' => $note]);

        return $order;
    }

    public function uploadProof(DesignOrder $order, UploadedFile $file, User $by, ?string $note = null): DesignAsset
    {
        if (! in_array($order->status, self::PROOFABLE, true)) {
            throw new DomainProblem('conflict', 'Proofs can only be uploaded while the design is in progress.', 409, 'Conflict');
        }
        $asset = DB::transaction(function () use ($order, $file, $by, $note) {
            $asset = $this->addAsset($order, $file, 'proof', $by, $note);
            $from = $order->status;
            $order->forceFill(['status' => 'proof_ready', 'designer_id' => $order->designer_id ?? $by->id])->save();
            Audit::log('design_order.proof_uploaded', $order, ['from' => $from, 'to' => 'proof_ready', 'version' => $asset->version, 'asset_id' => $asset->id, 'note' => $note]);

            return $asset;
        });
        $this->notifyProof($order, $asset);

        return $asset;
    }

    public function uploadFinal(DesignOrder $order, UploadedFile $file, User $by, ?string $note = null): DesignAsset
    {
        if (! in_array($order->status, ['approved', 'completed'], true)) {
            throw new DomainProblem('conflict', 'Final files can be uploaded once the business has approved the design.', 409, 'Conflict');
        }
        $asset = $this->addAsset($order, $file, 'final', $by, $note);
        Audit::log('design_order.final_uploaded', $order, ['asset_id' => $asset->id, 'name' => $asset->original_name]);

        return $asset;
    }

    // ------------------------------------------------------------------ business actions

    /** @return array{order: DesignOrder, extra_revision: bool, extra_revision_cents: int} */
    public function requestChanges(DesignOrder $order, User $by, string $changes): array
    {
        if ($order->status !== 'proof_ready') {
            throw new DomainProblem('conflict', 'You can request changes once a proof is ready.', 409, 'Conflict');
        }
        $extraCents = (int) ($order->pricing['meta']['extra_revision_cents'] ?? round((float) (app(PricingService::class)->designCard()['addons']['extra_revision']['fixed'] ?? 35) * 100));
        $order = DB::transaction(function () use ($order, $by, $changes) {
            $order->forceFill(['status' => 'changes_requested', 'revisions_used' => $order->revisions_used + 1])->save();
            $this->postMessage($order, $by, "Changes requested (proof v{$order->latestProofVersion()}):\n\n".$changes, false);

            return $order;
        });
        $extra = $order->revisions_included < DesignOrder::UNLIMITED_REVISIONS && $order->revisions_used > $order->revisions_included;
        Audit::log('design_order.changes_requested', $order, [
            'from' => 'proof_ready', 'to' => 'changes_requested', 'revision' => $order->revisions_used,
            'included' => $order->revisions_included, 'extra_revision' => $extra, 'extra_revision_cents' => $extra ? $extraCents : 0,
        ]);

        return ['order' => $order, 'extra_revision' => $extra, 'extra_revision_cents' => $extra ? $extraCents : 0];
    }

    public function approve(DesignOrder $order, User $by): DesignOrder
    {
        if ($order->status !== 'proof_ready') {
            throw new DomainProblem('conflict', 'There is no proof waiting for approval.', 409, 'Conflict');
        }
        $order->forceFill(['status' => 'approved', 'approved_at' => now()])->save();
        Audit::log('design_order.approved', $order, ['from' => 'proof_ready', 'to' => 'approved', 'proof_version' => $order->latestProofVersion(), 'by' => $by->id]);

        return $order;
    }

    /** Files that can become print artwork: final files (oldest first = front), else the latest proof. @return list<DesignAsset> */
    public function printableFiles(DesignOrder $order): array
    {
        $finals = $order->assets()->where('kind', 'final')->orderBy('id')->get()->filter(fn (DesignAsset $a) => $a->isPrintable())->values();
        if ($finals->isNotEmpty()) {
            return $finals->all();
        }
        $proof = $order->assets()->where('kind', 'proof')->orderByDesc('version')->get()->first(fn (DesignAsset $a) => $a->isPrintable());

        return $proof ? [$proof] : [];
    }

    public function printProduct(DesignOrder $order): ?PrintProduct
    {
        if ($order->print_product_id && ($p = PrintProduct::where('is_active', true)->find($order->print_product_id))) {
            return $p;
        }
        $slug = self::DEFAULT_PRODUCT[$order->design_type] ?? null;

        return $slug ? PrintProduct::where('is_active', true)->where('slug', $slug)->first() : null;
    }

    /** "Print this design": a print-order draft with the matching product and the design attached as artwork. */
    public function createPrintOrder(DesignOrder $order, User $by, bool $alsoDistribute = false): PrintOrder
    {
        if (! in_array($order->status, ['approved', 'completed'], true)) {
            throw new DomainProblem('conflict', 'Approve the design before sending it to print.', 409, 'Conflict');
        }
        $product = $this->printProduct($order);
        if (! $product) {
            throw new DomainProblem('validation_failed', 'We don\'t have an online print product for this design yet — message us and we\'ll quote the printing.', 422);
        }
        $files = $this->printableFiles($order);
        if ($files === []) {
            throw new DomainProblem('validation_failed', 'No print-ready file (PDF, PNG or JPG) is attached to this design yet.', 422);
        }
        $business = $order->business;

        $print = DB::transaction(function () use ($order, $by, $alsoDistribute, $product, $files, $business) {
            $print = PrintOrder::create([
                'business_id' => $order->business_id,
                'user_id' => $by->id,
                'reference' => Reference::make('PRT', 'print_orders'),
                'status' => 'draft',
                'step' => 3,
                'delivery_name' => $business->contact_name,
                'delivery_address' => $business->address,
                'delivery_phone' => $business->mobile,
            ]);
            PrintPricer::applyProduct($print, $product);
            $print->refresh();

            $sides = in_array(2, $product->sides(), true) && (int) $order->sides === 2 && count($files) >= 2 ? 2 : 1;
            foreach (array_slice($files, 0, $sides) as $i => $asset) {
                $ext = pathinfo($asset->path, PATHINFO_EXTENSION) ?: 'bin';
                $path = "artworks/{$print->id}/design-{$order->reference}-".($i === 0 ? 'front' : 'back').".{$ext}";
                if (! Storage::disk('local')->exists($asset->path)) {
                    throw new DomainProblem('validation_failed', 'The design file is missing from storage — please contact support.', 422);
                }
                Storage::disk('local')->copy($asset->path, $path);
                $print->artworks()->create([
                    'side' => $i === 0 ? 'front' : 'back',
                    'path' => $path,
                    'original_name' => mb_substr($asset->original_name, 0, 200),
                    'mime' => $asset->mime,
                    'size_bytes' => $asset->size_bytes,
                    'width_px' => $asset->width,
                    'height_px' => $asset->height,
                    'status' => 'pending',
                ]);
            }
            $print->forceFill(['sides' => $sides, 'also_distribute' => $alsoDistribute && $product->distributable])->save();
            PrintPricer::reprice($print->fresh() ?? $print, []);
            $order->forceFill(['print_order_id' => $print->id])->save();
            Audit::log('design_order.print_order_created', $order, ['print_order_id' => $print->id, 'reference' => $print->reference, 'also_distribute' => $alsoDistribute]);

            return $print;
        });

        return $print->fresh() ?? $print;
    }

    // ------------------------------------------------------------------ messages + notifications

    public function postMessage(DesignOrder $order, User $by, string $body, bool $fromStaff): SupportThread
    {
        return DB::transaction(function () use ($order, $by, $body, $fromStaff) {
            $thread = $order->thread()->first() ?? SupportThread::create([
                'business_id' => $order->business_id,
                'user_id' => $order->user_id ?? $by->id,
                'subject' => 'Job '.$order->reference,
                'status' => 'open',
                'channel' => 'job',
                'related_type' => $order->getMorphClass(),
                'related_id' => $order->id,
            ]);
            $thread->messages()->create(['user_id' => $by->id, 'from_staff' => $fromStaff, 'body' => $body]);
            $thread->forceFill(['last_message_at' => now(), 'status' => $fromStaff ? 'pending' : 'open'])->save();
            if ($fromStaff) {
                Audit::log('design_order.message_sent', $order, ['thread_id' => $thread->id]);
            }

            return $thread;
        });
    }

    private function notifyProof(DesignOrder $order, DesignAsset $proof): void
    {
        try {
            $user = $order->user ?? $order->business->users()->first();
            if ($user) {
                Mail::to($user->email)->send(new DesignProofReadyMail($order, $proof, $user->name, route('app.jobs.design', $order)));
            }
        } catch (Throwable $e) {
            // Never fail a proof upload because email is down. No PII in the log line.
            Log::warning('Design proof email failed', ['design_order' => $order->reference, 'error' => $e->getMessage()]);
        }
    }
}
