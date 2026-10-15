import { ConfirmDialog, Dl, ReadOnlyNote, useAction, useCan } from '@/components/admin/kit';
import { RefundDialog, type RefundablePayment } from '@/components/admin/refund-dialog';
import { Button } from '@/components/ui/button';
import { Field, Input } from '@/components/ui/form';
import { Dialog, DialogContent } from '@/components/ui/overlay';
import { Badge, Card, CardBody, CardHeader, EmptyState, StatusBadge, Timeline } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { date, dateTime, money, num, titleCase } from '@/lib/utils';
import { Link, useForm } from '@inertiajs/react';
import { Check, FileText, Truck, Upload, X } from 'lucide-react';
import { useState } from 'react';

interface Artwork {
    id: number;
    side: string;
    original_name: string;
    mime: string;
    size_bytes: number;
    width_px: number | null;
    height_px: number | null;
    status: string;
    review_note: string | null;
    reviewed_at: string | null;
}
interface Order {
    id: number;
    reference: string;
    status: string;
    gsm: number | null;
    finish: string | null;
    sides: number;
    orientation: string;
    quantity: number | null;
    subtotal_cents: number;
    gst_cents: number;
    total_cents: number;
    delivery_name: string | null;
    delivery_address: string | null;
    delivery_phone: string | null;
    earliest_delivery: string | null;
    vendor: string | null;
    tracking_number: string | null;
    proof_path: string | null;
    right_to_print: boolean;
    submitted_at: string | null;
    paid_at: string | null;
    completed_at: string | null;
    created_at: string;
    pricing: { lines?: { label: string; amount_cents: number }[] } | null;
    business: { id: number; name: string; email: string; status: string };
    product: { name: string; size_label: string; width_mm: number; height_mm: number; bleed_mm: number } | null;
    artworks: Artwork[];
    payments: (RefundablePayment & { status: string; gateway: string; card_brand: string | null; card_last4: string | null; paid_at: string | null })[];
    invoice: { id: number; number: string } | null;
    campaign: { id: number; reference: string; name: string } | null;
}
interface Log {
    id: number;
    action: string;
    changes: Record<string, unknown> | null;
    created_at: string;
    user: { name: string } | null;
}

const STEPS = [
    { key: 'submitted', label: 'Submitted' },
    { key: 'awaiting_artwork_approval', label: 'Artwork review' },
    { key: 'in_printing', label: 'In printing' },
    { key: 'shipped', label: 'Shipped' },
    { key: 'completed', label: 'Completed' },
];

