import { ReadOnlyNote } from '@/components/admin/kit';
import { Button } from '@/components/ui/button';
import { Field, Label, Select, Switch, Textarea } from '@/components/ui/form';
import { Card, CardBody, CardHeader, StatusBadge } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { cn, dateTime, titleCase } from '@/lib/utils';
import { Link, router, useForm } from '@inertiajs/react';
import { Lock, Send } from 'lucide-react';

interface Props {
    thread: {
        id: number;
        subject: string;
        status: string;
        priority: string;
        channel: string;
        assigned_to: number | null;
        created_at: string;
        business: { id: number; name: string; email: string } | null;
        user: { name: string; email: string } | null;
        messages: { id: number; body: string; from_staff: boolean; created_at: string; user: { name: string } | null }[];
    };
    related: { type: string; id: number; reference: string | null } | null;
    staff: { id: number; name: string }[];
    notes: { id: number; changes: { note?: string } | null; created_at: string; user: { name: string } | null }[];
    can_reply: boolean;
}

export default function SupportShow({ thread, related, staff, notes, can_reply }: Props) {
    const form = useForm({ body: '', internal: false, status: 'pending' });
    const update = (data: Record<string, unknown>) => router.patch(`/admin/support/${thread.id}`, data as never, { preserveScroll: true });
    const timeline = [
        ...thread.messages.map((m) => ({ kind: 'msg' as const, at: m.created_at, m })),
        ...notes.map((n) => ({ kind: 'note' as const, at: n.created_at, n })),
    ].sort((a, b) => a.at.localeCompare(b.at));
    return (
        <AdminLayout title={thread.subject}>
            <p className="-mt-4 mb-6 flex flex-wrap items-center gap-2 text-sm text-muted">
                <Link href="/admin/support" className="hover:underline">Support</Link> / #{thread.id} · <StatusBadge status={thread.status} />
            </p>
            <ReadOnlyNote show={!can_reply} />
            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
                <div className="space-y-4">
                    <ol className="space-y-3" aria-label="Conversation">
                        {timeline.map((t) =>
                            t.kind === 'msg' ? (
                                <li key={`m${t.m.id}`} className={cn('max-w-[85%] rounded-[14px] border px-4 py-3', t.m.from_staff ? 'ml-auto border-transparent bg-ink-900 text-white dark:bg-surface-2 dark:text-fg' : 'border-border bg-surface')}>
                                    <p className="text-sm whitespace-pre-wrap">{t.m.body}</p>
                                    <p className={cn('mt-1.5 text-xs', t.m.from_staff ? 'text-white/70 dark:text-muted' : 'text-muted')}>
                                        {t.m.user?.name ?? 'Customer'} {t.m.from_staff && '(staff)'} · {dateTime(t.m.created_at)}
                                    </p>
                                </li>
                            ) : (
                                <li key={`n${t.n.id}`} className="mx-auto max-w-[85%] rounded-[14px] border border-dashed border-warning/50 bg-warning-soft px-4 py-3">
                                    <p className="flex items-center gap-1.5 text-xs font-semibold text-warning"><Lock className="size-3" /> Internal note</p>
                                    <p className="mt-1 text-sm whitespace-pre-wrap">{t.n.changes?.note}</p>
                                    <p className="mt-1.5 text-xs text-muted">{t.n.user?.name} · {dateTime(t.n.created_at)}</p>
                                </li>
                            ),
                        )}
                    </ol>
                    {can_reply && (
                        <Card>
                            <CardBody>
                                <form
                                    className="space-y-3"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        form.post(`/admin/support/${thread.id}/reply`, { preserveScroll: true, onSuccess: () => form.reset('body') });
                                    }}
                                >
                                    <Field label={form.data.internal ? 'Internal note (staff only)' : 'Reply to customer'} error={form.errors.body}>
                                        <Textarea rows={4} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} placeholder={form.data.internal ? 'Only staff can see this' : 'Write a reply…'} />
                                    </Field>
                                    <div className="flex flex-wrap items-center gap-3">
                                        <label className="flex items-center gap-2 text-sm">
                                            <Switch checked={form.data.internal} onCheckedChange={(c) => form.setData('internal', c)} /> Internal note
                                        </label>
                                        {!form.data.internal && (
                                            <div className="flex items-center gap-2">
                                                <Label htmlFor="after-status" className="text-sm">Then set status</Label>
                                                <Select id="after-status" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)} className="h-9 w-auto">
                                                    <option value="pending">Pending (awaiting customer)</option>
                                                    <option value="open">Open</option>
                                                    <option value="resolved">Resolved</option>
                                                </Select>
                                            </div>
                                        )}
                                        <Button type="submit" className="ml-auto" loading={form.processing} disabled={form.data.body.trim().length < 2}>
                                            {form.data.internal ? <Lock /> : <Send />} {form.data.internal ? 'Add note' : 'Send reply'}
                                        </Button>
                                    </div>
                                </form>
                            </CardBody>
                        </Card>
                    )}
                </div>
                <div className="space-y-6">
                    <Card>
                        <CardHeader title="Thread" />
                        <CardBody className="space-y-4 text-sm">
                            <Field label="Status">
                                <Select value={thread.status} disabled={!can_reply} onChange={(e) => update({ status: e.target.value })}>
                                    {['open', 'pending', 'resolved'].map((s) => <option key={s} value={s}>{titleCase(s)}</option>)}
                                </Select>
                            </Field>
                            <Field label="Assignee">
                                <Select value={thread.assigned_to ?? ''} disabled={!can_reply} onChange={(e) => update({ assigned_to: e.target.value ? Number(e.target.value) : null })}>
                                    <option value="">Unassigned</option>
                                    {staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                </Select>
                            </Field>
                            <Field label="Priority">
                                <Select value={thread.priority} disabled={!can_reply} onChange={(e) => update({ priority: e.target.value })}>
                                    {['low', 'normal', 'high', 'urgent'].map((s) => <option key={s} value={s}>{titleCase(s)}</option>)}
                                </Select>
                            </Field>
                        </CardBody>
                    </Card>
                    <Card>
                        <CardHeader title="Customer" />
                        <CardBody className="space-y-1 text-sm">
                            {thread.business && <Link href={`/admin/businesses/${thread.business.id}`} className="font-medium hover:underline">{thread.business.name}</Link>}
                            {thread.user && <p className="text-muted">{thread.user.name} · <a className="hover:underline" href={`mailto:${thread.user.email}`}>{thread.user.email}</a></p>}
                            <p className="text-muted">Channel: {thread.channel === 'job' ? 'Job messages' : 'Support'}</p>
                            {related && (
                                <p>
                                    Related:{' '}
                                    <Link className="text-primary hover:underline" href={related.type === 'Campaign' ? `/admin/campaigns/${related.id}` : related.type === 'PrintOrder' ? `/admin/print-orders/${related.id}` : '#'}>
                                        {related.reference ?? `${related.type} #${related.id}`}
                                    </Link>
                                </p>
                            )}
                        </CardBody>
                    </Card>
                </div>
            </div>
        </AdminLayout>
    );
}
