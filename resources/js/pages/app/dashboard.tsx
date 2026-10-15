import { Button } from '@/components/ui/button';
import { Card, CardBody, CardHeader, EmptyState, PageHeader, StatTile, StatusBadge } from '@/components/ui/surface';
import AppLayout from '@/layouts/app-layout';
import { date, money, num } from '@/lib/utils';
import type { SharedProps } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { CheckCircle2, LifeBuoy, Mail, Plus, Printer, Truck, Wallet } from 'lucide-react';
import { Bar, BarChart, CartesianGrid, Cell, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

interface Props {
    stats: { total_flyers: number; active_runs: number; completed_runs: number; failed_runs: number; open_threads: number; credits_cents: number; drafts: number };
    outcomes: { successful: number; failed: number; in_flight: number };
    monthly: { month: string; label: string; flyers: number }[];
    threads: { id: number; subject: string; status: string; channel: string; last_message_at: string | null }[];
}

const OUTCOME = [
    { key: 'successful', label: 'Successful', color: 'var(--color-success)' },
    { key: 'failed', label: 'Failed', color: 'var(--color-danger)' },
    { key: 'in_flight', label: 'In flight', color: 'var(--ink-300)' },
] as const;

export default function Dashboard({ stats, outcomes, monthly, threads }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const finished = stats.completed_runs + stats.failed_runs;
    const totalRuns = outcomes.successful + outcomes.failed + outcomes.in_flight;
    const donut = OUTCOME.map((o) => ({ ...o, value: outcomes[o.key] }));
    const hasMonthly = monthly.some((m) => m.flyers > 0);

    return (
        <AppLayout title="Dashboard">
            <PageHeader
                eyebrow={auth.business?.name}
                title={`G'day, ${auth.user?.name.split(' ')[0] ?? 'there'}`}
                description="Your distribution and print at a glance."
                actions={
                    <>
                        <Button asChild variant="secondary">
                            <Link href="/app/print/new">
                                <Printer /> New Print Order
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href="/app/campaigns/new">
                                <Plus /> New Campaign
                            </Link>
                        </Button>
                    </>
                }
            />

            <section aria-label="Key figures" className="grid grid-cols-2 gap-4 lg:grid-cols-5">
                <StatTile label="Total flyers" value={num(stats.total_flyers)} hint="Paid campaigns (estimated letterboxes)" icon={Mail} />
                <StatTile label="Active runs" value={num(stats.active_runs)} hint="Published to delivery" icon={Truck} />
                <StatTile label="Success rate" value={finished ? `${Math.round((stats.completed_runs / finished) * 100)}%` : '—'} hint={`${num(stats.completed_runs)} of ${num(finished)} runs completed`} icon={CheckCircle2} />
                <StatTile label="Support requests" value={num(stats.open_threads)} hint="Open threads" icon={LifeBuoy} />
                <StatTile label="Credits" value={money(stats.credits_cents)} hint="Applied at checkout" icon={Wallet} />
            </section>

            <div className="mt-6 grid gap-6 lg:grid-cols-3">
                <Card>
                    <CardHeader title="Run outcomes" description="All runs across your campaigns" />
                    <CardBody>
                        {totalRuns === 0 ? (
                            <EmptyState icon={Truck} title="No runs yet" description="Runs appear once your first campaign is published to distributors." action={<Button asChild size="sm"><Link href="/app/campaigns/new">Create a campaign</Link></Button>} className="py-8" />
                        ) : (
                            <div className="flex items-center gap-6">
                                <div className="relative size-36 shrink-0" role="img" aria-label={`Run outcomes: ${donut.map((d) => `${d.label} ${d.value}`).join(', ')}`}>
                                    <ResponsiveContainer>
                                        <PieChart>
                                            <Pie data={donut} dataKey="value" nameKey="label" innerRadius="68%" outerRadius="100%" paddingAngle={donut.filter((d) => d.value > 0).length > 1 ? 2 : 0} stroke="none" isAnimationActive={false}>
                                                {donut.map((d) => (
                                                    <Cell key={d.key} fill={d.color} />
                                                ))}
                                            </Pie>
                                        </PieChart>
                                    </ResponsiveContainer>
                                    <div className="pointer-events-none absolute inset-0 grid place-items-center text-center">
                                        <div>
                                            <div className="tabular text-xl font-semibold">{num(totalRuns)}</div>
                                            <div className="text-xs text-muted">runs</div>
                                        </div>
                                    </div>
                                </div>
                                <ul className="flex-1 space-y-2.5 text-sm">
                                    {donut.map((d) => (
                                        <li key={d.key} className="flex items-center gap-2.5">
                                            <span className="size-2.5 shrink-0 rounded-full" style={{ background: d.color }} aria-hidden />
                                            <span className="flex-1 text-muted">{d.label}</span>
                                            <span className="tabular font-medium">{num(d.value)}</span>
                                            <span className="tabular w-10 text-right text-xs text-subtle">{totalRuns ? Math.round((d.value / totalRuns) * 100) : 0}%</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </CardBody>
                </Card>

                <Card className="lg:col-span-2">
                    <CardHeader title="Flyers distributed" description="Completed runs, last 12 months" />
                    <CardBody>
                        {!hasMonthly ? (
                            <EmptyState icon={Mail} title="Nothing delivered yet" description="Completed runs will chart here month by month." className="py-8" />
                        ) : (
                            <div className="h-56" role="img" aria-label={`Flyers distributed per month: ${monthly.map((m) => `${m.label} ${m.flyers}`).join(', ')}`}>
                                <ResponsiveContainer>
                                    <BarChart data={monthly} margin={{ top: 8, right: 4, left: -8, bottom: 0 }}>
                                        <CartesianGrid vertical={false} stroke="var(--color-border)" />
                                        <XAxis dataKey="month" tickLine={false} axisLine={false} tick={{ fill: 'var(--color-text-muted)', fontSize: 12 }} />
                                        <YAxis tickLine={false} axisLine={false} width={48} tick={{ fill: 'var(--color-text-muted)', fontSize: 12 }} tickFormatter={(v: number) => new Intl.NumberFormat('en-AU', { notation: 'compact' }).format(v)} />
                                        <Tooltip
                                            cursor={{ fill: 'var(--color-surface-2)' }}
                                            contentStyle={{ background: 'var(--color-surface)', border: '1px solid var(--color-border)', borderRadius: 12, fontSize: 13 }}
                                            labelFormatter={(_, p) => (p?.[0]?.payload as { label?: string } | undefined)?.label ?? ''}
                                            formatter={(v) => [num(Number(v)), 'Flyers']}
                                        />
                                        <Bar dataKey="flyers" fill="var(--ink-700)" radius={[6, 6, 0, 0]} maxBarSize={32} isAnimationActive={false} />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        )}
                    </CardBody>
                </Card>
            </div>

            <div className="mt-6 grid gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader title="Get started" description={stats.drafts ? `You have ${stats.drafts} draft campaign${stats.drafts === 1 ? '' : 's'}.` : 'Draw an area, see the price, pay — done.'} />
                    <CardBody className="grid gap-4 sm:grid-cols-2">
                        <Link href="/app/campaigns/new" className="group rounded-[14px] border border-border p-5 transition-colors hover:bg-surface-2">
                            <Truck className="mb-3 size-6 text-primary" aria-hidden />
                            <p className="font-semibold">Letterbox distribution</p>
                            <p className="mt-1 text-sm text-muted">Pick suburbs or draw areas. Live houses, units and price.</p>
                        </Link>
                        <Link href="/app/print/new" className="group rounded-[14px] border border-border p-5 transition-colors hover:bg-surface-2">
                            <Printer className="mb-3 size-6 text-primary" aria-hidden />
                            <p className="font-semibold">Print store</p>
                            <p className="mt-1 text-sm text-muted">Flyers, brochures, magnets and more — from 3c a piece.</p>
                        </Link>
                    </CardBody>
                </Card>
                <Card>
                    <CardHeader title="Support" action={<Link href="/app/support" className="text-sm font-medium text-primary hover:underline">View all</Link>} />
                    <CardBody>
                        {threads.length === 0 ? (
                            <div className="flex items-center gap-3 rounded-[12px] bg-success-soft px-4 py-3 text-sm text-success">
                                <CheckCircle2 className="size-5" aria-hidden /> <span><strong>All clear.</strong> No open requests.</span>
                            </div>
                        ) : (
                            <ul className="divide-y divide-border">
                                {threads.map((t) => (
                                    <li key={t.id}>
                                        <Link href={`/app/support/${t.id}`} className="flex items-center gap-3 py-3 hover:text-primary">
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-medium">{t.subject}</span>
                                                <span className="text-xs text-muted">{date(t.last_message_at)}</span>
                                            </span>
                                            <StatusBadge status={t.status} />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardBody>
                </Card>
            </div>
        </AppLayout>
    );
}
