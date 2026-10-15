import { AudienceMap } from '@/components/app/map';
import { MessageThread, RatingCard } from '@/components/app/misc';
import type { CampaignT, InvoiceT, RunT, ThreadT } from '@/components/app/types';
import { PriceBreakdown } from '@/components/app/wizard';
import { Button } from '@/components/ui/button';
import { Card, CardBody, CardHeader, EmptyState, PageHeader, StatusBadge, Timeline } from '@/components/ui/surface';
import AppLayout from '@/layouts/app-layout';
import { date, dateTime, num } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { Bookmark, Download, FileText, Printer, Repeat, Truck } from 'lucide-react';
import { useState } from 'react';

const STEPS = [
    { key: 'submitted', label: 'Submitted' },
    { key: 'runs_published', label: 'Runs published' },
    { key: 'allocated', label: 'Allocated' },
    { key: 'collected', label: 'Collected' },
    { key: 'in_delivery', label: 'In delivery' },
    { key: 'completed', label: 'Completed' },
];

export default function CampaignJob({ campaign: c, runs, invoice, thread, print_order, timeline }: { campaign: CampaignT; runs: RunT[]; invoice: InvoiceT | null; thread: ThreadT | null; print_order: { id: number; reference: string; status: string } | null; timeline: { current: number; cancelled: boolean; dates: Record<string, string | null> } }) {
    const [rerunning, setRerunning] = useState(false);
    const tracks = runs.map((r) => r.track ?? []).filter((t) => t.length > 1);
    const dates = Object.fromEntries(Object.entries(timeline.dates).map(([k, v]) => [k, v ? dateTime(v) : null]));
    const avgCoverage = runs.filter((r) => r.coverage_pct !== null).reduce((s, r, _, a) => s + Number(r.coverage_pct) / a.length, 0);

    return (
        <AppLayout title={c.name}>
            <PageHeader
                eyebrow={<Link href="/app/jobs" className="hover:underline">My Jobs</Link>}
                title={c.name}
                description={<span className="flex flex-wrap items-center gap-2">{c.reference} · Submitted {date(c.submitted_at)} <StatusBadge status={c.status} /></span>}
                actions={
                    <>
                        {invoice && (
                            <Button asChild variant="secondary">
                                <a href={invoice.pdf_url}>
                                    <FileText /> Invoice PDF
                                </a>
                            </Button>
                        )}
                        <Button variant="ink" loading={rerunning} onClick={() => router.post(`/app/campaigns/${c.id}/rerun`, {}, { onStart: () => setRerunning(true), onFinish: () => setRerunning(false) })}>
                            <Repeat /> Re-run
                        </Button>
                    </>
                }
            />
            <div className="grid items-start gap-6 lg:grid-cols-[1fr_340px]">
                <div className="space-y-6">
                    <Card className="overflow-hidden">
                        <CardHeader title="Areas & GPS coverage" description={`${c.areas.length} area${c.areas.length === 1 ? '' : 's'} · ${num(c.letterboxes)} letterboxes (estimated)${tracks.length ? ' · blue lines show distributor GPS tracks' : ''}`} />
                        <AudienceMap readOnly className="h-80 sm:h-96" areas={c.areas} pickup={c.pickup} includeHouses={c.include_houses} includeUnits={c.include_units} coverage={c.coverage} material={c.material} tracks={tracks} />
                        <ul className="divide-y divide-border border-t border-border">
                            {c.areas.map((a) => (
                                <li key={a.id} className="flex items-center gap-3 px-5 py-3 text-sm">
                                    <span className="flex-1 font-medium">{a.name}</span>
                                    <span className="tabular text-muted">{num(a.houses)} houses · {num(a.units)} units</span>
                                    <Button variant="ghost" size="sm" onClick={() => router.post(`/app/jobs/campaigns/${c.id}/areas/${a.id}/save`, {}, { preserveScroll: true })} aria-label={`Save ${a.name} as a target area`}>
                                        <Bookmark /> Save area
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </Card>

                    <Card>
                        <CardHeader
                            title="Proof of distribution"
                            description={runs.length ? `Average GPS coverage ${avgCoverage ? `${avgCoverage.toFixed(1)}%` : '—'}` : undefined}
                            action={
                                runs.length > 0 && (
                                    <Button asChild variant="secondary" size="sm">
                                        <a href={`/app/jobs/campaigns/${c.id}/proof.pdf`}>
                                            <Download /> Proof PDF
                                        </a>
                                    </Button>
                                )
                            }
                        />
                        <CardBody>
                            {runs.length === 0 ? (
                                <EmptyState icon={Truck} title="Runs not published yet" description="EPFD splits your areas into runs for local distributors, usually within one business day." className="py-8" />
                            ) : (
                                <div className="-mx-2 overflow-x-auto">
                                    <table className="w-full min-w-[560px] text-sm">
                                        <thead>
                                            <tr className="text-left text-xs text-muted uppercase">
                                                <th className="px-2 py-2 font-medium">Run</th>
                                                <th className="px-2 py-2 font-medium">Status</th>
                                                <th className="px-2 py-2 text-right font-medium">Flyers</th>
                                                <th className="px-2 py-2 text-right font-medium">Coverage</th>
                                                <th className="px-2 py-2 text-right font-medium">GPS points</th>
                                                <th className="px-2 py-2 font-medium">Finished</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-border">
                                            {runs.map((r) => (
                                                <tr key={r.id}>
                                                    <td className="px-2 py-2.5"><span className="font-medium">{r.name}</span><span className="block text-xs text-muted">{r.reference}</span></td>
                                                    <td className="px-2 py-2.5"><StatusBadge status={r.status} /></td>
                                                    <td className="tabular px-2 py-2.5 text-right">{num(r.flyers)}</td>
                                                    <td className="tabular px-2 py-2.5 text-right">{r.coverage_pct !== null ? `${Number(r.coverage_pct).toFixed(1)}%` : '—'}</td>
                                                    <td className="tabular px-2 py-2.5 text-right">{num(r.gps_count)}</td>
                                                    <td className="px-2 py-2.5 text-muted">{date(r.completed_at)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </CardBody>
                    </Card>

                    <MessageThread thread={thread} action={`/app/jobs/campaign/${c.id}/messages`} description="Questions about this campaign? Message the EPFD team." />
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader title="Status" />
                        <CardBody>
                            <Timeline steps={STEPS} current={timeline.current} cancelled={timeline.cancelled} dates={dates} />
                        </CardBody>
                    </Card>
                    {print_order && (
                        <Card className="p-5">
                            <p className="flex items-center gap-2 text-sm font-medium"><Printer className="size-4" aria-hidden /> Linked print order</p>
                            <Link href={`/app/jobs/print/${print_order.id}`} className="mt-1 block text-sm text-primary hover:underline">{print_order.reference}</Link>
                        </Card>
                    )}
                    <Card>
                        <CardHeader title="Summary" />
                        <CardBody className="space-y-4">
                            <dl className="space-y-1.5 text-sm">
                                <div className="flex justify-between"><dt className="text-muted">Pickup</dt><dd className="text-right">{c.pickup_address}</dd></div>
                                <div className="flex justify-between"><dt className="text-muted">Available from</dt><dd>{date(c.available_from)}</dd></div>
                            </dl>
                            <PriceBreakdown quote={c.pricing} />
                        </CardBody>
                    </Card>
                    {c.status === 'completed' && <RatingCard action={`/app/jobs/campaign/${c.id}/rating`} rating={c.rating} comment={c.rating_comment} />}
                </div>
            </div>
        </AppLayout>
    );
}
