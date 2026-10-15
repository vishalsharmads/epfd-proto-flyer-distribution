import { AssetRow, DesignArt, fmtSize } from '@/components/app/design-bits';
import { MessageThread } from '@/components/app/misc';
import type { DesignAssetT, DesignCatalogue, DesignOrderT, InvoiceT, ThreadT } from '@/components/app/types';
import { PriceBreakdown } from '@/components/app/wizard';
import { Button } from '@/components/ui/button';
import { Field, Textarea } from '@/components/ui/form';
import { Dialog, DialogClose, DialogContent } from '@/components/ui/overlay';
import { Alert, Badge, Card, CardBody, CardHeader, EmptyState, PageHeader, StatusBadge, Timeline } from '@/components/ui/surface';
import AppLayout from '@/layouts/app-layout';
import { cn, date, dateTime, money } from '@/lib/utils';
import { Link, router, useForm } from '@inertiajs/react';
import { Check, Download, FileText, ImageIcon, MessageSquareDiff, Printer, Truck } from 'lucide-react';
import { useState } from 'react';

interface PrintInfo {
    product: { id: number; name: string; distributable: boolean } | null;
    has_file: boolean;
    order: { id: number; reference: string; status: string; url: string } | null;
}

export default function DesignJob({ order: o, invoice, thread, catalogue, print, timeline }: { order: DesignOrderT; invoice: InvoiceT | null; thread: ThreadT | null; catalogue: DesignCatalogue; print: PrintInfo; timeline: { cancelled: boolean; dates: Record<string, string | null> } }) {
    const [approve, setApprove] = useState(false);
    const [changes, setChanges] = useState(false);
    const [preview, setPreview] = useState<DesignAssetT | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const latest = o.proofs[0] ?? null;
    const left = o.revisions_unlimited ? null : Math.max(0, o.revisions_included - o.revisions_used);

    const steps = [
        { key: 'submitted', label: 'Submitted' },
        { key: 'in_design', label: 'In design' },
        { key: 'proof_ready', label: latest ? `Proof ready (v${latest.version})` : 'Proof ready' },
        ...(o.status === 'changes_requested' ? [{ key: 'changes_requested', label: `Changes requested (revision ${o.revisions_used})` }] : []),
        { key: 'approved', label: 'Approved' },
        { key: 'completed', label: 'Completed' },
    ];
    const current = o.status === 'cancelled' ? -1 : Math.max(0, steps.findIndex((s) => s.key === o.status));
    const dates = Object.fromEntries(Object.entries(timeline.dates).map(([k, v]) => [k, v ? dateTime(v) : null]));

    const printIt = (alsoDistribute: boolean) => router.post(`/app/design/${o.id}/print`, { also_distribute: alsoDistribute }, { onStart: () => setBusy(alsoDistribute ? 'dist' : 'print'), onFinish: () => setBusy(null) });

    return (
        <AppLayout title={o.name}>
            <PageHeader
                eyebrow={<Link href="/app/jobs" className="hover:underline">My Jobs</Link>}
                title={o.name}
                description={<span className="flex flex-wrap items-center gap-2">{o.reference} · Submitted {date(o.submitted_at)} <StatusBadge status={o.status} /></span>}
                actions={
                    invoice && (
                        <Button asChild variant="secondary">
                            <a href={invoice.pdf_url}><FileText /> Invoice PDF</a>
                        </Button>
                    )
                }
            />

            {o.status === 'proof_ready' && latest && (
                <Alert
                    tone="info"
                    className="mb-6"
                    title={`Proof v${latest.version} is ready for your review`}
                    action={
                        <div className="flex flex-wrap gap-2">
                            <Button size="sm" variant="secondary" onClick={() => setChanges(true)} data-testid="request-changes">
                                <MessageSquareDiff /> Request changes
                            </Button>
                            <Button size="sm" onClick={() => setApprove(true)} data-testid="approve-design">
                                <Check /> Approve design
                            </Button>
                        </div>
                    }
                >
                    {left === null ? 'Your package includes unlimited revisions.' : left > 0 ? `${left} revision${left === 1 ? '' : 's'} left in your package.` : `You've used your included revisions — further changes are ${money(catalogue.extra_revision_cents)} + GST each.`}
                </Alert>
            )}
            {o.status === 'changes_requested' && <Alert tone="warning" className="mb-6" title="Changes requested">Our designer is working on your changes and will send a new proof.</Alert>}
            {['submitted', 'in_design'].includes(o.status) && <Alert tone="info" className="mb-6" title="Your brief is with our studio">We'll email you as soon as the first proof is ready{o.pricing?.meta.turnaround_days ? ` (usually within ${o.pricing.meta.turnaround_days} business days)` : ''}.</Alert>}
            {o.extra_revisions > 0 && (
                <Alert tone="warning" className="mb-6" title={`${o.extra_revisions} extra revision${o.extra_revisions === 1 ? '' : 's'}`}>
                    Revisions beyond your package will be invoiced separately at {money(catalogue.extra_revision_cents)} + GST each.
                </Alert>
            )}

            <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
                <div className="space-y-6">
                    {['approved', 'completed'].includes(o.status) && (
                        <Card>
                            <CardHeader title="Print your design" description="We'll create a print order with your approved design attached as artwork." />
                            <CardBody>
                                {print.order ? (
                                    <p className="flex flex-wrap items-center gap-3 text-sm">
                                        <Printer className="size-4 text-muted" aria-hidden /> Print order <Link href={print.order.url} className="font-medium text-primary hover:underline">{print.order.reference}</Link> <StatusBadge status={print.order.status} />
                                    </p>
                                ) : !print.product ? (
                                    <p className="text-sm text-muted">This design isn't available in our online print store yet — send us a message and we'll quote the printing.</p>
                                ) : !print.has_file ? (
                                    <p className="text-sm text-muted">Your print-ready file is being prepared. You'll be able to print as soon as it's uploaded.</p>
                                ) : (
                                    <div className="flex flex-wrap gap-2">
                                        <Button onClick={() => printIt(false)} loading={busy === 'print'} disabled={busy !== null} data-testid="print-design">
                                            <Printer /> Print this design
                                        </Button>
                                        {print.product.distributable && (
                                            <Button variant="secondary" onClick={() => printIt(true)} loading={busy === 'dist'} disabled={busy !== null} data-testid="print-distribute-design">
                                                <Truck /> Print + distribute
                                            </Button>
                                        )}
                                        <p className="w-full text-xs text-muted">Printed as {print.product.name}. You can change paper and quantity in the next step.</p>
                                    </div>
                                )}
                            </CardBody>
                        </Card>
                    )}

                    <Card>
                        <CardHeader title="Proofs" description={o.proofs.length ? 'Newest first. Click a proof to view it larger.' : undefined} />
                        <CardBody>
                            {o.proofs.length === 0 ? (
                                <EmptyState icon={ImageIcon} title="No proofs yet" description="Your designer's proofs will appear here — we'll email you when each one is ready." className="py-8" />
                            ) : (
                                <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" aria-label="Proofs">
                                    {o.proofs.map((p, i) => (
                                        <li key={p.id} className="overflow-hidden rounded-[12px] border border-border" data-testid={`proof-v${p.version}`}>
                                            <button type="button" onClick={() => setPreview(p)} className="block aspect-[4/3] w-full bg-surface-2 focus-visible:outline-2" aria-label={`View proof version ${p.version}`}>
                                                {p.is_image ? <img src={p.url} alt={`Proof version ${p.version}`} className="size-full object-contain" loading="lazy" /> : <span className="grid size-full place-items-center text-sm text-muted"><span className="flex flex-col items-center gap-2"><FileText className="size-8" aria-hidden /> PDF proof</span></span>}
                                            </button>
                                            <div className="flex items-center justify-between gap-2 px-3 py-2.5 text-sm">
                                                <span className="flex items-center gap-2 font-medium">
                                                    v{p.version} {i === 0 && <Badge tone="brand">Latest</Badge>}
                                                </span>
                                                <span className="flex items-center gap-1 text-xs text-muted">
                                                    {date(p.created_at)}
                                                    <Button asChild variant="ghost" size="icon-sm" aria-label={`Download proof version ${p.version}`}>
                                                        <a href={p.download_url}><Download /></a>
                                                    </Button>
                                                </span>
                                            </div>
                                            {p.note && <p className="border-t border-border px-3 py-2 text-xs text-muted">“{p.note}”</p>}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardBody>
                    </Card>

                    {o.finals.length > 0 && (
                        <Card>
                            <CardHeader title="Final files" description="Print-ready and source files for your records." />
                            <CardBody>
                                <ul className="divide-y divide-border">
                                    {o.finals.map((f) => <AssetRow key={f.id} asset={f} />)}
                                </ul>
                            </CardBody>
                        </Card>
                    )}

                    <Card>
                        <CardHeader title="Your brief" />
                        <CardBody className="space-y-5">
                            <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                                {[
                                    ['Headline', o.headline],
                                    ['Key message', o.key_message],
                                    ['Call to action', o.call_to_action],
                                    ['Contact details', o.contact_details],
                                    ['Target audience', o.target_audience],
                                    ['Brand colours', o.brand_colours],
                                    ['Style notes', o.style_notes],
                                    ['Needed by', o.due_date ? date(o.due_date) : null],
                                    ['Print size', o.size],
                                    ['Sides', o.sides === 2 ? 'Double-sided' : 'Single-sided'],
                                ]
                                    .filter(([, v]) => v)
                                    .map(([k, v]) => (
                                        <div key={k} className={cn((k === 'Key message' || k === 'Style notes') && 'sm:col-span-2')}>
                                            <dt className="text-muted">{k}</dt>
                                            <dd className="whitespace-pre-line">{v}</dd>
                                        </div>
                                    ))}
                            </dl>
                            {o.brief_files.length > 0 && (
                                <div>
                                    <h3 className="mb-1 text-sm font-medium">Reference files</h3>
                                    <ul className="divide-y divide-border">
                                        {o.brief_files.map((f) => <AssetRow key={f.id} asset={f} />)}
                                    </ul>
                                </div>
                            )}
                        </CardBody>
                    </Card>

                    <MessageThread thread={thread} action={`/app/jobs/design/${o.id}/messages`} description="Questions or extra files? Message your designer." emptyText="No messages yet. Ask your designer anything about this job." />
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader title="Status" />
                        <CardBody>
                            <Timeline steps={steps} current={current} cancelled={timeline.cancelled} dates={dates} />
                        </CardBody>
                    </Card>
                    <Card>
                        <CardHeader title="Package" />
                        <CardBody className="space-y-2 text-sm">
                            <div className="flex items-center gap-3">
                                <DesignArt type={o.design_type} className="size-11 shrink-0 rounded-[10px] [&>div]:size-7 [&>div]:rounded-[6px] [&_svg]:size-4" />
                                <p className="font-medium">{o.name}</p>
                            </div>
                            <p className="flex justify-between"><span className="text-muted">Revisions used</span><span className="tabular">{o.revisions_used} of {o.revisions_unlimited ? 'unlimited' : o.revisions_included}</span></p>
                            {o.designer && <p className="flex justify-between"><span className="text-muted">Designer</span><span>{o.designer.name}</span></p>}
                        </CardBody>
                    </Card>
                    <Card>
                        <CardHeader title="Price" />
                        <CardBody><PriceBreakdown quote={o.pricing} /></CardBody>
                    </Card>
                </div>
            </div>

            <Dialog open={approve} onOpenChange={setApprove}>
                <DialogContent title={`Approve proof v${latest?.version ?? ''}?`} description="Approving tells our studio the design is final. We'll then prepare your print-ready files.">
                    <div className="flex justify-end gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Cancel</Button>
                        </DialogClose>
                        <Button loading={busy === 'approve'} data-testid="approve-design-confirm" onClick={() => router.post(`/app/design/${o.id}/approve`, {}, { preserveScroll: true, onStart: () => setBusy('approve'), onFinish: () => setBusy(null), onSuccess: () => setApprove(false) })}>
                            <Check /> Approve design
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
            <ChangesDialog open={changes} onOpenChange={setChanges} order={o} left={left} extraCents={catalogue.extra_revision_cents} />
            <Dialog open={preview !== null} onOpenChange={(v) => !v && setPreview(null)}>
                {preview && (
                    <DialogContent wide title={`Proof v${preview.version}`} description={`${preview.original_name} · ${fmtSize(preview.size_bytes)}`}>
                        {preview.is_image ? (
                            <img src={preview.url} alt={`Proof version ${preview.version}`} className="max-h-[70dvh] w-full rounded-[8px] bg-surface-2 object-contain" />
                        ) : (
                            <a href={preview.url} target="_blank" rel="noreferrer" className="text-primary underline">Open the PDF proof</a>
                        )}
                    </DialogContent>
                )}
            </Dialog>
        </AppLayout>
    );
}

function ChangesDialog({ open, onOpenChange, order, left, extraCents }: { open: boolean; onOpenChange: (v: boolean) => void; order: DesignOrderT; left: number | null; extraCents: number }) {
    const form = useForm({ changes: '' });
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent title="Request changes" description="Tell your designer exactly what to change. Each request uses one revision.">
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/app/design/${order.id}/request-changes`, { preserveScroll: true, onSuccess: () => (form.reset(), onOpenChange(false)) });
                    }}
                >
                    <Field label="Changes" error={form.errors.changes}>
                        <Textarea data-testid="changes-text" value={form.data.changes} onChange={(e) => form.setData('changes', e.target.value)} maxLength={4000} placeholder="e.g. Make the logo bigger, change the headline colour to navy, use the second photo." />
                    </Field>
                    {left === 0 ? (
                        <Alert tone="warning">You've used the revisions in your package. This change will be invoiced as an extra revision ({money(extraCents)} + GST).</Alert>
                    ) : (
                        left !== null && <p className="text-sm text-muted">{left} revision{left === 1 ? '' : 's'} left in your package.</p>
                    )}
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button type="submit" loading={form.processing} disabled={form.data.changes.trim().length < 5} data-testid="changes-submit">Send to designer</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
