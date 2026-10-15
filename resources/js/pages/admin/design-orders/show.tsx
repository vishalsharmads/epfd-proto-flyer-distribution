import { ConfirmDialog, Dl, ReadOnlyNote, useAction, useCan } from '@/components/admin/kit';
import { RefundDialog, type RefundablePayment } from '@/components/admin/refund-dialog';
import { fmtSize } from '@/components/app/design-bits';
import type { DesignAssetT, DesignOrderT, ThreadT } from '@/components/app/types';
import { Button } from '@/components/ui/button';
import { Field, Input, Select, Textarea } from '@/components/ui/form';
import { Badge, Card, CardBody, CardHeader, EmptyState, StatusBadge, Timeline } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { cn, date, dateTime, money, titleCase } from '@/lib/utils';
import { Link, useForm } from '@inertiajs/react';
import { Check, Download, FileText, ImageIcon, MessageSquare, PenTool, Upload, X } from 'lucide-react';
import { useState } from 'react';

type Payment = RefundablePayment & { status: string; gateway: string; card_brand: string | null; card_last4: string | null; paid_at: string | null };
type Order = DesignOrderT & {
    business: { id: number; name: string; email: string; status: string; contact_name: string; mobile: string };
    user: { id: number; name: string; email: string } | null;
    payments: Payment[];
    invoice: { id: number; number: string } | null;
    print_order: { id: number; reference: string; status: string } | null;
};
interface Log {
    id: number;
    action: string;
    changes: Record<string, unknown> | null;
    created_at: string;
    user: { name: string } | null;
}

const STEPS = [
    { key: 'submitted', label: 'Submitted' },
    { key: 'in_design', label: 'In design' },
    { key: 'proof_ready', label: 'Proof ready' },
    { key: 'changes_requested', label: 'Changes requested' },
    { key: 'approved', label: 'Approved' },
    { key: 'completed', label: 'Completed' },
];

