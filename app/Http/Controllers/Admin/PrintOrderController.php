<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\Artwork;
use App\Models\AuditLog;
use App\Models\PrintOrder;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrintOrderController extends AdminController
{
    public const NEXT = [
        'submitted' => ['awaiting_artwork_approval', 'cancelled'],
        'awaiting_artwork_approval' => ['in_printing', 'cancelled'],
        'in_printing' => ['shipped', 'cancelled'],
        'shipped' => ['completed'],
    ];

    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();
        $q = trim($request->string('q')->toString());
        $rows = PrintOrder::query()->with(['business:id,name', 'product:id,name'])
            ->where('status', '!=', 'draft')
            ->when(in_array($status, PrintOrder::STATUSES, true), fn ($w) => $w->where('status', $status))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('reference', 'ilike', "%{$q}%")->orWhereHas('business', fn ($b) => $b->where('name', 'ilike', "%{$q}%"))))
            ->latest('id')->paginate(20)->withQueryString();

        return Inertia::render('admin/print-orders/index', [
            'orders' => $rows,
            'filters' => ['status' => $status ?: null, 'q' => $q ?: null],
            'counts' => PrintOrder::where('status', '!=', 'draft')->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
        ]);
    }

    public function show(PrintOrder $printOrder): Response
    {
        $printOrder->load(['business:id,name,email,status', 'product', 'artworks', 'payments', 'invoice:id,invoiceable_type,invoiceable_id,number', 'campaign:id,reference,name']);

        return Inertia::render('admin/print-orders/show', [
            'order' => $printOrder,
            'next' => self::NEXT[$printOrder->status] ?? [],
            'history' => AuditLog::where('auditable_type', $printOrder->getMorphClass())->where('auditable_id', $printOrder->id)->with('user:id,name')->latest('id')->get(),
        ]);
    }

    public function transition(Request $request, PrintOrder $printOrder): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', PrintOrder::STATUSES)],
            'note' => ['nullable', 'string', 'max:1000'],
            'vendor' => ['required_if:status,shipped', 'nullable', 'string', 'max:120'],
            'tracking_number' => ['required_if:status,shipped', 'nullable', 'string', 'max:120'],
        ]);
        if (! in_array($data['status'], self::NEXT[$printOrder->status] ?? [], true)) {
            return back()->with('error', "Can't move an order from ".str_replace('_', ' ', $printOrder->status).' to '.str_replace('_', ' ', $data['status']).'.');
        }
        if ($data['status'] === 'in_printing' && $printOrder->artworks()->where('status', '!=', 'approved')->exists()) {
            return back()->with('error', 'Approve all artwork before sending to print.');
        }
        $from = $printOrder->status;
        $printOrder->forceFill(array_filter([
            'status' => $data['status'],
            'vendor' => $data['vendor'] ?? null,
            'tracking_number' => $data['tracking_number'] ?? null,
            'completed_at' => $data['status'] === 'completed' ? now() : null,
        ], fn ($v) => $v !== null))->save();
        Audit::log('print_order.status_changed', $printOrder, ['from' => $from, 'to' => $data['status'], 'note' => $data['note'] ?? null, 'tracking_number' => $data['tracking_number'] ?? null]);

        return back()->with('success', 'Order moved to '.str_replace('_', ' ', $data['status']).'.');
    }

    public function vendor(Request $request, PrintOrder $printOrder): RedirectResponse
    {
        $data = $request->validate(['vendor' => ['required', 'string', 'max:120'], 'tracking_number' => ['nullable', 'string', 'max:120']]);
        $printOrder->forceFill($data)->save();
        Audit::log('print_order.vendor_assigned', $printOrder, $data);

        return back()->with('success', 'Vendor saved.');
    }

    public function reviewArtwork(Request $request, PrintOrder $printOrder, Artwork $artwork): RedirectResponse
    {
        abort_unless($artwork->print_order_id === $printOrder->id, 404);
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected'], 'note' => ['required_if:decision,rejected', 'nullable', 'string', 'max:1000']]);
        $artwork->forceFill(['status' => $data['decision'], 'review_note' => $data['note'] ?? null, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()])->save();
        Audit::log('artwork.'.$data['decision'], $printOrder, ['artwork_id' => $artwork->id, 'side' => $artwork->side, 'note' => $data['note'] ?? null]);
        if ($printOrder->status === 'submitted') {
            $printOrder->forceFill(['status' => 'awaiting_artwork_approval'])->save();
        }
        $allApproved = ! $printOrder->artworks()->where('status', '!=', 'approved')->exists();
        if ($data['decision'] === 'approved' && $allApproved && $printOrder->status === 'awaiting_artwork_approval') {
            $printOrder->forceFill(['status' => 'in_printing'])->save();
            Audit::log('print_order.status_changed', $printOrder, ['from' => 'awaiting_artwork_approval', 'to' => 'in_printing', 'via' => 'artwork approval']);

            return back()->with('success', 'Artwork approved — order sent to printing.');
        }

        return back()->with('success', $data['decision'] === 'approved' ? 'Artwork approved.' : 'Artwork rejected; the business will be asked to re-upload.');
    }

    public function uploadProof(Request $request, PrintOrder $printOrder): RedirectResponse
    {
        $request->validate(['proof' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:20480']]);
        $path = $request->file('proof')->store("proofs/print/{$printOrder->id}", 'local');
        $printOrder->forceFill(['proof_path' => $path])->save();
        Audit::log('print_order.proof_uploaded', $printOrder, ['path' => $path]);

        return back()->with('success', 'Proof uploaded.');
    }

    public function artworkFile(PrintOrder $printOrder, Artwork $artwork): StreamedResponse
    {
        abort_unless($artwork->print_order_id === $printOrder->id, 404);

        return $this->file($artwork->path, $artwork->original_name);
    }

    public function proofFile(PrintOrder $printOrder): StreamedResponse
    {
        abort_unless((bool) $printOrder->proof_path, 404);

        return $this->file($printOrder->proof_path, basename((string) $printOrder->proof_path));
    }

    private function file(string $path, string $name): StreamedResponse
    {
        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->response($path, $name, ['Content-Disposition' => 'inline; filename="'.addslashes($name).'"']);
            }
        }
        abort(404, 'File not found.');
    }
}
