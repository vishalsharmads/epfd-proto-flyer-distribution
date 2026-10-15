<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\SupportThread;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupportController extends AdminController
{
    public function index(Request $request): Response
    {
        $f = $request->only(['status', 'channel', 'assignee', 'q']);
        $rows = SupportThread::query()->with(['business:id,name', 'assignee:id,name', 'user:id,name'])->withCount('messages')
            ->when(in_array($f['status'] ?? '', ['open', 'pending', 'resolved'], true), fn ($w) => $w->where('status', $f['status']))
            ->when(in_array($f['channel'] ?? '', ['support', 'job'], true), fn ($w) => $w->where('channel', $f['channel']))
            ->when(($f['assignee'] ?? '') === 'me', fn ($w) => $w->where('assigned_to', $request->user()->id))
            ->when(($f['assignee'] ?? '') === 'none', fn ($w) => $w->whereNull('assigned_to'))
            ->when(! empty($f['q']), fn ($w) => $w->where(fn ($x) => $x->where('subject', 'ilike', '%'.$f['q'].'%')->orWhereHas('business', fn ($b) => $b->where('name', 'ilike', '%'.$f['q'].'%'))))
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")
            ->latest('last_message_at')->latest('id')
            ->paginate(20)->withQueryString();

        return Inertia::render('admin/support/index', [
            'threads' => $rows,
            'filters' => $f,
            'counts' => SupportThread::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
        ]);
    }

    public function show(SupportThread $thread): Response
    {
        $thread->load(['business:id,name,email', 'user:id,name,email', 'assignee:id,name', 'related', 'messages.user:id,name']);
        $thread->messages()->where('from_staff', false)->whereNull('read_at')->update(['read_at' => now()]);
        $related = $thread->related;

        return Inertia::render('admin/support/show', [
            'thread' => $thread,
            'related' => $related ? ['type' => class_basename($related), 'id' => $related->getKey(), 'reference' => $related->reference ?? null] : null,
            'staff' => User::role(User::ADMIN_ROLES)->orderBy('name')->get(['id', 'name']),
            'notes' => AuditLog::where('action', 'support.internal_note')->where('auditable_type', $thread->getMorphClass())->where('auditable_id', $thread->id)
                ->with('user:id,name')->orderBy('id')->get(),
            'can_reply' => request()->user()->can('support.reply'),
        ]);
    }

    public function reply(Request $request, SupportThread $thread): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:5000'],
            'internal' => ['boolean'],
            'status' => ['nullable', 'in:open,pending,resolved'],
        ]);
        if ($data['internal'] ?? false) {
            Audit::log('support.internal_note', $thread, ['note' => $data['body']]);

            return back()->with('success', 'Internal note added.');
        }
        $thread->messages()->create(['user_id' => $request->user()->id, 'from_staff' => true, 'body' => $data['body']]);
        $thread->forceFill(['last_message_at' => now(), 'status' => $data['status'] ?? 'pending', 'assigned_to' => $thread->assigned_to ?? $request->user()->id])->save();
        Audit::log('support.replied', $thread, ['status' => $thread->status]);

        return back()->with('success', 'Reply sent.');
    }

    public function update(Request $request, SupportThread $thread): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:open,pending,resolved'],
            'assigned_to' => ['sometimes', 'nullable', 'exists:users,id'],
            'priority' => ['sometimes', 'in:low,normal,high,urgent'],
        ]);
        $before = $thread->only(array_keys($data));
        $thread->forceFill($data)->save();
        Audit::log('support.updated', $thread, ['before' => $before, 'after' => $data]);

        return back()->with('success', 'Thread updated.');
    }
}
