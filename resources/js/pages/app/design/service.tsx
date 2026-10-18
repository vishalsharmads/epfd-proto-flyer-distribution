import { DesignArt, DESIGN_STEPS, revisionsText } from '@/components/app/design-bits';
import type { DesignCatalogue, DesignOrderT, DesignPackage, ProductT } from '@/components/app/types';
import { PriceBreakdown, useAutosave, WizardShell } from '@/components/app/wizard';
import { Field, Select } from '@/components/ui/form';
import { Alert, Badge, Card, CardBody } from '@/components/ui/surface';
import AppLayout from '@/layouts/app-layout';
import { api, cn, money } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { Check, Clock, RefreshCw } from 'lucide-react';
import { useCallback, useState } from 'react';

type Res = { data: DesignOrderT };

export default function DesignService({ order: initial, catalogue, products }: { order: DesignOrderT; catalogue: DesignCatalogue; products: ProductT[] }) {
    const [o, setO] = useState(initial);
    const [leaving, setLeaving] = useState(false);
    const send = useCallback((patch: Record<string, unknown>) => api<Res>(`/app/design/${o.id}`, { method: 'PATCH', json: patch }), [o.id]);
    const auto = useAutosave(send, useCallback((r: Res) => setO(r.data), []), 200);
    const set = (patch: Partial<DesignOrderT>) => {
        setO((x) => ({ ...x, ...patch }));
        const { product: _local, ...server } = patch;
        auto.save(server as Record<string, unknown>, true);
    };

    const type = catalogue.types.find((t) => t.value === o.design_type) ?? catalogue.types[0];
    const sidesFactor = type.sides_option && o.sides === 2 ? 1 + catalogue.double_sided_pct / 100 : 1;
    // Print sizes for the optional "print size" choice: products in the categories linked to this service (rate card).
    const linked = catalogue.types.find((t) => t.value === o.design_type)?.print_categories ?? [];
    const sizeOptions = products.filter((p) => linked.includes(p.category));

    const next = async () => {
        setLeaving(true);
        if (!(await auto.settle())) return setLeaving(false);
        router.visit(`/app/design/${o.id}/brief`, { onFinish: () => setLeaving(false) });
    };

    return (
        <AppLayout title="Design · Service" fullBleed hideFooter>
            <WizardShell steps={DESIGN_STEPS} current={1} onStep={() => router.visit('/app/design/new')} back={() => router.visit('/app/design/new')} next={next} nextDisabled={o.total_cents <= 0} nextLoading={leaving} saveState={auto.state}>
                <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">What should we design?</h1>
                <p className="mt-1.5 text-muted">Choose a service and package. Prices are ex GST; GST is itemised at checkout.</p>
                {auto.errors._ && <Alert tone="danger" className="mt-6">{auto.errors._}</Alert>}

                <div className="mt-8 grid items-start gap-8 lg:grid-cols-[1fr_340px]">
                    <div className="space-y-8">
                        <fieldset>
                            <legend className="mb-3 text-sm font-medium">Service</legend>
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
                                {catalogue.types.map((t) => (
                                    <label key={t.value} data-testid={`type-${t.value}`} className={cn('relative cursor-pointer overflow-hidden rounded-[14px] border bg-surface text-sm transition-[border,box-shadow]', o.design_type === t.value ? 'border-fg ring-2 ring-fg/10' : 'border-border hover:border-border-strong')}>
                                        <input type="radio" name="design_type" className="sr-only" checked={o.design_type === t.value} onChange={() => set({ design_type: t.value, print_product_id: null, size: null, product: null })} />
                                        {o.design_type === t.value && <Check className="absolute top-2 right-2 z-10 size-5 rounded-full bg-ink-900 p-0.5 text-white" aria-hidden />}
                                        <DesignArt icon={t.icon} className="aspect-[16/9]" />
                                        <span className="block px-3 pt-2 font-medium">{t.label.replace(' design', '')}</span>
                                        <span className="block px-3 pb-3 text-xs text-muted">from {money(t.from_cents)}</span>
                                    </label>
                                ))}
                            </div>
                        </fieldset>

                        <fieldset>
                            <legend className="mb-3 text-sm font-medium">Package</legend>
                            <div className="grid gap-3 md:grid-cols-3">
                                {catalogue.packages.map((p) => {
                                    const cents = Math.round(type.prices[p.value] * sidesFactor);
                                    const active = o.package === p.value;
                                    return (
                                        <label key={p.value} data-testid={`package-${p.value}`} className={cn('relative flex cursor-pointer flex-col rounded-[16px] border bg-surface p-5 transition-[border,box-shadow]', active ? 'border-fg ring-2 ring-fg/10' : 'border-border hover:border-border-strong')}>
                                            <input type="radio" name="package" className="sr-only" checked={active} onChange={() => set({ package: p.value as DesignPackage })} />
                                            <span className="flex items-center justify-between">
                                                <span className="font-semibold">{p.label}</span>
                                                {p.value === 'standard' && <Badge tone="brand">Popular</Badge>}
                                            </span>
                                            <span className="tabular mt-2 text-2xl font-semibold tracking-tight">{money(cents)}</span>
                                            <span className="text-xs text-muted">ex GST</span>
                                            <span className="mt-3 text-sm text-muted">{p.description}</span>
                                            <ul className="mt-4 space-y-1.5 text-sm">
                                                <li className="flex items-center gap-2"><RefreshCw className="size-4 text-muted" aria-hidden /> {revisionsText(p)}</li>
                                                <li className="flex items-center gap-2"><Clock className="size-4 text-muted" aria-hidden /> First proof in {p.turnaround_days} business days</li>
                                            </ul>
                                        </label>
                                    );
                                })}
                            </div>
                        </fieldset>

                        {type.sides_option && (
                            <fieldset>
                                <legend className="mb-2.5 text-sm font-medium">Sides</legend>
                                <div className="flex flex-wrap gap-2">
                                    {[1, 2].map((s) => (
                                        <label key={s} data-testid={`design-sides-${s}`} className={cn('cursor-pointer rounded-[12px] border px-3.5 py-2 text-sm transition-colors', o.sides === s ? 'border-fg bg-surface-2 font-medium' : 'border-border hover:border-border-strong')}>
                                            <input type="radio" name="sides" className="sr-only" checked={o.sides === s} onChange={() => set({ sides: s })} />
                                            {s === 1 ? 'Single-sided' : `Double-sided (+${catalogue.double_sided_pct}%)`}
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                        )}

                        {sizeOptions.length > 0 && (
                            <Field label="Print size" optional hint="Tell us the product you plan to print so we design at the right size with bleed.">
                                <Select
                                    data-testid="design-product"
                                    value={o.print_product_id ?? ''}
                                    onChange={(e) => {
                                        const id = e.target.value ? Number(e.target.value) : null;
                                        const p = sizeOptions.find((x) => x.id === id) ?? null;
                                        set({ print_product_id: id, size: p?.size_label ?? null, product: p });
                                    }}
                                >
                                    <option value="">Not sure yet</option>
                                    {sizeOptions.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.name} — {p.size_label}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                        )}
                    </div>

                    <aside className="lg:sticky lg:top-24" aria-label="Price">
                        <Card>
                            <CardBody className="space-y-4">
                                <h2 className="text-lg font-semibold">Your price</h2>
                                <PriceBreakdown quote={o.pricing} />
                                <div className="grid grid-cols-2 gap-2 text-sm">
                                    <div className="rounded-[12px] bg-surface-2 px-3 py-2.5">
                                        <span className="block text-muted">Revisions</span>
                                        <span className="font-semibold">{o.revisions_unlimited ? 'Unlimited' : o.revisions_included}</span>
                                    </div>
                                    <div className="rounded-[12px] bg-surface-2 px-3 py-2.5">
                                        <span className="block text-muted">First proof</span>
                                        <span className="font-semibold">{o.pricing?.meta.turnaround_days ?? '—'} business days</span>
                                    </div>
                                </div>
                            </CardBody>
                        </Card>
                    </aside>
                </div>
            </WizardShell>
        </AppLayout>
    );
}
