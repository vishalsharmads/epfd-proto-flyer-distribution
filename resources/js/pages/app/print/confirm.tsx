import { AddressTypeahead } from '@/components/app/address-typeahead';
import { PaymentForm, type PaymentContext } from '@/components/app/payment-form';
import { ProductArt } from '@/components/app/print-bits';
import type { PrintOrderT } from '@/components/app/types';
import { PRINT_STEPS, PriceBreakdown, useAutosave, WizardShell } from '@/components/app/wizard';
import { Checkbox, Field, Input, Label } from '@/components/ui/form';
import { Alert, Card, CardBody, CardHeader } from '@/components/ui/surface';
import AppLayout from '@/layouts/app-layout';
import { api, date, num } from '@/lib/utils';
import { router, usePage } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

type Res = { data: PrintOrderT };

export default function PrintConfirm({ order: initial, payment, delivery_defaults }: { order: PrintOrderT; payment: PaymentContext; delivery_defaults: { name: string; address: string; phone: string } }) {
    const errors = usePage().props.errors as Record<string, string>;
    const [o, setO] = useState(initial);
    const [delivery, setDelivery] = useState({
        delivery_name: initial.delivery_name ?? delivery_defaults.name ?? '',
        delivery_address: initial.delivery_address ?? delivery_defaults.address ?? '',
        delivery_phone: initial.delivery_phone ?? delivery_defaults.phone ?? '',
    });
    const [checked, setChecked] = useState(false);
    const [rights, setRights] = useState(false);
    const send = useCallback((patch: Record<string, unknown>) => api<Res>(`/app/print/${o.id}`, { method: 'PATCH', json: patch }), [o.id]);
    const auto = useAutosave(send, useCallback((r: Res) => setO(r.data), []), 500);

    // Persist prefilled delivery details once so the server has them for payment validation.
    useEffect(() => {
        if (!initial.delivery_name || !initial.delivery_address || !initial.delivery_phone) auto.save(delivery, true);
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    const upd = (k: keyof typeof delivery, v: string) => {
        setDelivery((d) => ({ ...d, [k]: v }));
        auto.save({ [k]: v });
    };
    const deliveryValid = delivery.delivery_name.trim().length > 1 && delivery.delivery_address.trim().length > 5 && /^[\d\s()+-]{8,20}$/.test(delivery.delivery_phone) && auto.state !== 'error';
    const p = o.product!;
    const err = (k: string) => auto.errors[k] ?? errors[k];

    return (
        <AppLayout title={`${p.name} · Confirm`} fullBleed hideFooter>
            <WizardShell steps={PRINT_STEPS} current={3} onStep={(i) => router.visit(i === 0 ? '/app/print/new' : `/app/print/${o.id}/${i === 1 ? 'product' : 'specs'}`)} back={() => router.visit(`/app/print/${o.id}/specs`)} hideNext saveState={auto.state}>
                <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">Confirm & pay</h1>
                <p className="mt-1.5 text-muted">Where should we deliver your print?</p>
                <div className="mt-8 grid items-start gap-8 lg:grid-cols-[1fr_380px]">
                    <div className="space-y-6">
                        <Card>
                            <CardHeader title="Delivery details" />
                            <CardBody className="grid gap-4 sm:grid-cols-2">
                                <Field label="Contact name" error={err('delivery_name')}>
                                    <Input data-testid="delivery-name" value={delivery.delivery_name} onChange={(e) => upd('delivery_name', e.target.value)} autoComplete="name" />
                                </Field>
                                <Field label="Phone" error={err('delivery_phone')}>
                                    <Input data-testid="delivery-phone" type="tel" value={delivery.delivery_phone} onChange={(e) => upd('delivery_phone', e.target.value)} autoComplete="tel" />
                                </Field>
                                <Field label="Delivery address" error={err('delivery_address')} className="sm:col-span-2">
                                    <AddressTypeahead data-testid="delivery-address" value={delivery.delivery_address} onChange={(v) => upd('delivery_address', v)} onSelect={(h) => upd('delivery_address', h.label)} />
                                </Field>
                                {o.also_distribute && (
                                    <Alert tone="info" className="sm:col-span-2">
                                        You chose to also distribute these flyers. We'll create a linked distribution campaign after payment so you can pick your areas.
                                    </Alert>
                                )}
                            </CardBody>
                        </Card>
                        {errors.artwork && <Alert tone="danger">{errors.artwork}</Alert>}
                        <PaymentForm
                            action={`/app/print/${o.id}/pay`}
                            totalCents={o.total_cents}
                            payment={payment}
                            extraValid={checked && rights && deliveryValid}
                            extraPayload={{ artwork_checked: checked, right_to_print: rights }}
                            blockedReason={errors.artwork ?? null}
                            extraAcks={
                                <>
                                    <div className="flex items-start gap-3">
                                        <Checkbox id="artwork-checked" data-testid="artwork-checked" className="mt-0.5" checked={checked} onCheckedChange={(v) => setChecked(v === true)} aria-invalid={errors.artwork_checked ? true : undefined} />
                                        <Label htmlFor="artwork-checked" className="font-normal text-muted">I've checked my artwork and order details.</Label>
                                    </div>
                                    <div className="flex items-start gap-3">
                                        <Checkbox id="right-to-print" data-testid="right-to-print" className="mt-0.5" checked={rights} onCheckedChange={(v) => setRights(v === true)} aria-invalid={errors.right_to_print ? true : undefined} />
                                        <Label htmlFor="right-to-print" className="font-normal text-muted">I have the right to print the uploaded artwork.</Label>
                                    </div>
                                    {(errors.artwork_checked || errors.right_to_print) && <p className="text-sm text-danger">{errors.artwork_checked ?? errors.right_to_print}</p>}
                                </>
                            }
                        />
                        <p className="flex items-start gap-2 text-sm text-muted">
                            <Info className="mt-0.5 size-4 shrink-0" aria-hidden /> Production may start soon after your order is accepted, so changes may not be possible after payment.
                        </p>
                    </div>
                    <aside className="lg:sticky lg:top-24" aria-label="Order summary">
                        <Card>
                            <CardBody className="space-y-5">
                                <div className="flex items-center gap-3">
                                    <ProductArt product={p} className="size-14 shrink-0 rounded-[10px]" />
                                    <div>
                                        <h2 className="font-semibold">{p.name}</h2>
                                        <p className="text-sm text-muted">{o.reference}</p>
                                    </div>
                                </div>
                                <dl className="space-y-1.5 text-sm">
                                    {[
                                        ['Quantity', num(o.quantity)],
                                        ['Paper', `${o.gsm}gsm ${o.finish === 'gloss' ? 'Gloss' : 'Matt'}`],
                                        ['Sides', o.sides === 2 ? 'Double-sided' : 'Single-sided'],
                                        ['Orientation', o.orientation === 'landscape' ? 'Landscape' : 'Portrait'],
                                        ['Artwork', `${o.artworks.length} file${o.artworks.length === 1 ? '' : 's'}`],
                                        ['Earliest delivery', date(o.earliest_delivery)],
                                    ].map(([k, v]) => (
                                        <div key={k} className="flex justify-between gap-4">
                                            <dt className="text-muted">{k}</dt>
                                            <dd className="text-right">{v}</dd>
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
