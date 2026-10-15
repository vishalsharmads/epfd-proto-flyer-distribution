import { DesignArt, DESIGN_STEPS } from '@/components/app/design-bits';
import { PaymentForm, type PaymentContext } from '@/components/app/payment-form';
import type { DesignCatalogue, DesignOrderT } from '@/components/app/types';
import { PriceBreakdown, useAutosave, WizardShell } from '@/components/app/wizard';
import { Button } from '@/components/ui/button';
import { Checkbox, Field, Input, Label } from '@/components/ui/form';
import { Alert, Card, CardBody, CardHeader } from '@/components/ui/surface';
import AppLayout from '@/layouts/app-layout';
import { api, date, money } from '@/lib/utils';
import { Link, router, usePage } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { useCallback, useState } from 'react';

type Res = { data: DesignOrderT };

export default function DesignConfirm({ order: initial, catalogue, payment }: { order: DesignOrderT; catalogue: DesignCatalogue; payment: PaymentContext }) {
    const errors = usePage().props.errors as Record<string, string>;
    const [o, setO] = useState(initial);
    const [promo, setPromo] = useState(initial.promo_code ?? '');
    const send = useCallback((patch: Record<string, unknown>) => api<Res>(`/app/design/${o.id}`, { method: 'PATCH', json: patch }), [o.id]);
    const auto = useAutosave(send, useCallback((r: Res) => setO(r.data), []), 200);
    const toggle = (value: string, on: boolean) => {
        const addons = on ? [...o.addons, value] : o.addons.filter((a) => a !== value);
        setO((x) => ({ ...x, addons }));
        auto.save({ addons }, true);
    };
    const briefMissing = !o.headline?.trim() || !o.key_message?.trim();
    const pkg = catalogue.packages.find((p) => p.value === o.package);

    return (
        <AppLayout title="Design · Confirm" fullBleed hideFooter>
            <WizardShell steps={DESIGN_STEPS} current={3} onStep={(i) => router.visit(i === 0 ? '/app/design/new' : `/app/design/${o.id}/${i === 1 ? 'service' : 'brief'}`)} back={() => router.visit(`/app/design/${o.id}/brief`)} hideNext saveState={auto.state}>
                <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">Confirm & pay</h1>
                <p className="mt-1.5 text-muted">Add any extras, then pay to send your brief to our studio.</p>
                <div className="mt-8 grid items-start gap-8 lg:grid-cols-[1fr_380px]">
                    <div className="space-y-6">
                        <Card>
                            <CardHeader title="Add-ons" description="Optional extras, priced ex GST." />
                            <CardBody className="space-y-3">
                                {catalogue.addons.map((a) => (
                                    <div key={a.value} className="flex items-center gap-3">
                                        <Checkbox id={`design-addon-${a.value}`} data-testid={`design-addon-${a.value}`} checked={o.addons.includes(a.value)} onCheckedChange={(v) => toggle(a.value, v === true)} />
                                        <Label htmlFor={`design-addon-${a.value}`} className="font-normal">
                                            {a.label} <span className="text-muted">{a.pct ? `+${a.pct}%` : a.fixed_cents ? `+${money(a.fixed_cents)}` : ''}</span>
                                        </Label>
                                    </div>
                                ))}
                                {auto.errors.addons && <p className="text-sm text-danger">{auto.errors.addons}</p>}
                                <Field label="Promo code" optional error={auto.errors.promo_code} success={o.promo_code && !auto.errors.promo_code ? `${o.promo_code.toUpperCase()} applied` : undefined} className="pt-2">
                                    <div className="flex gap-2">
                                        <Input value={promo} onChange={(e) => setPromo(e.target.value.toUpperCase())} placeholder="e.g. WELCOME10" />
                                        <Button variant="secondary" size="lg" className="h-11" onClick={() => auto.save({ promo_code: promo || null }, true)}>
                                            {promo ? 'Apply' : 'Clear'}
                                        </Button>
                                    </div>
                                </Field>
                            </CardBody>
                        </Card>
                        {(briefMissing || errors.brief) && (
                            <Alert tone="danger" title="Your brief is incomplete" action={<Button asChild size="sm" variant="secondary"><Link href={`/app/design/${o.id}/brief`}>Edit brief</Link></Button>}>
                                {errors.brief ?? 'Add a headline and key message before paying.'}
                            </Alert>
                        )}
                        <PaymentForm action={`/app/design/${o.id}/pay`} totalCents={o.total_cents} payment={payment} extraValid={!briefMissing && auto.state !== 'saving'} blockedReason={briefMissing ? 'Complete your brief (headline and key message) before paying.' : null} />
                        <p className="flex items-start gap-2 text-sm text-muted">
                            <Info className="mt-0.5 size-4 shrink-0" aria-hidden /> Revisions beyond your package are billed at {money(catalogue.extra_revision_cents)} + GST each. You can approve the design or request changes from your job page.
                        </p>
                    </div>
                    <aside className="lg:sticky lg:top-24" aria-label="Order summary">
                        <Card>
                            <CardBody className="space-y-5">
                                <div className="flex items-center gap-3">
                                    <DesignArt type={o.design_type} className="size-14 shrink-0 rounded-[10px] [&>div]:size-9 [&_svg]:size-4" />
                                    <div>
                                        <h2 className="font-semibold">{o.name}</h2>
                                        <p className="text-sm text-muted">{o.reference}</p>
                                    </div>
                                </div>
                                <dl className="space-y-1.5 text-sm">
                                    {[
                                        ['Headline', o.headline ? `“${o.headline}”` : '—'],
                                        ['Sides', o.sides === 2 ? 'Double-sided' : 'Single-sided'],
                                        ['Print size', o.size ?? 'Not specified'],
                                        ['Revisions', o.revisions_unlimited ? 'Unlimited' : String(o.revisions_included)],
                                        ['First proof', `${o.pricing?.meta.turnaround_days ?? pkg?.turnaround_days ?? '—'} business days`],
                                        ['Needed by', o.due_date ? date(o.due_date) : 'Flexible'],
                                        ['Reference files', String(o.brief_files.length)],
                                    ].map(([k, v]) => (
                                        <div key={k} className="flex justify-between gap-4">
                                            <dt className="text-muted">{k}</dt>
                                            <dd className="min-w-0 truncate text-right">{v}</dd>
                                        </div>
                                    ))}
                                </dl>
                                <PriceBreakdown quote={o.pricing} />
                            </CardBody>
                        </Card>
                    </aside>
                </div>
            </WizardShell>
        </AppLayout>
    );
}
