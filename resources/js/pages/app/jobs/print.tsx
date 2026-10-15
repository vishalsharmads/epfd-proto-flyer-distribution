import { MessageThread, RatingCard } from '@/components/app/misc';
import { ProductArt } from '@/components/app/print-bits';
import type { InvoiceT, PrintOrderT, ThreadT } from '@/components/app/types';
import { PriceBreakdown } from '@/components/app/wizard';
import { Button } from '@/components/ui/button';
import { Alert, Card, CardBody, CardHeader, PageHeader, StatusBadge, Timeline } from '@/components/ui/surface';
import AppLayout from '@/layouts/app-layout';
import { date, dateTime, num } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { Download, FileText, Repeat, Truck } from 'lucide-react';
import { useState } from 'react';

const STEPS = [
    { key: 'submitted', label: 'Submitted' },
    { key: 'awaiting_artwork_approval', label: 'Awaiting artwork approval' },
    { key: 'in_printing', label: 'In printing' },
    { key: 'shipped', label: 'Shipped' },
    { key: 'completed', label: 'Completed' },
];

export default function PrintJob({ order: o, invoice, thread, linked_campaign, timeline }: { order: PrintOrderT; invoice: InvoiceT | null; thread: ThreadT | null; linked_campaign: { id: number; name: string; status: string; url: string } | null; timeline: { current: number; cancelled: boolean; dates: Record<string, string | null> } }) {
    const [busy, setBusy] = useState(false);
    const p = o.product!;
    const dates = Object.fromEntries(Object.entries(timeline.dates).map(([k, v]) => [k, v ? dateTime(v) : null]));
    return (
        <AppLayout title={o.name}>
            <PageHeader
                eyebrow={<Link href="/app/jobs" className="hover:underline">My Jobs</Link>}
                title={o.name}
                description={<span className="flex flex-wrap items-center gap-2">{o.reference} · Submitted {date(o.submitted_at)} <StatusBadge status={o.status} /></span>}
                actions={
                    <>
                        {invoice && (
                            <Button asChild variant="secondary">
                                <a href={invoice.pdf_url}><FileText /> Invoice PDF</a>
                            </Button>
                        )}
                        <Button variant="ink" loading={busy} onClick={() => router.post(`/app/print/${o.id}/reorder`, {}, { onStart: () => setBusy(true), onFinish: () => setBusy(false) })}>
                            <Repeat /> Re-order
                        </Button>
                    </>
                }
            />
            {o.also_distribute && (
                <Alert tone="info" className="mb-6" title="Distribution linked" action={linked_campaign && <Button asChild size="sm"><Link href={linked_campaign.url}>{linked_campaign.status === 'draft' ? 'Continue to distribution' : 'View campaign'}</Link></Button>}>
                    {linked_campaign ? <>These flyers will be letterboxed in “{linked_campaign.name}”. {linked_campaign.status === 'draft' && 'Choose your areas to book the distribution.'}</> : 'Your linked distribution campaign is being created.'}
                </Alert>
            )}
            <div className="grid items-start gap-6 lg:grid-cols-[1fr_340px]">
                <div className="space-y-6">
                    <Card>
                        <CardHeader title="Order details" />
                        <CardBody className="grid gap-6 sm:grid-cols-[120px_1fr]">
                            <ProductArt product={p} className="aspect-square rounded-[12px]" />
                            <dl className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                                {[
                                    ['Product', `${p.name} (${p.size_label})`],
                                    ['Quantity', num(o.quantity)],
                                    ['Paper', `${o.gsm}gsm ${o.finish === 'gloss' ? 'Gloss' : 'Matt'}`],
                                    ['Sides', o.sides === 2 ? 'Double-sided' : 'Single-sided'],
                                    ['Deliver to', `${o.delivery_name ?? ''}, ${o.delivery_address ?? ''}`],
                                    ['Earliest delivery', date(o.earliest_delivery)],
                                    ...(o.tracking_number ? [['Tracking', o.tracking_number]] : []),
                                ].map(([k, v]) => (
                                    <div key={k} className="col-span-2 flex justify-between gap-4 sm:col-span-1 sm:block">
                                        <dt className="text-muted">{k}</dt>
                                        <dd>{v}</dd>
                                    </div>
                                ))}
                            </dl>
                        </CardBody>
                    </Card>
                    <Card>
                        <CardHeader title="Artwork" description="Our prepress team checks every file before printing." />
                        <ul className="divide-y divide-border">
                            {o.artworks.map((a) => (
                                <li key={a.id} className="flex items-center gap-3 px-5 py-3 text-sm">
                                    <span className="w-14 font-medium capitalize">{a.side}</span>
                                    <span className="min-w-0 flex-1 truncate">{a.original_name}</span>
                                    <StatusBadge status={a.status} />
                                    <Button asChild variant="ghost" size="icon-sm" aria-label={`Download ${a.side} artwork`}>
                                        <a href={`${a.url}?download=1`}><Download /></a>
                                    </Button>
                                </li>
                            ))}
                        </ul>
                        {o.artworks.some((a) => a.review_note) && <CardBody className="border-t border-border text-sm text-muted">{o.artworks.map((a) => a.review_note).filter(Boolean).join(' ')}</CardBody>}
                    </Card>
                    <MessageThread thread={thread} action={`/app/jobs/print/${o.id}/messages`} description="Questions about this order? Message the EPFD team." />
                </div>
                <div className="space-y-6">
                    <Card>
                        <CardHeader title="Status" />
                        <CardBody>
                            <Timeline steps={STEPS} current={timeline.current} cancelled={timeline.cancelled} dates={dates} />
                        </CardBody>
                    </Card>
                    <Card>
                        <CardHeader title="Price" />
                        <CardBody><PriceBreakdown quote={o.pricing} /></CardBody>
                    </Card>
                    {linked_campaign && (
                        <Card className="p-5">
                            <p className="flex items-center gap-2 text-sm font-medium"><Truck className="size-4" aria-hidden /> Linked campaign</p>
                            <Link href={linked_campaign.url} className="mt-1 block text-sm text-primary hover:underline">{linked_campaign.name}</Link>
                        </Card>
                    )}
                    {o.status === 'completed' && <RatingCard action={`/app/jobs/print/${o.id}/rating`} rating={o.rating} comment={o.rating_comment} />}
                </div>
            </div>
        </AppLayout>
    );
}
