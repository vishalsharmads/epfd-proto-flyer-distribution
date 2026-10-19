<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Controllers\App\Support\Presenter;
use App\Http\Controllers\Controller;
use App\Models\SupportThread;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SupportController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('app/support/index', [
            'threads' => SupportThread::where('business_id', $request->user()->business_id)->withCount('messages')->latest('last_message_at')->limit(100)->get()
                ->map(fn (SupportThread $t) => [
                    'id' => $t->id, 'subject' => $t->subject, 'status' => $t->status, 'channel' => $t->channel, 'messages_count' => $t->messages_count,
                    'last_message_at' => ($t->last_message_at ?? $t->created_at)?->toIso8601String(),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'min:3', 'max:160'],
            'body' => ['required', 'string', 'min:5', 'max:4000'],
        ], ['body.required' => 'Tell us how we can help.']);
        $thread = DB::transaction(function () use ($request, $data) {
            $t = SupportThread::create([
                'business_id' => $request->user()->business_id, 'user_id' => $request->user()->id,
                'subject' => $data['subject'], 'status' => 'open', 'channel' => 'support', 'last_message_at' => now(),
            ]);
            $t->messages()->create(['user_id' => $request->user()->id, 'from_staff' => false, 'body' => $data['body']]);

            return $t;
        });

        return redirect()->route('app.support.show', $thread)->with('success', 'Request sent. We usually reply within one business day.');
    }

    public function show(Request $request, SupportThread $thread): Response
    {
        $this->authorize('view', $thread);
        $related = null;
        if ($thread->related_type && $thread->related) {
            $r = $thread->related;
            $related = ['reference' => $r->reference, 'url' => $thread->related_type === 'campaign' ? route('app.jobs.campaign', $r->id) : route('app.jobs.print', $r->id)];
        }

        return Inertia::render('app/support/show', ['thread' => Presenter::thread($thread), 'related' => $related]);
    }

    public function reply(Request $request, SupportThread $thread): RedirectResponse
    {
        $this->authorize('update', $thread);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:4000']], ['body.required' => 'Write a message first.']);
        $thread->messages()->create(['user_id' => $request->user()->id, 'from_staff' => false, 'body' => $data['body']]);
        $thread->forceFill(['last_message_at' => now(), 'status' => 'open'])->save();

        return back()->with('success', 'Reply sent.');
    }
}
