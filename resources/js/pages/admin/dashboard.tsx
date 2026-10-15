import { AdminMap, useCan } from '@/components/admin/kit';
import { Button } from '@/components/ui/button';
import { Card, CardBody, CardHeader, EmptyState, Skeleton, StatTile } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { dateTime, money, num, titleCase } from '@/lib/utils';
import type { GeoJsonPolygon, LngLat } from '@/types';
import { Deferred, Link } from '@inertiajs/react';
import { Activity, Building2, DollarSign, Inbox, Palette, Printer, Truck } from 'lucide-react';
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

interface Props {
    kpis: { pending_approvals: number; runs_in_flight: number; revenue_month_cents: number; open_support: number; active_jobs: number; artwork_waiting: number; design_awaiting_proof: number };
    jobs: { status: string; campaigns: number; print: number }[];
    revenue: { month: string; cents: number }[];
    activity: { id: number; action: string; user: string | null; subject: string | null; created_at: string }[];
    live?: { runs: { id: number; reference: string; name: string; status: string; coverage_pct: number | null; campaign: { id: number; reference: string; name: string } | null; geometry: GeoJsonPolygon }[]; tracks: { run_id: number; points: LngLat[] }[] };
}

const axis = { fontSize: 12, fill: 'var(--color-text-muted, #6b7280)' };

