<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopesToBusiness;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Http\Resources\SupportThreadResource;
use App\Models\SupportThread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * @tags Support
 */
class SupportController extends Controller
{
    use ScopesToBusiness;

    /** Your support threads (latest activity first). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', 'in:open,pending,resolved']]);

        return SupportThreadResource::collection(
            $this->business($request)->supportThreads()
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->orderByDesc('last_message_at')->paginate($this->perPage($request))
        );
    }

    /** Open a thread with a first message. Optionally link it to a campaign or print order you own. */
    public function store(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
            'campaign_id' => ['nullable', 'integer'],
            'print_order_id' => ['nullable', 'integer'],
        ]);
        $related = match (true) {
            isset($data['campaign_id']) => $business->campaigns()->findOrFail($data['campaign_id']),
            isset($data['print_order_id']) => $business->printOrders()->findOrFail($data['print_order_id']),
            default => null,
        };

        $thread = DB::transaction(function () use ($business, $request, $data, $related) {
            $thread = SupportThread::create([
                'business_id' => $business->id,
                'user_id' => $request->user()->id,
                'subject' => $data['subject'],
                'status' => 'open',
                'channel' => $related ? 'job' : 'support',
                'related_type' => $related?->getMorphClass(),
                'related_id' => $related?->getKey(),
                'last_message_at' => now(),
            ]);
            $thread->messages()->create(['user_id' => $request->user()->id, 'from_staff' => false, 'body' => $data['body']]);

            return $thread;
        });

        return response()->json(['data' => SupportThreadResource::make($thread->load('messages.user'))], 201);
    }

    /** A thread with all messages. Staff messages are marked read. */
    public function show(Request $request, int $thread): JsonResponse
    {
        $model = $this->business($request)->supportThreads()->findOrFail($thread);
        $model->messages()->where('from_staff', true)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['data' => SupportThreadResource::make($model->load('messages.user'))]);
    }

    /** Reply to a thread (re-opens a resolved thread). */
    public function reply(Request $request, int $thread): JsonResponse
    {
        $model = $this->business($request)->supportThreads()->findOrFail($thread);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $msg = $model->messages()->create(['user_id' => $request->user()->id, 'from_staff' => false, 'body' => $data['body']]);
        $model->update(['last_message_at' => now(), 'status' => 'open']);

        return response()->json(['data' => MessageResource::make($msg->load('user'))], 201);
    }
}
