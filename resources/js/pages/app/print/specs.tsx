import { ArtworkSlot } from '@/components/app/artwork';
import { ProductArt } from '@/components/app/print-bits';
import type { AddonT, ArtworkT, PrintOrderT, ProductT } from '@/components/app/types';
import { PRINT_STEPS, PriceBreakdown, useAutosave, WizardShell } from '@/components/app/wizard';
import { Button } from '@/components/ui/button';
import { Checkbox, Field, Input, Label } from '@/components/ui/form';
import { Alert, Card, CardBody } from '@/components/ui/surface';
import AppLayout from '@/layouts/app-layout';
import { api, cn, date, money, num } from '@/lib/utils';
import type { Quote } from '@/types';
import { router } from '@inertiajs/react';
import { Truck } from 'lucide-react';
import { useCallback, useEffect, useState, type ReactNode } from 'react';

type Res = { data: PrintOrderT };

function Segment<T extends string | number>({ label, options, value, onChange, testPrefix }: { label: string; options: { value: T; label: ReactNode; disabled?: boolean; reason?: string; sub?: ReactNode }[]; value: T | null; onChange: (v: T) => void; testPrefix?: string }) {
    return (
        <fieldset>
            <legend className="mb-2.5 text-sm font-medium">{label}</legend>
            <div className="flex flex-wrap gap-2">
                {options.map((o) => (
                    <label
                        key={String(o.value)}
                        title={o.disabled ? o.reason : undefined}
                        data-testid={testPrefix ? `${testPrefix}-${o.value}` : undefined}
                        className={cn(
                            'relative flex min-w-20 cursor-pointer flex-col rounded-[12px] border px-3.5 py-2 text-sm transition-colors',
                            value === o.value ? 'border-fg bg-surface-2 font-medium' : 'border-border hover:border-border-strong',
                            o.disabled && 'cursor-not-allowed opacity-50 hover:border-border',
                        )}
                    >
                        <input type="radio" className="sr-only" checked={value === o.value} disabled={o.disabled} onChange={() => onChange(o.value)} aria-describedby={o.disabled && o.reason ? `${label}-${o.value}-why` : undefined} />
                        <span>{o.label}</span>
                        {o.sub && <span className="tabular text-xs font-normal text-muted">{o.sub}</span>}
                        {o.disabled && o.reason && (
                            <span id={`${label}-${o.value}-why`} className="text-xs font-normal text-muted">
                                {o.reason}
                            </span>
                        )}
                    </label>
                ))}
            </div>
        </fieldset>
    );
}

