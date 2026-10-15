<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Payments\CheckoutService;
use App\Http\Controllers\Api\V1\Concerns\ScopesToBusiness;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use App\Http\Resources\CreditEntryResource;
use App\Models\Business;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Admin · Businesses
 */
class BusinessController extends Controller
{
    use ScopesToBusiness;

    /** List businesses (filter by `status`, search `q` on name/e-mail/ABN). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', 'in:pending,approved,suspended'], 'q' => ['nullable', 'string', 'max:100']]);

        return BusinessResource::collection(
            Business::query()
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->whereRaw('name ILIKE ?', ["%{$s}%"])->orWhereRaw('email ILIKE ?', ["%{$s}%"])->orWhere('abn', preg_replace('/\D/', '', $s))))
                ->latest('id')->paginate($this->perPage($request))
        );
    }

    /** Business detail with users and counts. */
    public function show(int $business): JsonResponse
    {
        $b = Business::withCount(['campaigns', 'printOrders', 'payments'])->with('users')->findOrFail($business);

        return response()->json(['data' => [
            ...BusinessResource::make($b)->resolve(),
            'users' => $b->users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'email_verified' => $u->email_verified_at !== null]),
            'counts' => ['campaigns' => $b->campaigns_count, 'print_orders' => $b->print_orders_count, 'payments' => $b->payments_count],
        ]]);
    }

    /** Approve a business (enables payment). */
    public function approve(Request $request, int $business): JsonResponse
    {
        $b = Business::findOrFail($business);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $b->forceFill(['status' => Business::APPROVED, 'approved_at' => now(), 'approved_by' => $request->user()->id, 'status_note' => $data['note'] ?? null])->save();
        Audit::log('business.approved', $b);

        return response()->json(['data' => BusinessResource::make($b)]);
    }

    /** Suspend a business (blocks payment). */
    public function suspend(Request $request, int $business): JsonResponse
    {
        $b = Business::findOrFail($business);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $b->forceFill(['status' => Business::SUSPENDED, 'status_note' => $data['reason']])->save();
        Audit::log('business.suspended', $b, ['reason' => $data['reason']]);

        return response()->json(['data' => BusinessResource::make($b)]);
    }

    /** Add (positive) or remove (negative) account credit. */
    public function credits(Request $request, int $business, CheckoutService $checkout): JsonResponse
    {
        $b = Business::findOrFail($business);
        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'not_in:0', 'between:-10000000,10000000'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $entry = $checkout->adjustCredits($b, (int) $data['amount_cents'], $data['reason'], null, $request->user());
        Audit::log('credits.adjusted', $b, $data);

        return response()->json(['data' => CreditEntryResource::make($entry)], 201);
    }
}