export default function DesignOrderShow({ order, next, can_proof, can_final, designers, thread, history }: { order: Order; next: string[]; can_proof: boolean; can_final: boolean; designers: { id: number; name: string }[]; thread: ThreadT | null; history: Log[] }) {
    const can = useCan();
    const canManage = can('design_orders.manage');
    const [move, setMove] = useState<string | null>(null);
    const [refund, setRefund] = useState<RefundablePayment | null>(null);
    const act = useAction();
    const current = order.status === 'cancelled' ? -1 : STEPS.findIndex((s) => s.key === order.status);
    const statusDates: Record<string, string> = {};
    history.forEach((h) => {
        const to = h.changes?.to;
        if (typeof to === 'string' && !statusDates[to]) statusDates[to] = dateTime(h.created_at);
    });
    if (order.submitted_at) statusDates.submitted = dateTime(order.submitted_at);

    const actions = canManage && (
        <>
            {next.includes('in_design') && (
                <Button data-testid="design-status-in_design" onClick={() => setMove('in_design')}>
                    <PenTool /> Mark in design
                </Button>
            )}
            {next.includes('completed') && (
                <Button data-testid="design-status-completed" disabled={order.finals.length === 0} title={order.finals.length === 0 ? 'Upload final files first' : undefined} onClick={() => setMove('completed')}>
                    <Check /> Complete order
                </Button>
            )}
            {next.includes('cancelled') && (
                <Button variant="danger-soft" onClick={() => setMove('cancelled')}>
                    <X /> Cancel order
                </Button>
            )}
        </>
    );

    return (
        <AdminLayout title={`Design order ${order.reference}`} actions={actions || undefined}>
            <p className="-mt-4 mb-6 flex flex-wrap items-center gap-2 text-sm text-muted">
                <Link href="/admin/design-orders" className="hover:underline">
                    Design orders
                </Link>
                / {order.reference} · <StatusBadge status={order.status} /> ·{' '}
                <Link href={`/admin/businesses/${order.business.id}`} className="hover:underline">
                    {order.business.name}
                </Link>
            </p>
            <ReadOnlyNote show={!canManage} />

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
                <div className="min-w-0 space-y-6">
                    <Card>
                        <CardHeader title="Brief" description={`${order.name}${order.size ? ` · ${order.size}` : ''} · ${order.sides === 2 ? 'double' : 'single'}-sided`} />
                        <CardBody className="space-y-5">
                            <Dl
                                items={[
                                    ['Headline', order.headline],
                                    ['Key message', order.key_message && <span className="whitespace-pre-line">{order.key_message}</span>],
                                    ['Call to action', order.call_to_action],
                                    ['Contact details', order.contact_details && <span className="whitespace-pre-line">{order.contact_details}</span>],
                                    ['Target audience', order.target_audience],
                                    ['Brand colours', order.brand_colours],
                                    ['Style notes', order.style_notes && <span className="whitespace-pre-line">{order.style_notes}</span>],
                                    ['Needed by', order.due_date ? date(order.due_date) : null],
                                    ['Add-ons', order.addons.length ? order.addons.map(titleCase).join(', ') : null],
                                ]}
                            />
                            <div>
                                <h3 className="mb-2 text-sm font-medium">Uploaded by the business</h3>
                                {order.brief_files.length === 0 ? (
                                    <p className="text-sm text-muted">No reference files.</p>
                                ) : (
                                    <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                        {order.brief_files.map((f) => (
                                            <FileTile key={f.id} asset={f} />
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </CardBody>
                    </Card>

                    <Card>
                        <CardHeader title="Proofs" description="Each upload is a new version and emails the business." />
                        <CardBody className="space-y-5">
                            {order.proofs.length === 0 ? (
                                <EmptyState icon={ImageIcon} title="No proofs yet" description="Upload the first proof when the design is ready for the business to review." className="py-8" />
                            ) : (
                                <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                    {order.proofs.map((p, i) => (
                                        <FileTile key={p.id} asset={p} label={<>v{p.version} {i === 0 && <Badge tone="brand">Latest</Badge>}</>} />
                                    ))}
                                </ul>
                            )}
                            {canManage && can_proof && <ProofUpload orderId={order.id} nextVersion={(order.proofs[0]?.version ?? 0) + 1} />}
                        </CardBody>
                    </Card>

                    <Card>
                        <CardHeader title="Final files" description="Print-ready PDF/PNG/JPG (used by “Print this design”) and source files." />
                        <CardBody className="space-y-4">
                            {order.finals.length === 0 ? (
                                <p className="text-sm text-muted">{can_final ? 'No final files uploaded yet.' : 'Final files can be uploaded once the business approves a proof.'}</p>
                            ) : (
                                <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    {order.finals.map((f) => (
                                        <FileTile key={f.id} asset={f} />
                                    ))}
                                </ul>
                            )}
                            {canManage && can_final && <FinalUpload orderId={order.id} />}
                        </CardBody>
                    </Card>

                    <AdminMessages orderId={order.id} thread={thread} canManage={canManage} />

                    <Card>
                        <CardHeader title="Payments" />
                        {order.payments.length === 0 ? (
                            <CardBody>
                                <p className="text-sm text-muted">No payments recorded.</p>
                            </CardBody>
                        ) : (
                            <ul className="divide-y divide-border">
                                {order.payments.map((p) => (
                                    <li key={p.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm sm:px-6">
                                        <div>
                                            <p className="font-medium">
                                                {money(p.amount_cents)} {p.card_last4 ? `· ${p.card_brand} •••• ${p.card_last4}` : ''}
                                                {p.credits_applied_cents > 0 && <span className="text-muted"> + {money(p.credits_applied_cents)} credit</span>}
                                            </p>
                                            <p className="text-xs text-muted">
                                                {dateTime(p.paid_at)} · refunded {money(p.refunded_cents)}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <StatusBadge status={p.status} />
                                            {can('payments.refund') && p.refunded_cents < p.amount_cents + p.credits_applied_cents && ['succeeded', 'partially_refunded'].includes(p.status) && (
                                                <Button size="sm" variant="secondary" onClick={() => setRefund(p)} data-testid="design-refund">
                                                    Refund
                                                </Button>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader title="Timeline" />
                        <CardBody>
                            <Timeline steps={STEPS} current={current} cancelled={order.status === 'cancelled'} dates={statusDates} />
                        </CardBody>
                    </Card>
                    <DesignerCard order={order} designers={designers} canManage={canManage} />
                    <Card>
                        <CardHeader title="Order" />
                        <CardBody className="space-y-4">
                            <Dl
                                items={[
                                    ['Customer', order.user ? `${order.user.name} · ${order.user.email}` : order.business.email],
                                    ['Revisions', `${order.revisions_used} used of ${order.revisions_unlimited ? 'unlimited' : order.revisions_included}`],
                                    ['Extra revisions', order.extra_revisions > 0 ? <span className="font-medium text-warning">{order.extra_revisions} to invoice</span> : null],
                                    ['Print order', order.print_order ? <Link href={`/admin/print-orders/${order.print_order.id}`} className="text-primary hover:underline">{order.print_order.reference} · {titleCase(order.print_order.status)}</Link> : null],
                                    ['Invoice', order.invoice ? <a href={`/admin/invoices/${order.invoice.id}/pdf`} target="_blank" rel="noreferrer" className="text-primary hover:underline">{order.invoice.number}</a> : null],
                                ]}
                            />
                            {order.pricing?.lines && (
                                <div className="border-t border-border pt-3 text-sm">
                                    {order.pricing.lines.map((l, i) => (
                                        <div key={i} className="flex justify-between gap-4 py-1">
                                            <span className="text-muted">{l.label}</span>
                                            <span className="tabular">{money(l.amount_cents)}</span>
                                        </div>
                                    ))}
                                    <div className="flex justify-between py-1">
                                        <span className="text-muted">GST</span>
                                        <span className="tabular">{money(order.gst_cents)}</span>
                                    </div>
                                    <div className="flex justify-between py-1 font-semibold">
                                        <span>Total incl GST</span>
                                        <span className="tabular">{money(order.total_cents)}</span>
                                    </div>
                                </div>
                            )}
                        </CardBody>
                    </Card>
                    <Card>
                        <CardHeader title="History" />
                        {history.length === 0 ? (
                            <CardBody>
                                <p className="text-sm text-muted">No actions yet.</p>
                            </CardBody>
                        ) : (
                            <ul className="divide-y divide-border text-sm">
                                {history.map((h) => (
                                    <li key={h.id} className="px-5 py-2.5">
                                        <p className="font-medium">
                                            {titleCase(h.action.split('.').pop() ?? h.action)}
                                            {typeof h.changes?.to === 'string' ? ` → ${titleCase(h.changes.to)}` : ''}
                                            {typeof h.changes?.version === 'number' ? ` (v${h.changes.version})` : ''}
                                        </p>
                                        <p className="text-xs text-muted">
                                            {h.user?.name ?? 'System'} · {dateTime(h.created_at)}
                                        </p>
                                        {typeof h.changes?.note === 'string' && h.changes.note && <p className="mt-1 text-xs text-muted">“{h.changes.note}”</p>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
            </div>

            <ConfirmDialog
                open={move !== null}
                onOpenChange={(o) => !o && setMove(null)}
                title={move === 'cancelled' ? 'Cancel this design order?' : move === 'completed' ? 'Complete this design order?' : 'Mark as in design?'}
                description={move === 'cancelled' ? 'Cancelling does not refund automatically — issue a refund from Payments below.' : move === 'completed' ? 'The business keeps access to the final files and can send the design to print.' : 'Lets the business know a designer is working on it.'}
                confirmLabel={move === 'cancelled' ? 'Cancel order' : 'Confirm'}
                tone={move === 'cancelled' ? 'danger' : 'primary'}
                note={{ label: move === 'cancelled' ? 'Reason' : 'Note', required: move === 'cancelled' }}
                processing={act.processing}
                error={act.error}
                testId="design-status"
                onConfirm={(note) => act.run('post', `/admin/design-orders/${order.id}/status`, { status: move, note }, () => setMove(null))}
            />
            <RefundDialog payment={refund} open={refund !== null} onOpenChange={(o) => !o && setRefund(null)} />
        </AdminLayout>
    );
}

function FileTile({ asset, label }: { asset: DesignAssetT; label?: React.ReactNode }) {
    return (
        <li className="overflow-hidden rounded-[12px] border border-border">
            <a href={asset.url} target="_blank" rel="noreferrer" className="block aspect-[4/3] bg-surface-2">
                {asset.is_image ? (
                    <img src={asset.url} alt={`Preview of ${asset.original_name}`} className="size-full object-contain" loading="lazy" />
                ) : (
                    <span className="grid size-full place-items-center text-sm text-muted">
                        <span className="flex flex-col items-center gap-2">
                            <FileText className="size-8" aria-hidden /> {asset.mime === 'application/pdf' ? 'Open PDF' : 'Download'}
                        </span>
                    </span>
                )}
            </a>
            <div className="flex items-center justify-between gap-2 px-3 py-2 text-sm">
                <span className="min-w-0">
                    {label && <span className="flex items-center gap-2 font-medium">{label}</span>}
                    <span className="block truncate text-xs text-muted" title={asset.original_name}>
                        {asset.original_name} · {fmtSize(asset.size_bytes)}
                    </span>
                </span>
                <Button asChild variant="ghost" size="icon-sm" aria-label={`Download ${asset.original_name}`}>
                    <a href={asset.download_url}>
                        <Download />
                    </a>
                </Button>
            </div>
            {asset.note && <p className="border-t border-border px-3 py-2 text-xs text-muted">“{asset.note}”</p>}
        </li>
    );
}

function ProofUpload({ orderId, nextVersion }: { orderId: number; nextVersion: number }) {
    const form = useForm<{ file: File | null; note: string }>({ file: null, note: '' });
    return (
        <form
            className="grid gap-3 border-t border-border pt-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/admin/design-orders/${orderId}/proofs`, { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() });
            }}
        >
            <Field label={`Upload proof v${nextVersion} (PDF/PNG/JPG, max 20 MB)`} error={form.errors.file}>
                <Input type="file" data-testid="proof-file" accept="application/pdf,image/png,image/jpeg" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} />
            </Field>
            <Field label="Note to the business" optional error={form.errors.note}>
                <Input value={form.data.note} maxLength={1000} onChange={(e) => form.setData('note', e.target.value)} placeholder="e.g. Two layout options" />
            </Field>
            <Button type="submit" loading={form.processing} disabled={!form.data.file} data-testid="proof-upload">
                <Upload /> Upload proof
            </Button>
        </form>
    );
}

function FinalUpload({ orderId }: { orderId: number }) {
    const form = useForm<{ files: File[]; note: string }>({ files: [], note: '' });
    return (
        <form
            className="grid gap-3 border-t border-border pt-4 sm:grid-cols-[1fr_auto] sm:items-end"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/admin/design-orders/${orderId}/finals`, { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() });
            }}
        >
            <Field label="Upload final files (PDF, PNG, JPG, SVG, ZIP, AI, PSD…)" error={form.errors.files ?? Object.entries(form.errors).find(([k]) => k.startsWith('files.'))?.[1]}>
                <Input type="file" multiple data-testid="final-files" onChange={(e) => form.setData('files', Array.from(e.target.files ?? []))} />
            </Field>
            <Button type="submit" variant="secondary" loading={form.processing} disabled={form.data.files.length === 0}>
                <Upload /> Upload
            </Button>
        </form>
    );
}

function DesignerCard({ order, designers, canManage }: { order: Order; designers: { id: number; name: string }[]; canManage: boolean }) {
    const form = useForm({ designer_id: order.designer?.id ? String(order.designer.id) : '' });
    return (
        <Card>
            <CardHeader title="Designer" />
            <CardBody>
                {canManage ? (
                    <form
                        className="space-y-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.transform((d) => ({ designer_id: d.designer_id ? Number(d.designer_id) : null }));
                            form.post(`/admin/design-orders/${order.id}/assign`, { preserveScroll: true });
                        }}
                    >
                        <Field label="Assigned designer" error={form.errors.designer_id}>
                            <Select data-testid="designer-select" value={form.data.designer_id} onChange={(e) => form.setData('designer_id', e.target.value)}>
                                <option value="">Unassigned</option>
                                {designers.map((d) => (
                                    <option key={d.id} value={d.id}>
                                        {d.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Button type="submit" size="sm" variant="secondary" loading={form.processing} disabled={!form.isDirty}>
                            Save designer
                        </Button>
                    </form>
                ) : (
                    <p className="text-sm">{order.designer?.name ?? <span className="text-muted">Unassigned</span>}</p>
                )}
            </CardBody>
        </Card>
    );
}

function AdminMessages({ orderId, thread, canManage }: { orderId: number; thread: ThreadT | null; canManage: boolean }) {
    const form = useForm({ body: '' });
    return (
        <Card>
            <CardHeader title="Messages" description="Shared with the business on their job page." />
            <CardBody className="space-y-4">
                {!thread || thread.messages.length === 0 ? (
                    <EmptyState icon={MessageSquare} title="No messages yet" className="py-6" />
                ) : (
                    <ol className="space-y-3" aria-label="Messages">
                        {thread.messages.map((m) => (
                            <li key={m.id} className={cn('flex', m.from_staff ? 'justify-end' : 'justify-start')}>
                                <div className={cn('max-w-[85%] rounded-[14px] px-4 py-3 text-sm', m.from_staff ? 'rounded-tr-sm bg-ink-900 text-white dark:bg-white dark:text-ink-900' : 'rounded-tl-sm border border-border bg-surface-2')}>
                                    <p className={cn('mb-1 text-xs font-semibold', m.from_staff ? 'opacity-70' : 'text-primary-hover')}>
                                        {m.from_staff ? 'EPFD team' : m.author} · <span className="font-normal">{dateTime(m.created_at)}</span>
                                    </p>
                                    <p className="whitespace-pre-line">{m.body}</p>
                                </div>
                            </li>
                        ))}
                    </ol>
                )}
                {canManage && (
                    <form
                        className="space-y-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`/admin/design-orders/${orderId}/messages`, { preserveScroll: true, onSuccess: () => form.reset() });
                        }}
                    >
                        <Field label="Reply to the business" error={form.errors.body}>
                            <Textarea value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} maxLength={4000} />
                        </Field>
                        <Button type="submit" variant="ink" size="sm" loading={form.processing} disabled={form.data.body.trim().length < 2}>
                            Send message
                        </Button>
                    </form>
                )}
            </CardBody>
        </Card>
    );
}
