import { AddressTypeahead } from '@/components/app/address-typeahead';
import { MapThumb } from '@/components/app/map';
import type { CampaignT, DistributionOptions } from '@/components/app/types';
import { CAMPAIGN_STEPS, PriceBreakdown, useAutosave, WizardShell } from '@/components/app/wizard';
import { Button } from '@/components/ui/button';
import { Field, Input, Textarea } from '@/components/ui/form';
import { Alert, Card, CardBody } from '@/components/ui/surface';
import AppLayout from '@/layouts/app-layout';
import { api, cn, date, money, num } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { formatInTimeZone } from 'date-fns-tz';
import { Building2, Calendar, Check, Home } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

type Res = { data: CampaignT; options: DistributionOptions };
const today = () => formatInTimeZone(new Date(), 'Australia/Sydney', 'yyyy-MM-dd');

function RadioCard({ name, value, checked, onChange, children, testId }: { name: string; value: string; checked: boolean; onChange: (v: string) => void; children: React.ReactNode; testId?: string }) {
    return (
        <label data-testid={testId} className={cn('relative flex cursor-pointer flex-col rounded-[14px] border p-3.5 transition-[border,background,box-shadow] duration-150', checked ? 'border-fg bg-surface-2 shadow-sm' : 'border-border hover:border-border-strong')}>
            <input type="radio" name={name} value={value} checked={checked} onChange={() => onChange(value)} className="sr-only" />
            {checked && <Check className="absolute top-3 right-3 size-4" aria-hidden />}
            {children}
        </label>
    );
}

