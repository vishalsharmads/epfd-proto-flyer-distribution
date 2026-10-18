<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Design\DesignService;
use App\Domain\Pricing\PricingService;
use App\Http\Controllers\App\DesignOrderController as AppDesignOrderController;
use App\Http\Controllers\App\Support\Presenter;
use App\Models\AuditLog;
use App\Models\DesignAsset;
use App\Models\DesignOrder;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Admin → Design orders: brief review, designer assignment, proofs, final files, messages, refunds. */
class DesignOrderController extends AdminController
{
    /** Orders where the studio owes the business a proof (dashboard KPI + filter). */
    public const AWAITING_PROOF = ['submitted', 'in_design', 'changes_requested'];

    public function __construct(private readonly DesignService $design) {}

    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();
        $type = $request->string('type')->toString();
        $q = trim($request->string('q')->toString());
        $designer = $request->string('designer')->toString();
        $rows = DesignOrder::query()->with(['business:id,name', 'designer:id,name'])
            ->where('status', '!=', 'draft')
            ->when(in_array($status, DesignOrder::STATUSES, true), fn ($w) => $w->where('status', $status))
            ->when($status === 'awaiting_proof', fn ($w) => $w->whereIn('status', self::AWAITING_PROOF))
            ->when(is_string($type) && $type !== '', fn ($w) => $w->where('design_type', $type))
            ->when($designer === 'unassigned', fn ($w) => $w->whereNull('designer_id'))
            ->when(ctype_digit($designer), fn ($w) => $w->where('designer_id', (int) $designer))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('reference', 'ilike', "%{$q}%")->orWhere('headline', 'ilike', "%{$q}%")->orWhereHas('business', fn ($b) => $b->where('name', 'ilike', "%{$q}%"))))
            ->latest('id')->paginate(20)->withQueryString();

        return Inertia::render('admin/design-orders/index', [
            'orders' => $rows->through(fn (DesignOrder $d) => [
                'id' => $d->id, 'reference' => $d->reference, 'status' => $d->status, 'design_type' => $d->design_type, 'type_label' => $d->typeLabel(),
                'package' => $d->package, 'package_label' => $d->packageLabel(), 'headline' => $d->headline, 'total_cents' => $d->total_cents, 'due_date' => $d->due_date?->toDateString(),
                'revisions_used' => $d->revisions_used, 'revisions_included' => $d->revisions_included,
                'submitted_at' => $d->submitted_at?->toIso8601String(), 'created_at' => $d->created_at?->toIso8601String(),
                'business' => $d->business?->only('id', 'name'), 'designer' => $d->designer?->only('id', 'name'),
            ]),
            'filters' => ['status' => $status ?: null, 'type' => $type ?: null, 'q' => $q ?: null, 'designer' => $designer ?: null],
            'counts' => ($counts = DesignOrder::where('status', '!=', 'draft')->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'))
                ->put('awaiting_proof', $counts->only(self::AWAITING_PROOF)->sum()),
            'designers' => $this->designers(),
            // Current services plus any (since removed) that existing orders still use.
            'types' => collect(app(PricingService::class)->designCard()['types'])->map(fn ($t, $k) => ['value' => (string) $k, 'label' => (string) preg_replace('/\s+design$/i', '', (string) $t['label'])])
                ->union(DesignOrder::where('status', '!=', 'draft')->distinct()->pluck('design_type')->mapWithKeys(fn ($k) => [$k => ['value' => $k, 'label' => ucfirst(str_replace('_', ' ', $k))]]))
                ->values(),
        ]);
    }

    public function show(DesignOrder $designOrder): Response
    {
        $designOrder->load(['business:id,name,email,status,contact_name,mobile', 'payments', 'invoice:id,invoiceable_type,invoiceable_id,number', 'printOrder:id,reference,status', 'user:id,name,email']);

        return Inertia::render('admin/design-orders/show', [
            'order' => [
                ...Presenter::designOrder($designOrder, true),
                'business' => $designOrder->business,
                'user' => $designOrder->user?->only('id', 'name', 'email'),
                'payments' => $designOrder->payments,
                'invoice' => $designOrder->invoice,
                'print_order' => $designOrder->printOrder?->only('id', 'reference', 'status'),
            ],
            'next' => DesignService::NEXT[$designOrder->status] ?? [],
            'can_proof' => in_array($designOrder->status, DesignService::PROOFABLE, true),
            'can_final' => in_array($designOrder->status, ['approved', 'completed'], true),
            'designers' => $this->designers(),
            'thread' => Presenter::thread($designOrder->thread),
            'history' => AuditLog::where('auditable_type', $designOrder->getMorphClass())->where('auditable_id', $designOrder->id)->with('user:id,name')->latest('id')->get(),
        ]);
    }

    public function asset(Request $request, DesignOrder $designOrder, DesignAsset $asset): StreamedResponse
    {
        abort_unless($asset->design_order_id === $designOrder->id && Storage::disk('local')->exists($asset->path), 404);

        return AppDesignOrderController::stream($asset, $request->boolean('download'));
    }

    public function assign(Request $request, DesignOrder $designOrder): RedirectResponse
    {
        $data = $request->validate(['designer_id' => ['nullable', 'integer', 'exists:users,id']]);
        $designer = ! empty($data['designer_id']) ? User::find($data['designer_id']) : null;

        return $this->attempt(fn () => $this->design->assign($designOrder, $designer, $request->user()), $designer ? "Assigned to {$designer->name}." : 'Designer unassigned.');
    }

    public function transition(Request $request, DesignOrder $designOrder): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:in_design,completed,cancelled'],
            'note' => ['required_if:status,cancelled', 'nullable', 'string', 'max:1000'],
        ], ['note.required_if' => 'Give a reason for cancelling.']);

        return $this->attempt(fn () => $this->design->transition($designOrder, $data['status'], $data['note'] ?? null), 'Design order moved to '.str_replace('_', ' ', $data['status']).'.');
    }

    public function uploadProof(Request $request, DesignOrder $designOrder): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:20480'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['file.mimes' => 'Upload a PDF, PNG or JPG proof.', 'file.max' => 'Proofs can be at most 20 MB.']);

        return $this->attempt(fn () => $this->design->uploadProof($designOrder, $request->file('file'), $request->user(), $data['note'] ?? null), 'Proof uploaded — the business has been emailed.');
    }

    public function uploadFinal(Request $request, DesignOrder $designOrder): RedirectResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['file', 'mimes:pdf,png,jpg,jpeg,svg,zip,ai,eps,psd,indd', 'max:102400'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['files.*.max' => 'Each file can be at most 100 MB.']);

        return $this->attempt(function () use ($request, $designOrder) {
            foreach ($request->file('files') as $file) {
                $this->design->uploadFinal($designOrder, $file, $request->user(), $request->input('note'));
            }
        }, 'Final files uploaded.');
    }

    public function message(Request $request, DesignOrder $designOrder): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:4000']]);

        return $this->attempt(fn () => $this->design->postMessage($designOrder, $request->user(), $data['body'], true), 'Message sent to the business.');
    }

    /** @return list<array{id:int,name:string}> */
    private function designers(): array
    {
        return User::role(User::ADMIN_ROLES)->orderBy('name')->get(['id', 'name'])->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->all();
    }
}
