<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends AdminController
{
    public function __invoke(Request $request): Response
    {
        [$from, $to] = $this->range($request->input('from'), $request->input('to'));
        $tz = config('epfd.timezone');
        $rows = AuditLog::query()->with('user:id,name,email')
            ->when($request->filled('action'), fn ($w) => $w->where('action', 'like', $request->input('action').'%'))
            ->when($request->filled('user'), fn ($w) => $w->where('user_id', $request->integer('user')))
            ->when($request->filled('subject'), fn ($w) => $w->where('auditable_type', 'like', '%'.$request->input('subject')))
            ->when($request->filled('subject_id'), fn ($w) => $w->where('auditable_id', $request->integer('subject_id')))
            ->when($from, fn ($w) => $w->where('created_at', '>=', Carbon::parse($from, $tz)->startOfDay()->utc()))
            ->when($to, fn ($w) => $w->where('created_at', '<=', Carbon::parse($to, $tz)->endOfDay()->utc()))
            ->latest('id')->paginate(25)->withQueryString()
            ->through(fn (AuditLog $a) => [...$a->toArray(), 'subject' => $a->auditable_type ? class_basename($a->auditable_type) : null]);

        return Inertia::render('admin/audit-log/index', [
            'logs' => $rows,
            'filters' => $request->only(['action', 'user', 'subject', 'subject_id', 'from', 'to']),
            'actions' => AuditLog::distinct()->orderBy('action')->pluck('action'),
            'subjects' => AuditLog::whereNotNull('auditable_type')->distinct()->pluck('auditable_type')->map(fn ($t) => class_basename($t))->unique()->values(),
            'users' => User::whereIn('id', AuditLog::whereNotNull('user_id')->distinct()->select('user_id'))->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