export default function Dashboard({ kpis, jobs, revenue, activity, live }: Props) {
    const can = useCan();
    const jobsData = jobs.filter((j) => j.campaigns + j.print > 0 || ['submitted', 'in_delivery', 'completed'].includes(j.status)).map((j) => ({ ...j, label: titleCase(j.status) }));
    return (
        <AdminLayout title="Dashboard">
            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4 xl:grid-cols-7">
                <Link href="/admin/businesses?status=pending" className="rounded-[16px] focus-visible:outline-2 focus-visible:outline-focus">
                    <StatTile label="Pending approvals" value={num(kpis.pending_approvals)} icon={Building2} hint="Businesses to review" />
                </Link>
                <StatTile label="Active jobs" value={num(kpis.active_jobs)} icon={Activity} hint="Campaigns, print + design" />
                <StatTile label="Runs in flight" value={num(kpis.runs_in_flight)} icon={Truck} hint="Allocated → in delivery" />
                <Link href="/admin/print-orders?status=awaiting_artwork_approval" className="rounded-[16px] focus-visible:outline-2 focus-visible:outline-focus">
                    <StatTile label="Artwork to review" value={num(kpis.artwork_waiting)} icon={Printer} />
                </Link>
                <Link href="/admin/design-orders?status=awaiting_proof" className="rounded-[16px] focus-visible:outline-2 focus-visible:outline-focus" data-testid="kpi-design-awaiting-proof">
                    <StatTile label="Designs awaiting proof" value={num(kpis.design_awaiting_proof)} icon={Palette} hint="Submitted, in design or changes" />
                </Link>
                <StatTile label="Revenue this month" value={money(kpis.revenue_month_cents)} icon={DollarSign} hint="Incl. GST, net of refunds" />
                <Link href="/admin/support?status=open" className="rounded-[16px] focus-visible:outline-2 focus-visible:outline-focus">
                    <StatTile label="Open support" value={num(kpis.open_support)} icon={Inbox} hint="Open + pending threads" />
                </Link>
            </div>

            <div className="mt-6 grid gap-6 xl:grid-cols-2">
                <Card>
                    <CardHeader title="Jobs by status" description="Distribution campaigns and print orders" />
                    <CardBody>
                        {jobsData.every((j) => j.campaigns + j.print === 0) ? (
                            <EmptyState title="No jobs yet" description="Submitted campaigns and print orders will appear here." />
                        ) : (
                            <div className="h-72" role="img" aria-label={`Jobs by status: ${jobsData.map((j) => `${j.label} ${j.campaigns + j.print}`).join(', ')}`}>
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={jobsData} margin={{ left: -16 }}>
                                        <CartesianGrid vertical={false} strokeOpacity={0.15} />
                                        <XAxis dataKey="label" tick={axis} interval={0} angle={-25} textAnchor="end" height={60} />
                                        <YAxis allowDecimals={false} tick={axis} />
                                        <Tooltip cursor={{ fillOpacity: 0.06 }} contentStyle={{ borderRadius: 10, fontSize: 12 }} />
                                        <Legend wrapperStyle={{ fontSize: 12 }} />
                                        <Bar dataKey="campaigns" name="Distribution" stackId="a" fill="var(--color-primary, #e00b33)" radius={[0, 0, 0, 0]} />
                                        <Bar dataKey="print" name="Print" stackId="a" fill="var(--color-text, #141414)" radius={[4, 4, 0, 0]} />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        )}
                    </CardBody>
                </Card>
                <Card>
                    <CardHeader title="Revenue — last 12 months" description="Incl. GST, net of refunds (AUD)" />
                    <CardBody>
                        <div className="h-72" role="img" aria-label={`Revenue by month: ${revenue.map((r) => `${r.month} ${money(r.cents)}`).join(', ')}`}>
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={revenue.map((r) => ({ ...r, dollars: r.cents / 100 }))} margin={{ left: 0 }}>
                                    <CartesianGrid vertical={false} strokeOpacity={0.15} />
                                    <XAxis dataKey="month" tick={axis} />
                                    <YAxis tick={axis} tickFormatter={(v: number) => `$${v >= 1000 ? `${Math.round(v / 1000)}k` : v}`} />
                                    <Tooltip formatter={(v) => money(Number(v) * 100)} contentStyle={{ borderRadius: 10, fontSize: 12 }} cursor={{ fillOpacity: 0.06 }} />
                                    <Bar dataKey="dollars" name="Revenue" fill="var(--color-primary, #e00b33)" radius={[4, 4, 0, 0]} />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    </CardBody>
                </Card>
            </div>

            <div className="mt-6 grid gap-6 xl:grid-cols-[1.4fr_1fr]">
                <Card className="overflow-hidden">
                    <CardHeader title="Live map" description="Runs collected or in delivery, with their latest GPS fixes" action={can('campaigns.view') ? <Button asChild variant="secondary" size="sm"><Link href="/admin/campaigns?status=in_delivery">View runs</Link></Button> : undefined} />
                    <Deferred data="live" fallback={<Skeleton className="h-[380px] rounded-none" />}>
                        <LiveMap live={live} />
                    </Deferred>
                </Card>
                <Card>
                    <CardHeader title="Latest activity" action={can('audit.view') ? <Button asChild variant="ghost" size="sm"><Link href="/admin/audit-log">Audit log</Link></Button> : undefined} />
                    {activity.length === 0 ? (
                        <CardBody>
                            <EmptyState title="Nothing yet" description="Staff and payment actions are logged here." />
                        </CardBody>
                    ) : (
                        <ul className="divide-y divide-border">
                            {activity.map((a) => (
                                <li key={a.id} className="flex items-start justify-between gap-3 px-5 py-3 text-sm sm:px-6">
                                    <div className="min-w-0">
                                        <p className="font-medium">{titleCase(a.action.replace('.', ' · '))}</p>
                                        <p className="truncate text-muted">
                                            {a.user ?? 'System'}
                                            {a.subject && ` · ${a.subject}`}
                                        </p>
                                    </div>
                                    <time className="shrink-0 text-xs text-subtle" dateTime={a.created_at}>
                                        {dateTime(a.created_at)}
                                    </time>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </AdminLayout>
    );
}

function LiveMap({ live }: { live?: Props['live'] }) {
    if (!live || live.runs.length === 0) {
        return (
            <div className="p-6">
                <EmptyState icon={Truck} title="No runs on the road" description="When a run is collected or in delivery, its area and GPS track appear here." />
            </div>
        );
    }
    return (
        <>
            <AdminMap
                className="h-[380px] rounded-none border-0"
                readOnly
                mode="admin"
                areas={live.runs.map((r) => ({ id: r.id, name: `${r.reference} · ${r.name}`, draw_mode: 'pins' as const, geometry: r.geometry }))}
                tracks={live.tracks.filter((t) => t.points.length > 1).map((t) => ({ points: t.points }))}
            />
            <ul className="divide-y divide-border border-t border-border text-sm">
                {live.runs.map((r) => (
                    <li key={r.id} className="flex items-center justify-between gap-3 px-5 py-2.5">
                        <Link href={r.campaign ? `/admin/campaigns/${r.campaign.id}` : '#'} className="font-medium hover:underline">
                            {r.reference} <span className="font-normal text-muted">· {r.campaign?.name}</span>
                        </Link>
                        <span className="tabular text-muted">{r.coverage_pct != null ? `${r.coverage_pct}% covered` : titleCase(r.status)}</span>
                    </li>
                ))}
            </ul>
        </>
    );
}
