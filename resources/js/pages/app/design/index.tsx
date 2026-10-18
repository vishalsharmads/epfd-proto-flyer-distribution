import { DesignArt, designStepUrl, fromLabel, revisionsText } from '@/components/app/design-bits';
import type { DesignCatalogue, DesignOrderT, DesignType } from '@/components/app/types';
import { Button } from '@/components/ui/button';
import { Badge, Card, CardHeader, EmptyState, PageHeader, StatusBadge } from '@/components/ui/surface';
import AppLayout from '@/layouts/app-layout';
import { date, money } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { Clock, Palette, Plus, RefreshCw } from 'lucide-react';
import { useState } from 'react';

export default function DesignHub({ orders, catalogue }: { orders: DesignOrderT[]; catalogue: DesignCatalogue }) {
    const [busy, setBusy] = useState<string | null>(null);
    const start = (type: DesignType) => router.post('/app/design', { design_type: type }, { onStart: () => setBusy(type), onFinish: () => setBusy(null) });
    const fastest = Math.min(...catalogue.packages.map((p) => p.turnaround_days));
    const basic = catalogue.packages[0];
    const best = catalogue.packages[catalogue.packages.length - 1];

    return (
        <AppLayout title="Design Studio">
            <PageHeader
                title="Design Studio"
                description="Our creative team delivers professional graphic design that enhances your printed materials and supports effective distribution campaigns."
                actions={
                    <Button asChild>
                        <Link href="/app/design/new" data-testid="new-design">
                            <Plus /> New Design
                        </Link>
                    </Button>
                }
            />

            <section aria-labelledby="services-heading">
                <div className="mb-4 flex flex-wrap items-end justify-between gap-2">
                    <h2 id="services-heading" className="text-lg font-semibold">Design services</h2>
                    <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted">
                        <span className="inline-flex items-center gap-1.5"><Clock className="size-4" aria-hidden /> First proof in as little as {fastest} business days</span>
                        <span className="inline-flex items-center gap-1.5"><RefreshCw className="size-4" aria-hidden /> {revisionsText(basic)} to {revisionsText(best).toLowerCase()}</span>
                    </p>
                </div>
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    {catalogue.types.map((t) => (
                        <button key={t.value} type="button" data-testid={`design-service-${t.value}`} disabled={busy !== null} onClick={() => start(t.value)} className="flex flex-col overflow-hidden rounded-[16px] border border-border bg-surface text-left shadow-sm transition-shadow hover:shadow-md focus-visible:outline-2 disabled:opacity-70">
                            <DesignArt icon={t.icon} className="aspect-[16/10] w-full" />
                            <div className="flex flex-1 flex-col p-4">
                                <p className="font-semibold">{t.label}</p>
                                <p className="mt-1 flex-1 text-sm text-muted">{t.description}</p>
                                <p className="mt-3 text-sm font-semibold">{fromLabel(t.from_cents)}</p>
                            </div>
                        </button>
                    ))}
                </div>
            </section>

            <Card className="mt-8">
                <CardHeader title="My design orders" description="Drafts, briefs in progress and approved designs." />
                {orders.length === 0 ? (
                    <div className="p-5 sm:p-6">
                        <EmptyState icon={Palette} title="No design orders yet" description="Tell us what you need in a short brief — our designers send proofs for you to approve, then we can print and letterbox it." action={<Button asChild><Link href="/app/design/new">Start a design</Link></Button>} />
                    </div>
                ) : (
                    <ul className="divide-y divide-border">
                        {orders.map((o) => (
                            <li key={o.id}>
                                <Link href={o.status === 'draft' ? designStepUrl(o) : `/app/jobs/design/${o.id}`} className="flex items-center gap-4 px-4 py-3.5 hover:bg-surface-2 sm:px-5" data-testid={`design-order-${o.id}`}>
                                    <DesignArt icon={o.type_icon} className="size-12 shrink-0 rounded-[10px] [&>div]:size-8 [&>div]:rounded-[8px] [&_svg]:size-4" />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate font-medium">{o.name}</p>
                                        <p className="truncate text-xs text-muted">
                                            {o.reference} · {date(o.submitted_at ?? o.updated_at)}
                                            {o.headline && <> · “{o.headline}”</>}
                                        </p>
                                    </div>
                                    {o.proofs.length > 0 && <span className="hidden text-xs text-muted md:inline">Proof v{o.proofs[0].version}</span>}
                                    <span className="tabular hidden text-sm font-medium sm:block">{money(o.total_cents)}</span>
                                    {o.status === 'draft' ? <Badge>Draft</Badge> : <StatusBadge status={o.status} />}
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        </AppLayout>
    );
}