export default function PrintSpecs({ order: initial, addons }: { order: PrintOrderT; addons: AddonT[] }) {
    const [o, setO] = useState(initial);
    const [promo, setPromo] = useState(initial.promo_code ?? '');
    const [tierPrices, setTierPrices] = useState<Record<number, number>>({});
    const [leaving, setLeaving] = useState(false);
    const p = o.product as ProductT;

    const send = useCallback((patch: Record<string, unknown>) => api<Res>(`/app/print/${o.id}`, { method: 'PATCH', json: patch }), [o.id]);
    const onSaved = useCallback((r: Res) => setO((cur) => ({ ...r.data, artworks: cur.artworks })), []);
    const auto = useAutosave(send, onSaved, 250);

    const set = (patch: Partial<PrintOrderT>) => {
        setO((x) => ({ ...x, ...patch }));
        auto.save(patch as Record<string, unknown>, true);
    };

    // Per-piece price for every quantity tier (same pricing engine via the public quote endpoint).
    useEffect(() => {
        if (!o.gsm) return;
        let cancelled = false;
        Promise.all(
            p.quantity_options.map((q) =>
                api<{ data: Quote }>('/api/v1/pricing/print/quote', { json: { product_id: p.id, gsm: o.gsm, sides: o.sides, quantity: q, finish: o.finish } })
                    .then((r) => [q, r.data.total_cents / q] as const)
                    .catch(() => [q, NaN] as const),
            ),
        ).then((rows) => !cancelled && setTierPrices(Object.fromEntries(rows.filter(([, v]) => !Number.isNaN(v)))));
        return () => {
            cancelled = true;
        };
    }, [p.id, o.gsm, o.sides, o.finish, p.quantity_options]);

    const allowedFinishes = (o.gsm && p.finish_options[String(o.gsm)]) || ['matt'];
    const front = o.artworks.find((a) => a.side === 'front') ?? null;
    const back = o.artworks.find((a) => a.side === 'back') ?? null;
    const singleOnly = ['banner', 'magnet'].includes(p.category);
    const setArt = (side: 'front' | 'back', a: ArtworkT | null) => setO((x) => ({ ...x, artworks: [...x.artworks.filter((y) => y.side !== side), ...(a ? [a] : [])] }));
    const missingArt = !front ? 'Upload your front artwork.' : o.sides === 2 && !back ? 'Upload your back artwork.' : null;
    const quote = o.pricing;

    const next = async () => {
        setLeaving(true);
        if (!(await auto.settle())) return setLeaving(false);
        router.visit(`/app/print/${o.id}/confirm`, { onFinish: () => setLeaving(false) });
    };

    return (
        <AppLayout title={`${p.name} · Specs`} fullBleed hideFooter>
            <WizardShell steps={PRINT_STEPS} current={2} onStep={(i) => router.visit(i === 0 ? '/app/print/new' : `/app/print/${o.id}/product`)} back={() => router.visit(`/app/print/${o.id}/product`)} next={next} nextDisabled={!!missingArt || !o.quantity || o.total_cents <= 0} nextHint={missingArt ?? undefined} nextLoading={leaving} saveState={auto.state}>
                <div className="flex items-center gap-4">
                    <ProductArt product={p} className="size-16 shrink-0 rounded-[12px]" />
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">{p.name}</h1>
                        <p className="text-muted">{p.size_label}</p>
                    </div>
                </div>
                {auto.errors._ && <Alert tone="danger" className="mt-6">{auto.errors._}</Alert>}

                <div className="mt-8 grid items-start gap-8 lg:grid-cols-[1fr_360px]">
                    <div className="space-y-7">
                        <Segment testPrefix="gsm" label="Paper weight" value={o.gsm} onChange={(v) => set({ gsm: v })} options={p.gsm_options.map((g) => ({ value: g, label: `${g}gsm` }))} />
                        <Segment
                            testPrefix="finish"
                            label="Finish"
                            value={o.finish}
                            onChange={(v) => set({ finish: v })}
                            options={['matt', 'gloss'].map((f) => ({ value: f, label: f === 'matt' ? 'Matt' : 'Gloss', disabled: !allowedFinishes.includes(f), reason: `Not available on ${o.gsm}gsm` }))}
                        />
                        <div className="grid gap-7 sm:grid-cols-2">
                            <Segment testPrefix="sides" label="Sides" value={o.sides} onChange={(v) => set({ sides: v })} options={[{ value: 1, label: 'Single-sided' }, { value: 2, label: 'Double-sided', disabled: singleOnly, reason: 'Single-sided only' }]} />
                            <Segment testPrefix="orientation" label="Orientation" value={o.orientation} onChange={(v) => set({ orientation: v })} options={[{ value: 'portrait' as const, label: 'Portrait' }, { value: 'landscape' as const, label: 'Landscape' }]} />
                        </div>
                        <Segment testPrefix="qty" label="Quantity" value={o.quantity} onChange={(v) => set({ quantity: v })} options={p.quantity_options.map((q) => ({ value: q, label: num(q), sub: tierPrices[q] ? `${tierPrices[q] < 100 ? `${(Math.round(tierPrices[q] * 10) / 10).toString()}c` : money(tierPrices[q])}/pc` : ' ' }))} />
                        {auto.errors.quantity && <p className="text-sm text-danger">{auto.errors.quantity}</p>}

                        <section className="space-y-4">
                            <h2 className="text-sm font-medium">Artwork</h2>
                            <ArtworkSlot orderId={o.id} side="front" product={p} orientation={o.orientation} artwork={front} onChange={(a) => setArt('front', a)} />
                            {o.sides === 2 && <ArtworkSlot orderId={o.id} side="back" product={p} orientation={o.orientation} artwork={back} onChange={(a) => setArt('back', a)} />}
                            <p className="text-xs text-muted">Colours on screen can differ from print (screens are RGB, print is CMYK). Keep text inside the safe zone and extend backgrounds to the bleed edge.</p>
                        </section>

                        <fieldset className="space-y-2.5">
                            <legend className="mb-1 text-sm font-medium">Add-ons</legend>
                            {addons.map((a) => (
                                <div key={a.value} className="flex items-center gap-3">
                                    <Checkbox id={`addon-${a.value}`} data-testid={`addon-${a.value}`} checked={o.addons.includes(a.value)} onCheckedChange={(v) => set({ addons: v ? [...o.addons, a.value] : o.addons.filter((x) => x !== a.value) })} />
                                    <Label htmlFor={`addon-${a.value}`} className="font-normal">
                                        {a.label} <span className="text-muted">{a.pct ? `+${a.pct}%` : a.fixed_cents ? `+${money(a.fixed_cents)} ex GST` : ''}</span>
                                    </Label>
                                </div>
                            ))}
                        </fieldset>

                        {p.distributable && (
                            <label className={cn('flex cursor-pointer items-start gap-3 rounded-[14px] border p-4 transition-colors', o.also_distribute ? 'border-fg bg-surface-2' : 'border-border')}>
                                <Checkbox data-testid="also-distribute" checked={o.also_distribute} onCheckedChange={(v) => set({ also_distribute: v === true })} className="mt-0.5" />
                                <span>
                                    <span className="flex items-center gap-2 font-medium">
                                        <Truck className="size-4" aria-hidden /> Also distribute these flyers
                                    </span>
                                    <span className="text-sm text-muted">After payment we'll create a linked distribution campaign so you can choose areas. Flyers go straight from the printer to our depot.</span>
                                </span>
                            </label>
                        )}

                        <Field label="Promo code" optional error={auto.errors.promo_code} success={o.promo_code && !auto.errors.promo_code ? `${o.promo_code.toUpperCase()} applied` : undefined}>
                            <div className="flex gap-2">
                                <Input value={promo} onChange={(e) => setPromo(e.target.value.toUpperCase())} placeholder="e.g. PRINT25" />
                                <Button variant="secondary" size="lg" className="h-11" onClick={() => auto.save({ promo_code: promo || null }, true)}>
                                    {promo ? 'Apply' : 'Clear'}
                                </Button>
                            </div>
                        </Field>
                    </div>

                    <aside className="lg:sticky lg:top-24" aria-label="Price">
                        <Card>
                            <CardBody className="space-y-4">
                                <div className="flex items-baseline justify-between">
                                    <h2 className="text-lg font-semibold">Your price</h2>
                                    {o.quantity && o.total_cents > 0 && <span className="tabular text-sm text-muted">{((o.total_cents / o.quantity) < 100 ? `${(Math.round((o.total_cents / o.quantity) * 10) / 10).toString()}c` : money(o.total_cents / o.quantity))} / pc incl. GST</span>}
                                </div>
                                <PriceBreakdown quote={quote} />
                                <div className="rounded-[12px] bg-surface-2 px-4 py-3 text-sm">
                                    <span className="text-muted">Earliest delivery</span>
                                    <span className="block font-semibold">{date(o.earliest_delivery)}</span>
                                </div>
                            </CardBody>
                        </Card>
                    </aside>
                </div>
            </WizardShell>
        </AppLayout>
    );
}