export default function PrintOrderShow({ order, next, history }: { order: Order; next: string[]; history: Log[] }) {
    const can = useCan();
    const canManage = can('print_orders.manage');
    const [review, setReview] = useState<{ artwork: Artwork; decision: 'approved' | 'rejected' } | null>(null);
    const [move, setMove] = useState<string | null>(null);
    const [refund, setRefund] = useState<RefundablePayment | null>(null);
    const act = useAction();
    const current = STEPS.findIndex((s) => s.key === order.status);
    const statusDates: Record<string, string> = {};
    history.filter((h) => h.action === 'print_order.status_changed').forEach((h) => (statusDates[String(h.changes?.to)] = dateTime(h.created_at)));
    if (order.submitted_at) statusDates.submitted = dateTime(order.submitted_at);

    const actions = canManage && next.filter((s) => s !== 'cancelled').length > 0 && (
        <>
            {next
                .filter((s) => s !== 'cancelled')
                .map((s) => (
                    <Button key={s} data-testid={`print-status-${s}`} onClick={() => setMove(s)}>
                        {s === 'shipped' ? <Truck /> : <Check />} Mark {titleCase(s).toLowerCase()}
                    </Button>
                ))}
            {next.includes('cancelled') && (
                <Button variant="danger-soft" onClick={() => setMove('cancelled')}>
                    <X /> Cancel order
                </Button>
            )}
        </>
    );

    return (
        <AdminLayout title={`Print order ${order.reference}`} actions={actions || undefined}>
            <p className="-mt-4 mb-6 flex flex-wrap items-center gap-2 text-sm text-muted">
                <Link href="/admin/print-orders" className="hover:underline">
                    Print orders
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
                        <CardHeader title="Artwork review" description={order.product ? `${order.product.size_label} · ${order.product.width_mm}×${order.product.height_mm} mm + ${order.product.bleed_mm} mm bleed` : undefined} />
                        <CardBody>
                            {order.artworks.length === 0 ? (
                                <EmptyState icon={FileText} title="No artwork uploaded" description="The business hasn't uploaded artwork for this order yet." />
                            ) : (
                                <div className="grid gap-4 md:grid-cols-2">
                                    {order.artworks.map((a) => {
                                        const url = `/admin/print-orders/${order.id}/artworks/${a.id}/file`;
                                        return (
                                            <div key={a.id} className="overflow-hidden rounded-[12px] border border-border">
                                                <a href={url} target="_blank" rel="noreferrer" className="block aspect-[4/3] bg-surface-2">
                                                    {a.mime.startsWith('image/') ? (
                                                        <img src={url} alt={`${a.side} artwork preview`} className="size-full object-contain" loading="lazy" />
                                                    ) : (
                                                        <span className="grid size-full place-items-center text-sm text-muted">
                                                            <span className="flex flex-col items-center gap-2">
                                                                <FileText className="size-8" aria-hidden /> Open PDF
                                                            </span>
                                                        </span>
                                                    )}
                                                </a>
                                                <div className="space-y-2 p-4 text-sm">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <p className="font-medium capitalize">{a.side}</p>
                                                        <StatusBadge status={a.status} />
                                                    </div>
                                                    <p className="truncate text-muted" title={a.original_name}>
                                                        {a.original_name} · {(a.size_bytes / 1048576).toFixed(1)} MB{a.width_px ? ` · ${a.width_px}×${a.height_px}px` : ''}
                                                    </p>
                                                    {a.review_note && <p className="rounded-[8px] bg-surface-2 px-2 py-1.5 text-muted">“{a.review_note}”</p>}
                                                    {canManage && ['submitted', 'awaiting_artwork_approval'].includes(order.status) && (
                                                        <div className="flex gap-2 pt-1">
                                                            <Button size="sm" data-testid="approve-artwork" disabled={a.status === 'approved'} onClick={() => setReview({ artwork: a, decision: 'approved' })}>
                                                                <Check /> Approve
                                                            </Button>
                                                            <Button size="sm" variant="danger-soft" disabled={a.status === 'rejected'} onClick={() => setReview({ artwork: a, decision: 'rejected' })}>
                                                                <X /> Reject
                                                            </Button>
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </CardBody>
                    </Card>

                    <Card>
                        <CardHeader title="Order" />
                        <CardBody>
                            <Dl
                                items={[
                                    ['Product', order.product?.name],
                                    ['Spec', `${order.gsm ?? '—'}gsm · ${titleCase(order.finish ?? 'matt')} · ${order.sides === 2 ? 'Double' : 'Single'}-sided · ${titleCase(order.orientation)}`],
                                    ['Quantity', num(order.quantity)],
                                    ['Deliver to', [order.delivery_name, order.delivery_address, order.delivery_phone].filter(Boolean).join(' · ') || null],
                                    ['Earliest delivery', order.earliest_delivery ? date(order.earliest_delivery) : null],
                                    ['Right to print', order.right_to_print ? 'Confirmed' : 'Not confirmed'],
                                    ['Linked campaign', order.campaign ? <Link href={`/admin/campaigns/${order.campaign.id}`} className="text-primary hover:underline">{order.campaign.reference} · {order.campaign.name}</Link> : null],
                                    ['Invoice', order.invoice ? <a href={`/admin/invoices/${order.invoice.id}/pdf`} target="_blank" rel="noreferrer" className="text-primary hover:underline">{order.invoice.number}</a> : null],
                                ]}
                            />
                            {order.pricing?.lines && (
                                <div className="mt-5 border-t border-border pt-4 text-sm">
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
                                                <Button size="sm" variant="secondary" onClick={() => setRefund(p)}>
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
                            <Timeline steps={STEPS} current={order.status === 'cancelled' ? -1 : current} cancelled={order.status === 'cancelled'} dates={statusDates} />
                        </CardBody>
                    </Card>
                    <VendorCard order={order} canManage={canManage} />
                    <ProofCard order={order} canManage={canManage} />
                    <Card>
                        <CardHeader title="History" />
                        {history.length === 0 ? (
                            <CardBody><p className="text-sm text-muted">No staff actions yet.</p></CardBody>
                        ) : (
                            <ul className="divide-y divide-border text-sm">
                                {history.map((h) => (
                                    <li key={h.id} className="px-5 py-2.5">
                                        <p className="font-medium">{titleCase(h.action.split('.').pop() ?? h.action)}{h.changes?.to ? ` → ${titleCase(String(h.changes.to))}` : ''}</p>
                                        <p className="text-xs text-muted">{h.user?.name ?? 'System'} · {dateTime(h.created_at)}</p>
                                        {typeof h.changes?.note === 'string' && h.changes.note && <p className="mt-1 text-xs text-muted">“{h.changes.note}”</p>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
            </div>

            <ConfirmDialog
                open={review !== null}
                onOpenChange={(o) => !o && setReview(null)}
                title={review?.decision === 'approved' ? `Approve ${review.artwork.side} artwork?` : `Reject ${review?.artwork.side} artwork?`}
                description={review?.decision === 'approved' ? 'When every side is approved, the order moves to printing.' : 'The business will be asked to upload new artwork.'}
                confirmLabel={review?.decision === 'approved' ? 'Approve artwork' : 'Reject artwork'}
                tone={review?.decision === 'rejected' ? 'danger' : 'primary'}
                note={{ label: review?.decision === 'rejected' ? 'What needs fixing?' : 'Note', required: review?.decision === 'rejected' }}
                processing={act.processing}
                error={act.error}
                testId="approve-artwork"
                onConfirm={(note) => review && act.run('post', `/admin/print-orders/${order.id}/artworks/${review.artwork.id}/review`, { decision: review.decision, note }, () => setReview(null))}
            />
            {move === 'shipped' ? (
                <ShipDialog order={order} onClose={() => setMove(null)} />
            ) : (
                <ConfirmDialog
                    open={move !== null}
                    onOpenChange={(o) => !o && setMove(null)}
                    title={move === 'cancelled' ? 'Cancel this order?' : `Move to ${titleCase(move ?? '').toLowerCase()}?`}
                    description={move === 'cancelled' ? 'Cancelling does not refund automatically — issue a refund from Payments if needed.' : undefined}
                    confirmLabel={move === 'cancelled' ? 'Cancel order' : 'Confirm'}
                    tone={move === 'cancelled' ? 'danger' : 'primary'}
                    note={{ label: 'Note', required: move === 'cancelled' }}
                    processing={act.processing}
                    error={act.error}
                    onConfirm={(note) => act.run('post', `/admin/print-orders/${order.id}/status`, { status: move, note }, () => setMove(null))}
                />
            )}
            <RefundDialog payment={refund} open={refund !== null} onOpenChange={(o) => !o && setRefund(null)} />
        </AdminLayout>
    );
}

function ShipDialog({ order, onClose }: { order: Order; onClose: () => void }) {
    const form = useForm({ status: 'shipped', vendor: order.vendor ?? '', tracking_number: order.tracking_number ?? '', note: '' });
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent title="Mark as shipped" description="The business sees the vendor and tracking number on their order.">
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/admin/print-orders/${order.id}/status`, { preserveScroll: true, onSuccess: onClose });
                    }}
                >
                    <Field label="Print vendor / courier" error={form.errors.vendor}>
                        <Input value={form.data.vendor} onChange={(e) => form.setData('vendor', e.target.value)} placeholder="e.g. Sydney Print Co. via StarTrack" />
                    </Field>
                    <Field label="Tracking number" error={form.errors.tracking_number}>
                        <Input value={form.data.tracking_number} onChange={(e) => form.setData('tracking_number', e.target.value)} />
                    </Field>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={form.processing} data-testid="print-ship-confirm">
                            <Truck /> Mark shipped
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function VendorCard({ order, canManage }: { order: Order; canManage: boolean }) {
    const form = useForm({ vendor: order.vendor ?? '', tracking_number: order.tracking_number ?? '' });
    return (
        <Card>
            <CardHeader title="Vendor & shipping" />
            <CardBody>
                {canManage ? (
                    <form
                        className="space-y-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`/admin/print-orders/${order.id}/vendor`, { preserveScroll: true });
                        }}
                    >
                        <Field label="Vendor" error={form.errors.vendor}>
                            <Input value={form.data.vendor} onChange={(e) => form.setData('vendor', e.target.value)} placeholder="Assign a print vendor" />
                        </Field>
                        <Field label="Tracking number" optional error={form.errors.tracking_number}>
                            <Input value={form.data.tracking_number} onChange={(e) => form.setData('tracking_number', e.target.value)} />
                        </Field>
                        <Button type="submit" size="sm" variant="secondary" loading={form.processing} disabled={!form.isDirty}>
                            Save vendor
                        </Button>
                    </form>
                ) : (
                    <Dl items={[['Vendor', order.vendor], ['Tracking', order.tracking_number]]} />
                )}
            </CardBody>
        </Card>
    );
}

function ProofCard({ order, canManage }: { order: Order; canManage: boolean }) {
    const form = useForm<{ proof: File | null }>({ proof: null });
    return (
        <Card>
            <CardHeader title="Proof" description="Digital proof sent to the business" />
            <CardBody className="space-y-3">
                {order.proof_path ? (
                    <a href={`/admin/print-orders/${order.id}/proof`} target="_blank" rel="noreferrer" className="flex items-center gap-2 text-sm text-primary hover:underline">
                        <FileText className="size-4" /> View current proof
                    </a>
                ) : (
                    <Badge>No proof uploaded</Badge>
                )}
                {canManage && (
                    <form
                        className="space-y-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`/admin/print-orders/${order.id}/proof`, { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() });
                        }}
                    >
                        <Field label="Upload proof (PDF/PNG/JPG, max 20 MB)" error={form.errors.proof}>
                            <Input type="file" accept="application/pdf,image/png,image/jpeg" onChange={(e) => form.setData('proof', e.target.files?.[0] ?? null)} />
                        </Field>
                        <Button type="submit" size="sm" variant="secondary" loading={form.processing} disabled={!form.data.proof}>
                            <Upload /> Upload
                        </Button>
                    </form>
                )}
            </CardBody>
        </Card>
    );
}

