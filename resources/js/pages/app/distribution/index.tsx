import { MapThumb, PolygonSketch } from '@/components/app/map';
import type { CampaignCard, RunT } from '@/components/app/types';
import { ago } from '@/components/app/wizard';
import { Button } from '@/components/ui/button';
import { Badge, Card, EmptyState, PageHeader, StatusBadge } from '@/components/ui/surface';
import AppLayout from '@/layouts/app-layout';
import { date, money, num } from '@/lib/utils';
import type { LngLat } from '@/types';
import { Link } from '@inertiajs/react';
import { Plus, Send, Truck } from 'lucide-react';
import { useEffect, useState } from 'react';

const COLUMNS = [
    { key: 'to_allocate', title: 'To allocate', hint: 'Waiting for a distributor' },
    { key: 'to_collect', title: 'To collect', hint: 'Distributor assigned' },
    { key: 'out_for_delivery', title: 'Out for delivery', hint: 'Live GPS' },
] as const;

/** Simulated live position: walks along the recorded track (or the run outline) every 1.5 s. */
function useSimulatedTrack(run: RunT): LngLat[] {
    const base: LngLat[] = run.track && run.track.length > 1 ? run.track : (run.geometry?.coordinates[0] ?? []);
    const [n, setN] = useState(Math.min(2, base.length));
    useEffect(() => {
        if (base.length < 2 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        const t = setInterval(() => setN((c) => (c >= base.length ? 2 : c + 1)), 1500);
        return () => clearInterval(t);
    }, [base.length]);
    return base.slice(0, Math.max(2, n));
}

function LiveRunMap({ run }: { run: RunT }) {
    const track = useSimulatedTrack(run);
    return <PolygonSketch polygons={run.geometry ? [run.geometry] : []} tracks={[track]} animate className="h-28 w-full" />;
}

function RunCard({ run, live }: { run: RunT; live: boolean }) {
    return (
        <Card className="p-4">
            {live && <LiveRunMap run={run} />}
            <div className={live ? 'mt-3' : ''}>
                <div className="flex items-start justify-between gap-2">
                    <p className="font-medium">{run.name}</p>
                    <StatusBadge status={run.status} />
                </div>
                <p className="mt-0.5 text-xs text-muted">
                    {run.reference} · {run.campaign ? <Link href={`/app/jobs/campaigns/${run.campaign.id}`} className="hover:underline">{run.campaign.name}</Link> : '—'}
                </p>
                <div className="mt-3 flex justify-between text-sm">
                    <span className="tabular">{num(run.flyers)} flyers</span>
                    <span className="text-muted">Due {date(run.deadline)}</span>
                </div>
            </div>
        </Card>
    );
}

export default function DistributionHub({ board, campaigns }: { board: Record<(typeof COLUMNS)[number]['key'], RunT[]>; campaigns: CampaignCard[] }) {
    const totalRuns = COLUMNS.reduce((s, c) => s + board[c.key].length, 0);
    return (
        <AppLayout title="Letterbox Distribution">
            <PageHeader
                title="Letterbox Distribution"
                description="Track current runs and manage your campaigns."
                actions={
                    <Button asChild>
                        <Link href="/app/campaigns/new">
                            <Plus /> New Campaign
                        </Link>
                    </Button>
                }
            />

            <section aria-labelledby="runs-h">
                <h2 id="runs-h" className="mb-4 text-lg font-semibold">
                    Current runs
                </h2>
                {totalRuns === 0 ? (
                    <EmptyState icon={Truck} title="No runs in progress" description="Once you pay for a campaign, EPFD publishes runs to local distributors and you can follow them here." />
                ) : (
                    <div className="grid gap-4 md:grid-cols-3">
                        {COLUMNS.map((col) => (
                            <div key={col.key} className="rounded-[16px] bg-surface-2 p-3">
                                <div className="mb-3 flex items-center justify-between px-1">
                                    <h3 className="text-sm font-semibold">
                                        {col.title} <span className="ml-1 text-muted">{board[col.key].length}</span>
                                    </h3>
                                    <span className="text-xs text-muted">{col.hint}</span>
                                </div>
                                <div className="space-y-3">
                                    {board[col.key].length === 0 ? <p className="px-1 py-6 text-center text-sm text-subtle">Nothing here</p> : board[col.key].map((r) => <RunCard key={r.id} run={r} live={col.key === 'out_for_delivery'} />)}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </section>

            <section aria-labelledby="campaigns-h" className="mt-12">
                <h2 id="campaigns-h" className="mb-4 text-lg font-semibold">
                    My campaigns
                </h2>
                {campaigns.length === 0 ? (
                    <EmptyState
                        icon={Send}
                        title="Create a distribution campaign"
                        description="Draw your target areas, see estimated letterboxes and the price instantly."
                        action={
                            <Button asChild>
                                <Link href="/app/campaigns/new">
                                    <Plus /> Create a distribution campaign
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <Card className="divide-y divide-border">
                        {campaigns.map((c) => (
                            <Link key={c.id} href={c.status === 'draft' ? `/app/campaigns/${c.id}/${c.step >= 4 ? 'confirm' : c.step >= 3 ? 'details' : 'audience'}` : `/app/jobs/campaigns/${c.id}`} className="flex items-center gap-4 px-4 py-3.5 transition-colors hover:bg-surface-2 sm:px-5">
                                <MapThumb polygons={c.polygons} className="h-12 w-16 shrink-0" />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate font-medium">{c.name}</p>
                                    <p className="text-xs text-muted">
                                        {c.reference} · {c.area_count} area{c.area_count === 1 ? '' : 's'} · {num(c.letterboxes)} letterboxes (est.) · {c.status === 'draft' ? `edited ${ago(c.updated_at)}` : date(c.submitted_at)}
                                    </p>
                                </div>
                                <span className="tabular hidden text-sm font-medium sm:block">{money(c.total_cents)}</span>
                                {c.status === 'draft' ? <Badge>Draft</Badge> : <StatusBadge status={c.status} />}
                            </Link>
                        ))}
                    </Card>
                )}
            </section>
        </AppLayout>
    );
}
