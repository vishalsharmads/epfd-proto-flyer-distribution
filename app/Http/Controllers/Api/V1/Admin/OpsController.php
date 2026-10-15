<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\ScopesToBusiness;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Http\Resources\SupportThreadResource;
use App\Models\AuditLog;
use App\Models\GnafRelease;
use App\Models\Suburb;
use App\Models\SupportThread;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Admin · Data & support
 */
class OpsController extends Controller
{
    use ScopesToBusiness;

    /** Imported G-NAF releases with row/skip counts. */
    public function gnafReleases(Request $request): JsonResponse
    {
        return response()->json(GnafRelease::latest('id')->paginate($this->perPage($request)));
    }

    /** Suburbs with serviced flag and letterbox stats (filter `state`, `q`, `is_serviced`). */
    public function suburbs(Request $request): JsonResponse
    {
        $request->validate(['state' => ['nullable', 'string', 'size:3'], 'q' => ['nullable', 'string', 'max:60'], 'is_serviced' => ['nullable', 'boolean']]);

        return response()->json(
            Suburb::query()->leftJoin('suburb_stats', 'suburb_stats.suburb_id', '=', 'suburbs.id')
                ->select('suburbs.id', 'suburbs.name', 'suburbs.state', 'suburbs.postcode', 'suburbs.is_serviced', 'suburbs.boundary_source', 'suburb_stats.houses', 'suburb_stats.units', 'suburb_stats.letterboxes')
                ->when($request->query('state'), fn ($q, $s) => $q->where('suburbs.state', strtoupper($s)))
                ->when($request->query('q'), fn ($q, $s) => $q->whereRaw('suburbs.name ILIKE ?', [$s.'%']))
                ->when($request->has('is_serviced'), fn ($q) => $q->where('suburbs.is_serviced', $request->boolean('is_serviced')))
                ->orderBy('suburbs.name')->paginate($this->perPage($request))
        );
    }

    /** Toggle whether a suburb is serviced (shown in the suburb search). */
    public function updateSuburb(Request $request, int $suburb): JsonResponse
    {
        $s = Suburb::findOrFail($suburb);
        $data = $request->validate(['is_serviced' => ['required', 'boolean']]);
        $s->update($data);
        Audit::log('suburb.serviced', $s, $data);

        return response()->json(['data' => ['id' => $s->id, 'name' => $s->name, 'state' => $s->state, 'postcode' => $s->postcode, 'is_serviced' => $s->is_serviced]]);
    }

    /** All support threads (filter `status`, `channel`). */
    public function threads(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', 'in:open,pending,resolved'], 'channel' => ['nullable', 'in:support,job']]);

        return SupportThreadResource::collection(
            SupportThread::query()
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->when($request->query('channel'), fn ($q, $c) => $q->where('channel', $c))
                ->orderByDesc('last_message_at')->paginate($this->perPage($request))
        );
    }

    /** Staff reply; optionally set the thread `status`. */
    public function replyThread(Request $request, int $thread): JsonResponse
    {
        $t = SupportThread::findOrFail($thread);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000'], 'status' => ['nullable', 'in:open,pending,resolved']]);
        $msg = $t->messages()->create(['user_id' => $request->user()->id, 'from_staff' => true, 'body' => $data['body']]);
        $t->update(['last_message_at' => now(), 'status' => $data['status'] ?? 'pending', 'assigned_to' => $t->assigned_to ?? $request->user()->id]);

        return response()->json(['data' => MessageResource::make($msg->load('user'))], 201);
    }

    /** Append-only audit log (filter `action`, `user_id`). */
    public function auditLogs(Request $request): JsonResponse
    {
        $request->validate(['action' => ['nullable', 'string', 'max:80'], 'user_id' => ['nullable', 'integer']]);

        return response()->json(
            AuditLog::query()
                ->when($request->query('action'), fn ($q, $a) => $q->where('action', 'like', $a.'%'))
                ->when($request->query('user_id'), fn ($q, $u) => $q->where('user_id', $u))
                ->latest('id')->paginate($this->perPage($request))
        );
    }
}