export default function CampaignDetails({ campaign: initial, options: initialOptions }: { campaign: CampaignT; options: DistributionOptions }) {
    const [c, setC] = useState(initial);
    const [opts, setOpts] = useState(initialOptions);
    const [name, setName] = useState(initial.name);
    const [nameState, setNameState] = useState<{ ok: boolean; msg: string } | null>(null);
    const [pickupText, setPickupText] = useState(initial.pickup_address ?? '');
    const [pickupPicked, setPickupPicked] = useState(true);
    const [promo, setPromo] = useState(initial.promo_code ?? '');
    const [notes, setNotes] = useState(initial.notes ?? '');
    const [leaving, setLeaving] = useState(false);

    const send = useCallback((patch: Record<string, unknown>) => api<Res>(`/app/campaigns/${c.id}`, { method: 'PATCH', json: patch }), [c.id]);
    const onSaved = useCallback((r: Res) => {
        setC(r.data);
        setOpts(r.options);
    }, []);
    const auto = useAutosave(send, onSaved, 600);

    // Live name uniqueness check (debounced).
    const nameTimer = useRef<ReturnType<typeof setTimeout>>();
    useEffect(() => {
        if (name === c.name) return setNameState(null);
        if (!name.trim()) return setNameState({ ok: false, msg: 'Enter a campaign name.' });
        clearTimeout(nameTimer.current);
        nameTimer.current = setTimeout(async () => {
            try {
                const r = await api<{ available: boolean; message: string }>(`/app/campaigns/name-check?campaign_id=${c.id}&name=${encodeURIComponent(name)}`);
                setNameState({ ok: r.available, msg: r.message });
                if (r.available) auto.save({ name }, true);
            } catch {
                setNameState({ ok: false, msg: 'Could not check the name. Try again.' });
            }
        }, 350);
        return () => clearTimeout(nameTimer.current);
    }, [name]); // eslint-disable-line react-hooks/exhaustive-deps

    const set = (patch: Partial<CampaignT>, immediate = true) => {
        setC((x) => ({ ...x, ...patch }));
        auto.save(patch as Record<string, unknown>, immediate);
    };

    const nameErr = (nameState && !nameState.ok ? nameState.msg : undefined) ?? auto.errors.name;
    const errs = auto.errors;
    const valid = !nameErr && name.trim() && pickupPicked && c.pickup_address && c.available_from && c.letterboxes > 0 && !errs.pickup_address && !errs.available_from;

    const next = async () => {
        setLeaving(true);
        const ok = await auto.settle();
        if (!ok) return setLeaving(false);
        router.visit(`/app/campaigns/${c.id}/confirm`, { onFinish: () => setLeaving(false) });
    };

    const isToday = c.available_from === today();

    return (
        <AppLayout title={`${c.name} · Details`} fullBleed hideFooter>
            <WizardShell
                steps={CAMPAIGN_STEPS}
                current={2}
                onStep={(i) => router.visit(i === 0 ? '/app/campaigns/new' : `/app/campaigns/${c.id}/audience`)}
                back={() => router.visit(`/app/campaigns/${c.id}/audience`)}
                next={next}
                nextDisabled={!valid}
                nextLoading={leaving}
                saveState={auto.state}
            >
                <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">Pick up details</h1>
                <p className="mt-1.5 text-muted">Tell us where to collect and what to deliver. Changes save automatically.</p>

                {errs._ && (
                    <Alert tone="danger" className="mt-6" action={<Button size="sm" variant="secondary" onClick={auto.retry}>Retry</Button>}>
                        {errs._}
                    </Alert>
                )}

                <div className="mt-8 grid items-start gap-8 lg:grid-cols-[1fr_380px]">
                    <div className="space-y-8">
                        <Field label={<>Campaign name <span className="font-normal text-subtle">Must be unique</span></>} error={nameErr} success={nameState?.ok ? nameState.msg : undefined}>
                            <Input data-testid="campaign-name" value={name} maxLength={80} onChange={(e) => setName(e.target.value)} />
                        </Field>

                        <fieldset className="space-y-3">
                            <legend className="text-sm font-medium">
                                Collection <span className="font-normal text-subtle">When your job goes live and pick-up location</span>
                            </legend>
                            <Field label={<span className="sr-only">Pickup address</span>} error={errs.pickup_address ?? (!pickupPicked ? 'Choose the pickup address from the list.' : undefined)} hint={`Every area must be within 20 km of the pickup.`}>
                                <AddressTypeahead
                                    data-testid="pickup-address"
                                    placeholder="Pickup address"
                                    value={pickupText}
                                    onChange={(v) => {
                                        setPickupText(v);
                                        setPickupPicked(false);
                                    }}
                                    onSelect={(h) => {
                                        setPickupText(h.label);
                                        setPickupPicked(true);
                                        setC((x) => ({ ...x, pickup_address: h.label }));
                                        auto.save({ pickup_address: h.label, pickup_lng: h.location[0], pickup_lat: h.location[1] }, true);
                                    }}
                                />
                            </Field>
                            <Field label="Available from" htmlFor="available-from" error={errs.available_from} hint={isToday ? 'Today — earliest available' : `Flyers ready for collection from ${date(c.available_from)}`}>
                                <div className="relative">
                                    <Calendar className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-subtle" aria-hidden />
                                    <Input id="available-from" aria-invalid={errs.available_from ? true : undefined} aria-describedby={errs.available_from ? 'available-from-error' : 'available-from-hint'} type="date" data-testid="available-from" className="pl-10" min={today()} value={c.available_from ?? ''} onChange={(e) => e.target.value && set({ available_from: e.target.value })} />
                                </div>
                            </Field>
                        </fieldset>

                        <fieldset>
                            <legend className="mb-3 text-sm font-medium">Material type</legend>
                            <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                                {opts.materials.map((m) => (
                                    <RadioCard key={m.value} testId={`material-${m.value}`} name="material" value={m.value} checked={c.material === m.value} onChange={(v) => set({ material: v })}>
                                        <span className="text-sm font-medium">{m.label}</span>
                                        <span className="text-xs text-muted">{m.add_cents > 0 ? `+$${(m.add_cents / 100).toFixed(2)} / item` : 'Base price'}</span>
                                    </RadioCard>
                                ))}
                            </div>
                        </fieldset>

                        <fieldset>
                            <legend className="mb-3 text-sm font-medium">Coverage</legend>
                            <div className="grid gap-2.5 sm:grid-cols-3">
                                {opts.coverages.map((cv) => (
                                    <RadioCard key={cv.value} testId={`coverage-${cv.value}`} name="coverage" value={cv.value} checked={c.coverage === cv.value} onChange={(v) => set({ coverage: v })}>
                                        <span className="flex items-center gap-1.5 text-sm font-medium">
                                            {cv.value === 'units_only' ? <Building2 className="size-4" aria-hidden /> : <Home className="size-4" aria-hidden />}
                                            {cv.label}
                                        </span>
                                        <span className="tabular mt-1 text-xs text-muted">~${(cv.per_item_incl_gst_cents / 100).toFixed(3)} / item incl. GST</span>
                                        <span className="tabular text-xs text-muted">{num(cv.letterboxes)} letterboxes (est.)</span>
                                    </RadioCard>
                                ))}
                            </div>
                            {errs.coverage && <p className="mt-2 text-sm text-danger">{errs.coverage}</p>}
                        </fieldset>

                        <Field label="Notes for the distributors" optional error={errs.notes}>
                            <Textarea
                                data-testid="campaign-notes"
                                maxLength={1000}
                                placeholder="e.g. Skip letterboxes with 'No junk mail' signs. Please fold flyers in half."
                                value={notes}
                                onChange={(e) => {
                                    setNotes(e.target.value);
                                    auto.save({ notes: e.target.value });
                                }}
                            />
                        </Field>

                        <Field label="Promo code" optional error={errs.promo_code} success={c.promo_code && !errs.promo_code ? `${c.promo_code.toUpperCase()} applied` : undefined}>
                            <div className="flex gap-2">
                                <Input data-testid="promo-code" value={promo} onChange={(e) => setPromo(e.target.value.toUpperCase())} placeholder="e.g. WELCOME10" />
                                <Button variant="secondary" size="lg" className="h-11" onClick={() => auto.save({ promo_code: promo || null }, true)}>
                                    {promo ? 'Apply' : 'Clear'}
                                </Button>
                            </div>
                        </Field>
                    </div>

                    <aside className="lg:sticky lg:top-24" aria-label="Campaign summary">
                        <Card>
                            <CardBody className="space-y-5">
                                <h2 className="text-lg font-semibold">Campaign Summary</h2>
                                <div className="aspect-[16/10] w-full overflow-hidden rounded-[12px] bg-surface-2">
                                    <MapThumb polygons={c.areas.map((a) => a.geometry)} />
                                </div>
                                <div>
                                    <h3 className="mb-2 text-sm font-semibold">Audience breakdown <span className="font-normal text-subtle">(estimated)</span></h3>
                                    <dl className="space-y-1.5 text-sm">
                                        <div className="flex justify-between"><dt className="flex items-center gap-2 text-muted"><Home className="size-4" aria-hidden /> Houses</dt><dd className="tabular">{num(c.houses)}</dd></div>
                                        <div className="flex justify-between"><dt className="flex items-center gap-2 text-muted"><Building2 className="size-4" aria-hidden /> Units</dt><dd className="tabular">{num(c.units)}</dd></div>
                                        <div className="flex justify-between border-t border-border pt-1.5"><dt className="text-muted">Billable letterboxes</dt><dd className="tabular font-medium">{num(c.letterboxes)}</dd></div>
                                        <div className="flex justify-between"><dt className="text-muted">Areas</dt><dd className="tabular">{c.areas.length}</dd></div>
                                    </dl>
                                </div>
                                <PriceBreakdown quote={c.pricing} totalLabel="Distribution fee incl. GST" />
                                {c.total_cents > 0 && <p className="text-xs text-subtle">≈ {money(Math.round(c.total_cents / Math.max(1, c.letterboxes) * 100) / 100)} per letterbox incl. GST</p>}
                            </CardBody>
                        </Card>
                    </aside>
                </div>
            </WizardShell>
        </AppLayout>
    );
}
