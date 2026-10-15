import { MapThumb } from '@/components/app/map';
import { PaymentForm, type PaymentContext } from '@/components/app/payment-form';
import type { CampaignT, DistributionOptions } from '@/components/app/types';
import { CAMPAIGN_STEPS, PriceBreakdown, WizardShell } from '@/components/app/wizard';
import { Card, CardBody } from '@/components/ui/surface';
import AppLayout from '@/layouts/app-layout';
import { date, num } from '@/lib/utils';
import { router, usePage } from '@inertiajs/react';

export default function CampaignConfirm({ campaign: c, options, payment }: { campaign: CampaignT; options: DistributionOptions; payment: PaymentContext }) {
    const errors = usePage().props.errors as Record<string, string>;
    const material = options.materials.find((m) => m.value === c.material)?.label ?? c.material;
    const coverage = options.coverages.find((m) => m.value === c.coverage)?.label ?? c.coverage;
    const readiness = errors.areas ?? errors.coverage ?? errors.pickup_address ?? errors.available_from ?? (c.letterboxes <= 0 ? 'Your areas have no billable letterboxes for the selected coverage.' : null);

    return (
        <AppLayout title={`${c.name} · Confirm`} fullBleed hideFooter>
            <WizardShell steps={CAMPAIGN_STEPS} current={3} onStep={(i) => router.visit(i === 0 ? '/app/campaigns/new' : `/app/campaigns/${c.id}/${i === 1 ? 'audience' : 'details'}`)} back={() => router.visit(`/app/campaigns/${c.id}/details`)} hideNext>
                <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">Confirm & pay</h1>
                <p className="mt-1.5 text-muted">Check your campaign, then pay to send it to our distributors.</p>
                <div className="mt-8 grid items-start gap-8 lg:grid-cols-[1fr_380px]">
                    <PaymentForm action={`/app/campaigns/${c.id}/pay`} totalCents={c.total_cents} payment={payment} blockedReason={readiness} />
                    <aside className="lg:sticky lg:top-24" aria-label="Order summary">
                        <Card>
                            <CardBody className="space-y-5">
                                <div>
                                    <h2 className="text-lg font-semibold">{c.name}</h2>
                                    <p className="text-sm text-muted">{c.reference}</p>
                                </div>
                                <div className="aspect-[16/10] overflow-hidden rounded-[12px] bg-surface-2">
                                    <MapThumb polygons={c.areas.map((a) => a.geometry)} />
                                </div>
                                <dl className="space-y-1.5 text-sm">
                                    {[
                                        ['Areas', String(c.areas.length)],
                                        ['Letterboxes (est.)', num(c.letterboxes)],
                                        ['Coverage', coverage],
                                        ['Material', material],
                                        ['Pickup', c.pickup_address ?? '—'],
                                        ['Available from', date(c.available_from)],
                                    ].map(([k, v]) => (
                                        <div key={k} className="flex justify-between gap-4">
                                            <dt className="shrink-0 text-muted">{k}</dt>
                                            <dd className="text-right">{v}</dd>
                                        </div>
                                    ))}
                                </dl>
                                <PriceBreakdown quote={c.pricing} />
                            </CardBody>
                        </Card>
                    </aside>
                </div>
            </WizardShell>
        </AppLayout>
    );
}
