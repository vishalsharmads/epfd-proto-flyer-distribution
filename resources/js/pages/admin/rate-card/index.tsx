import { ConfirmDialog, Table, Td, Th, THead, Tr, useAction } from '@/components/admin/kit';
import { Button } from '@/components/ui/button';
import { Checkbox, Field, Input, Label, Select, Switch } from '@/components/ui/form';
import { Tabs } from '@/components/ui/navigation';
import { Dialog, DialogContent } from '@/components/ui/overlay';
import { Alert, Badge, Card, CardBody, CardHeader, EmptyState, ErrorState, Skeleton, StatusBadge } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { api, ApiError, date, dateTime, money, num, perItem, titleCase } from '@/lib/utils';
import type { Quote } from '@/types';
import { router, useForm } from '@inertiajs/react';
import { Calculator, History, Percent, Plus, Save, Tag, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

type Money = number | string;
interface CardData {
    gst_rate: Money;
    distribution: {
        coverage: Record<string, { label: string; per_item: Money }>;
        materials: Record<string, { label: string; add: Money }>;
        quantity_bands: { min: Money; discount_pct: Money }[];
        minimum_fee: Money;
        distributor_pay_per_item: Money;
    };
    print: {
        minimum_order: Money;
        turnaround_days: Money;
        finishes: Record<string, { label: string; pct: Money }>;
        addons: Record<string, { label: string; pct?: Money | null; fixed?: Money | null }>;
    };
    design: {
        double_sided_pct: Money;
        rush_turnaround_days: Money;
        types: Record<string, { label: string; sides_option: boolean; prices: Record<string, Money>; description?: string; icon?: string; print_categories?: string[]; default_product?: string | null }>;
        packages: Record<string, { label: string; description?: string; revisions: Money; turnaround_days: Money }>;
        addons: Record<string, { label: string; pct?: Money | null; fixed?: Money | null }>;
    };
}
interface Product {
    id: number;
    name: string;
    slug: string;
    gsm_options: number[];
    quantity_options: number[];
    finish_options: string[];
    is_active: boolean;
}
interface Option {
    id: number;
    gsm: number;
    sides: number;
    quantity: number;
    unit_price_hc: number | null;
}
interface Promo {
    id: number;
    code: string;
    description: string | null;
    type: 'percent' | 'fixed';
    value: number;
    service: string;
    min_subtotal_cents: number;
    starts_at: string | null;
    ends_at: string | null;
    max_uses: number | null;
    uses: number;
    is_active: boolean;
}
interface Props {
    card: { id: number | null; data: CardData };
    history: { id: number; name: string; is_active: boolean; created_at: string; creator_name: string | null }[];
    products: Product[];
    matrix: { product_id: number; options: Option[] } | null;
    promos: Promo[];
    tab: string;
    categories?: { slug: string; name: string }[];
    icons?: string[];
}

function setIn<T>(obj: T, path: (string | number)[], value: unknown): T {
    if (path.length === 0) return value as T;
    const [k, ...rest] = path;
    const clone: Record<string | number, unknown> = Array.isArray(obj) ? [...(obj as unknown[])] as unknown as Record<number, unknown> : { ...(obj as Record<string, unknown>) };
    clone[k] = setIn((obj as Record<string | number, unknown>)[k], rest, value);
    return clone as T;
}

export default function RateCardPage(props: Props) {
    const [tab, setTabState] = useState(props.tab);
    const setTab = (t: string) => {
        setTabState(t);
        const u = new URL(window.location.href);
        u.searchParams.set('tab', t);
        window.history.replaceState(window.history.state, '', u);
    };
    const [draft, setDraft] = useState<CardData>(props.card.data);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [confirmSave, setConfirmSave] = useState(false);
    const dirty = useMemo(() => JSON.stringify(draft) !== JSON.stringify(props.card.data), [draft, props.card.data]);
    useEffect(() => setDraft(props.card.data), [props.card.data]);
    const set = (path: (string | number)[], v: unknown) => setDraft((d) => setIn(d, path, v));
    const err = (path: string) => errors[`data.${path}`];

    const save = (name: string) => {
        setSaving(true);
        router.post('/admin/rate-card', { name: name || null, data: draft } as never, {
            preserveScroll: true,
            onError: (e) => setErrors(e),
            onSuccess: () => (setErrors({}), setConfirmSave(false)),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <AdminLayout
            title="Rate card & promos"
            actions={
                ['distribution', 'print', 'design'].includes(tab) && (
                    <>
                        {dirty && (
                            <Button variant="ghost" onClick={() => (setDraft(props.card.data), setErrors({}))}>
                                Discard changes
                            </Button>
                        )}
                        <Button onClick={() => setConfirmSave(true)} disabled={!dirty} data-testid="save-rate-card">
                            <Save /> Save new version
                        </Button>
                    </>
                )
            }
        >
            <Tabs
                value={tab}
                onChange={setTab}
                tabs={[
                    { value: 'distribution', label: 'Distribution' },
                    { value: 'print', label: 'Print' },
                    { value: 'design', label: 'Design' },
                    { value: 'matrix', label: 'Print price matrix' },
                    { value: 'promos', label: 'Promo codes', count: props.promos.length },
                    { value: 'history', label: 'History', count: props.history.length },
                ]}
            />
            {dirty && ['distribution', 'print', 'design'].includes(tab) && (
                <Alert tone="warning" className="mb-6" title="Unsaved changes">
                    The preview uses your draft. Saving creates a new rate card version and activates it immediately for new quotes.
                </Alert>
            )}
            {Object.keys(errors).length > 0 && (
                <Alert tone="danger" className="mb-6" title="Please fix the highlighted fields">
                    {Object.values(errors)[0]}
                </Alert>
            )}

            {['distribution', 'print', 'design'].includes(tab) && (
                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_400px]">
                    <div className="min-w-0 space-y-6">{tab === 'distribution' ? <DistributionEditor d={draft} set={set} err={err} /> : tab === 'print' ? <PrintEditor d={draft} set={set} err={err} /> : <DesignEditor d={draft} set={set} err={err} categories={props.categories ?? []} products={props.products} icons={props.icons ?? []} />}</div>
                    <PreviewCalculator key={tab === 'design' ? 'design' : 'other'} draft={draft} products={props.products} initialService={tab === 'design' ? 'design' : tab === 'print' ? 'print' : 'distribution'} />
                </div>
            )}
            {tab === 'matrix' && <MatrixEditor products={props.products} matrix={props.matrix} />}
            {tab === 'promos' && <Promos promos={props.promos} />}
            {tab === 'history' && <RateHistory history={props.history} />}

            <SaveDialog open={confirmSave} onOpenChange={setConfirmSave} onSave={save} saving={saving} />
        </AdminLayout>
    );
}

function SaveDialog({ open, onOpenChange, onSave, saving }: { open: boolean; onOpenChange: (o: boolean) => void; onSave: (name: string) => void; saving: boolean }) {
    const [name, setName] = useState('');
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent title="Save a new rate card version?" description="The current version is kept in history and can be re-activated. Existing paid orders keep their prices.">
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        onSave(name);
                    }}
                >
                    <Field label="Version name" optional hint="Defaults to today's date and time.">
                        <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. FY27 pricing" />
                    </Field>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={saving} data-testid="save-rate-card-confirm">
                            Save & activate
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

type EditorProps = { d: CardData; set: (p: (string | number)[], v: unknown) => void; err: (p: string) => string | undefined };

function NumInput({ value, onChange, step = '0.001', prefix, suffix, label, error }: { value: Money; onChange: (v: string) => void; step?: string; prefix?: string; suffix?: string; label: string; error?: string }) {
    return (
        <div>
            <div className="relative">
                {prefix && <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-subtle">{prefix}</span>}
                <Input aria-label={label} aria-invalid={error ? true : undefined} type="number" inputMode="decimal" step={step} value={String(value ?? '')} onChange={(e) => onChange(e.target.value)} className={`tabular ${prefix ? (prefix.length > 1 ? "pl-9" : "pl-7") : ""} ${suffix ? 'pr-10' : ''}`} />
                {suffix && <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-subtle">{suffix}</span>}
            </div>
            {error && <p className="mt-1 text-xs text-danger" role="alert">{error}</p>}
        </div>
    );
}

function DistributionEditor({ d, set, err }: EditorProps) {
    const dist = d.distribution;
    return (
        <>
            <Card>
                <CardHeader title="Price per letterbox by coverage" description="Ex GST, AUD per item" />
                <CardBody className="grid gap-4 sm:grid-cols-3">
                    {Object.entries(dist.coverage).map(([k, c]) => (
                        <Field key={k} label={c.label}>
                            <NumInput label={c.label} prefix="$" value={c.per_item} onChange={(v) => set(['distribution', 'coverage', k, 'per_item'], v)} error={err(`distribution.coverage.${k}.per_item`)} />
                        </Field>
                    ))}
                </CardBody>
            </Card>
            <Card>
                <CardHeader title="Material add-ons" description="Added per item, ex GST" />
                <CardBody className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {Object.entries(dist.materials).map(([k, m]) => (
                        <Field key={k} label={m.label}>
                            <NumInput label={`${m.label} add-on`} prefix="+$" value={m.add} onChange={(v) => set(['distribution', 'materials', k, 'add'], v)} error={err(`distribution.materials.${k}.add`)} />
                        </Field>
                    ))}
                </CardBody>
            </Card>
            <Card>
                <CardHeader
                    title="Quantity bands"
                    description="Volume discount applied when letterboxes ≥ minimum"
                    action={
                        <Button size="sm" variant="secondary" onClick={() => set(['distribution', 'quantity_bands'], [...dist.quantity_bands, { min: '', discount_pct: '' }])}>
                            <Plus /> Add band
                        </Button>
                    }
                />
                <CardBody className="space-y-3">
                    {dist.quantity_bands.map((b, i) => (
                        <div key={i} className="grid grid-cols-[1fr_1fr_auto] items-start gap-3">
                            <Field label={i === 0 ? 'Minimum letterboxes' : undefined}>
                                <NumInput label={`Band ${i + 1} minimum`} step="1" value={b.min} onChange={(v) => set(['distribution', 'quantity_bands', i, 'min'], v)} error={err(`distribution.quantity_bands.${i}.min`)} />
                            </Field>
                            <Field label={i === 0 ? 'Discount' : undefined}>
                                <NumInput label={`Band ${i + 1} discount`} step="0.5" suffix="%" value={b.discount_pct} onChange={(v) => set(['distribution', 'quantity_bands', i, 'discount_pct'], v)} error={err(`distribution.quantity_bands.${i}.discount_pct`)} />
                            </Field>
                            <Button size="icon" variant="ghost" className={i === 0 ? 'mt-6' : ''} aria-label={`Remove band ${i + 1}`} disabled={dist.quantity_bands.length <= 1} onClick={() => set(['distribution', 'quantity_bands'], dist.quantity_bands.filter((_, j) => j !== i))}>
                                <Trash2 />
                            </Button>
                        </div>
                    ))}
                </CardBody>
            </Card>
            <Card>
                <CardHeader title="Fees, pay & tax" />
                <CardBody className="grid gap-4 sm:grid-cols-3">
                    <Field label="Minimum distribution fee" hint="Ex GST, per campaign">
                        <NumInput label="Minimum fee" prefix="$" step="0.01" value={dist.minimum_fee} onChange={(v) => set(['distribution', 'minimum_fee'], v)} error={err('distribution.minimum_fee')} />
                    </Field>
                    <Field label="Distributor pay per item" hint="Default run pay = flyers × this">
                        <NumInput label="Distributor pay per item" prefix="$" value={dist.distributor_pay_per_item} onChange={(v) => set(['distribution', 'distributor_pay_per_item'], v)} error={err('distribution.distributor_pay_per_item')} />
                    </Field>
                    <Field label="GST rate" hint="0.10 = 10%">
                        <NumInput label="GST rate" step="0.01" value={d.gst_rate} onChange={(v) => set(['gst_rate'], v)} error={err('gst_rate')} />
                    </Field>
                </CardBody>
            </Card>
        </>
    );
}

function PrintEditor({ d, set, err }: EditorProps) {
    const p = d.print;
    const [newAddon, setNewAddon] = useState('');
    return (
        <>
            <Card>
                <CardHeader title="Print order settings" />
                <CardBody className="grid gap-4 sm:grid-cols-3">
                    <Field label="Minimum print order" hint="Ex GST">
                        <NumInput label="Minimum print order" prefix="$" step="0.01" value={p.minimum_order} onChange={(v) => set(['print', 'minimum_order'], v)} error={err('print.minimum_order')} />
                    </Field>
                    <Field label="Turnaround" hint="Business days">
                        <NumInput label="Turnaround days" step="1" suffix="days" value={p.turnaround_days} onChange={(v) => set(['print', 'turnaround_days'], v)} error={err('print.turnaround_days')} />
                    </Field>
                    <Field label="GST rate">
                        <NumInput label="GST rate" step="0.01" value={d.gst_rate} onChange={(v) => set(['gst_rate'], v)} error={err('gst_rate')} />
                    </Field>
                </CardBody>
            </Card>
            <Card>
                <CardHeader title="Finishes" description="Percentage applied to the matrix unit price" />
                <CardBody className="grid gap-4 sm:grid-cols-3">
                    {Object.entries(p.finishes).map(([k, f]) => (
                        <Field key={k} label={f.label}>
                            <NumInput label={`${f.label} finish`} step="0.5" suffix="%" value={f.pct} onChange={(v) => set(['print', 'finishes', k, 'pct'], v)} error={err(`print.finishes.${k}.pct`)} />
                        </Field>
                    ))}
                </CardBody>
            </Card>
            <Card>
                <CardHeader title="Add-ons" description="Either a % of the print line or a fixed amount (ex GST)" />
                <CardBody className="space-y-4">
                    {Object.entries(p.addons).map(([k, a]) => {
                        const isPct = a.pct !== undefined && a.pct !== null;
                        return (
                            <div key={k} className="grid items-end gap-3 sm:grid-cols-[1.4fr_120px_1fr_auto]">
                                <Field label="Label">
                                    <Input value={a.label} onChange={(e) => set(['print', 'addons', k, 'label'], e.target.value)} />
                                </Field>
                                <Field label="Type">
                                    <Select value={isPct ? 'pct' : 'fixed'} onChange={(e) => set(['print', 'addons', k], e.target.value === 'pct' ? { label: a.label, pct: 0 } : { label: a.label, fixed: 0 })}>
                                        <option value="pct">Percent</option>
                                        <option value="fixed">Fixed</option>
                                    </Select>
                                </Field>
                                <Field label="Value">
                                    {isPct ? (
                                        <NumInput label={`${a.label} percent`} step="0.5" suffix="%" value={a.pct ?? ''} onChange={(v) => set(['print', 'addons', k, 'pct'], v)} error={err(`print.addons.${k}.pct`)} />
                                    ) : (
                                        <NumInput label={`${a.label} amount`} prefix="$" step="0.01" value={a.fixed ?? ''} onChange={(v) => set(['print', 'addons', k, 'fixed'], v)} error={err(`print.addons.${k}.fixed`)} />
                                    )}
                                </Field>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    aria-label={`Remove ${a.label}`}
                                    onClick={() => {
                                        const next = { ...p.addons };
                                        delete next[k];
                                        set(['print', 'addons'], next);
                                    }}
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        );
                    })}
                    <form
                        className="flex gap-2 border-t border-border pt-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            const key = newAddon.trim().toLowerCase().replace(/[^a-z0-9]+/g, '_');
                            if (!key || p.addons[key]) return;
                            set(['print', 'addons', key], { label: newAddon.trim(), fixed: 0 });
                            setNewAddon('');
                        }}
                    >
                        <Input aria-label="New add-on name" placeholder="New add-on name, e.g. Rounded corners" value={newAddon} onChange={(e) => setNewAddon(e.target.value)} />
                        <Button type="submit" variant="secondary" disabled={!newAddon.trim()}>
                            <Plus /> Add
                        </Button>
                    </form>
                </CardBody>
            </Card>
        </>
    );
}

const slugKey = (label: string) =>
    label
        .trim()
        .toLowerCase()
        .replace(/\s+design$/, '')
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_|_$/g, '')
        .slice(0, 40);

function DesignEditor({
    d,
    set,
    err,
    categories,
    products,
    icons,
}: EditorProps & { categories: { slug: string; name: string }[]; products: Product[]; icons: string[] }) {
    const g = d.design;
    const pkgs = Object.keys(g.packages);
    const [newAddon, setNewAddon] = useState('');
    const [newType, setNewType] = useState('');
    const [newPkg, setNewPkg] = useState('');
    const addType = () => {
        const key = slugKey(newType);
        if (!key || g.types[key]) return;
        const label = /design$/i.test(newType.trim()) ? newType.trim() : `${newType.trim()} design`;
        set(['design', 'types', key], {
            label,
            sides_option: false,
            icon: 'palette',
            description: '',
            print_categories: [],
            default_product: null,
            prices: Object.fromEntries(pkgs.map((p) => [p, ''])),
        });
        setNewType('');
    };
    const removeType = (k: string) => {
        const next = { ...g.types };
        delete next[k];
        set(['design', 'types'], next);
    };
    const addPkg = () => {
        const key = slugKey(newPkg);
        if (!key || g.packages[key]) return;
        set(['design'], {
            ...g,
            packages: { ...g.packages, [key]: { label: newPkg.trim(), description: '', revisions: 1, turnaround_days: 5 } },
            types: Object.fromEntries(Object.entries(g.types).map(([k, t]) => [k, { ...t, prices: { ...t.prices, [key]: '' } }])),
        });
        setNewPkg('');
    };
    const removePkg = (key: string) => {
        const packages = { ...g.packages };
        delete packages[key];
        set(['design'], {
            ...g,
            packages,
            types: Object.fromEntries(
                Object.entries(g.types).map(([k, t]) => [
                    k,
                    { ...t, prices: Object.fromEntries(Object.entries(t.prices).filter(([p]) => p !== key)) },
                ]),
            ),
        });
    };
    return (
        <>
            <Alert tone="warning" title="Placeholder prices">
                Design Studio prices are prototype placeholders (ex GST) — confirm EPFD's real design rates before launch.
            </Alert>
            {err('design.types') && <Alert tone="danger">{err('design.types')}</Alert>}
            <Card>
                <CardHeader
                    title="Design services"
                    description="What customers can order in Design Studio. Prices are ex GST, AUD per design; the double-sided uplift applies to services with a sides option."
                />
                <CardBody className="space-y-6">
                    {Object.entries(g.types).map(([k, t]) => {
                        const linked = t.print_categories ?? [];
                        return (
                            <div key={k} className="space-y-3 rounded-[12px] border border-border p-4" data-testid={`design-type-${k}`}>
                                <div className="grid items-end gap-3 sm:grid-cols-[1.6fr_1fr_auto_auto]">
                                    <Field label="Service name" error={err(`design.types.${k}.label`)}>
                                        <Input value={t.label} onChange={(e) => set(['design', 'types', k, 'label'], e.target.value)} />
                                    </Field>
                                    <Field label="Icon">
                                        <Select value={t.icon ?? 'palette'} onChange={(e) => set(['design', 'types', k, 'icon'], e.target.value)}>
                                            {icons.map((i) => (
                                                <option key={i} value={i}>
                                                    {i}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                    <label className="flex h-11 items-center gap-2 text-sm">
                                        <Switch
                                            checked={!!t.sides_option}
                                            onCheckedChange={(v) => set(['design', 'types', k, 'sides_option'], v)}
                                            aria-label={`${t.label} offers single/double-sided`}
                                        />
                                        Sides option
                                    </label>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        aria-label={`Remove ${t.label}`}
                                        disabled={Object.keys(g.types).length <= 1}
                                        onClick={() => removeType(k)}
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                                <div className="grid gap-3" style={{ gridTemplateColumns: `repeat(auto-fit, minmax(120px, 1fr))` }}>
                                    {pkgs.map((p) => (
                                        <Field key={p} label={g.packages[p].label}>
                                            <NumInput
                                                label={`${t.label} ${g.packages[p].label} price`}
                                                prefix="$"
                                                step="1"
                                                value={t.prices[p] ?? ''}
                                                onChange={(v) => set(['design', 'types', k, 'prices', p], v)}
                                                error={err(`design.types.${k}.prices.${p}`)}
                                            />
                                        </Field>
                                    ))}
                                </div>
                                <Field label="Description" hint="Shown on the service card" error={err(`design.types.${k}.description`)}>
                                    <Input
                                        value={t.description ?? ''}
                                        maxLength={255}
                                        onChange={(e) => set(['design', 'types', k, 'description'], e.target.value)}
                                    />
                                </Field>
                                <div className="grid gap-3 sm:grid-cols-[1.6fr_1fr]">
                                    <fieldset>
                                        <legend className="mb-1.5 text-sm font-medium">Linked print categories</legend>
                                        <p className="mb-2 text-xs text-muted">
                                            “Get it designed” on these products opens this service; their sizes are offered as print sizes.
                                        </p>
                                        <div className="flex flex-wrap gap-x-4 gap-y-2 text-sm">
                                            {categories.map((c) => {
                                                const id = `link-${k}-${c.slug}`;
                                                return (
                                                    <span key={c.slug} className="inline-flex items-center gap-2">
                                                        <Checkbox
                                                            id={id}
                                                            checked={linked.includes(c.slug)}
                                                            onCheckedChange={(v) =>
                                                                set(
                                                                    ['design', 'types', k, 'print_categories'],
                                                                    v === true ? [...linked, c.slug] : linked.filter((x) => x !== c.slug),
                                                                )
                                                            }
                                                        />
                                                        <Label htmlFor={id}>{c.name}</Label>
                                                    </span>
                                                );
                                            })}
                                        </div>
                                    </fieldset>
                                    <Field
                                        label="Default print product"
                                        optional
                                        hint="Used by “Print this design” when no size was chosen"
                                        error={err(`design.types.${k}.default_product`)}
                                    >
                                        <Select
                                            value={t.default_product ?? ''}
                                            onChange={(e) => set(['design', 'types', k, 'default_product'], e.target.value || null)}
                                        >
                                            <option value="">None</option>
                                            {products.map((p) => (
                                                <option key={p.slug} value={p.slug}>
                                                    {p.name}
                                                    {p.is_active ? '' : ' (hidden)'}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                </div>
                            </div>
                        );
                    })}
                    <form
                        className="flex gap-2 border-t border-border pt-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            addType();
                        }}
                    >
                        <Input
                            aria-label="New design service name"
                            placeholder="New service, e.g. Logo design"
                            value={newType}
                            onChange={(e) => setNewType(e.target.value)}
                        />
                        <Button type="submit" variant="secondary" disabled={!slugKey(newType) || !!g.types[slugKey(newType)]}>
                            <Plus /> Add service
                        </Button>
                    </form>
                    <p className="text-xs text-muted">Removing a service stops new orders for it; existing orders keep their details.</p>
                </CardBody>
            </Card>
            <Card>
                <CardHeader
                    title="Packages"
                    description="Revisions included (99 = unlimited) and business days to first proof. Every service needs a price for every package."
                />
                <CardBody className="space-y-4">
                    {err('design.packages') && <p className="text-sm text-danger">{err('design.packages')}</p>}
                    {pkgs.map((p) => {
                        const pk = g.packages[p];
                        return (
                            <div key={p} className="grid items-start gap-3 sm:grid-cols-[1fr_120px_140px_auto]">
                                <Field label="Label" error={err(`design.packages.${p}.label`)}>
                                    <Input value={pk.label} onChange={(e) => set(['design', 'packages', p, 'label'], e.target.value)} />
                                </Field>
                                <Field label="Revisions">
                                    <NumInput
                                        label={`${pk.label} revisions`}
                                        step="1"
                                        value={pk.revisions}
                                        onChange={(v) => set(['design', 'packages', p, 'revisions'], v)}
                                        error={err(`design.packages.${p}.revisions`)}
                                    />
                                </Field>
                                <Field label="Turnaround">
                                    <NumInput
                                        label={`${pk.label} turnaround days`}
                                        step="1"
                                        suffix="days"
                                        value={pk.turnaround_days}
                                        onChange={(v) => set(['design', 'packages', p, 'turnaround_days'], v)}
                                        error={err(`design.packages.${p}.turnaround_days`)}
                                    />
                                </Field>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    className="sm:mt-7"
                                    aria-label={`Remove ${pk.label} package`}
                                    disabled={pkgs.length <= 1}
                                    onClick={() => removePkg(p)}
                                >
                                    <Trash2 />
                                </Button>
                                <Field label="Description" className="sm:col-span-4">
                                    <Input
                                        value={pk.description ?? ''}
                                        onChange={(e) => set(['design', 'packages', p, 'description'], e.target.value)}
                                    />
                                </Field>
                            </div>
                        );
                    })}
                    <form
                        className="flex gap-2 border-t border-border pt-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            addPkg();
                        }}
                    >
                        <Input
                            aria-label="New package name"
                            placeholder="New package, e.g. Express"
                            value={newPkg}
                            onChange={(e) => setNewPkg(e.target.value)}
                        />
                        <Button type="submit" variant="secondary" disabled={!slugKey(newPkg) || !!g.packages[slugKey(newPkg)]}>
                            <Plus /> Add package
                        </Button>
                    </form>
                </CardBody>
            </Card>
            <Card>
                <CardHeader title="Settings" />
                <CardBody className="grid gap-4 sm:grid-cols-2">
                    <Field label="Double-sided uplift" hint="Added to the package price">
                        <NumInput label="Double-sided uplift" step="1" suffix="%" value={g.double_sided_pct} onChange={(v) => set(['design', 'double_sided_pct'], v)} error={err('design.double_sided_pct')} />
                    </Field>
                    <Field label="Rush turnaround" hint="Business days to first proof with the rush add-on">
                        <NumInput label="Rush turnaround days" step="1" suffix="days" value={g.rush_turnaround_days} onChange={(v) => set(['design', 'rush_turnaround_days'], v)} error={err('design.rush_turnaround_days')} />
                    </Field>
                </CardBody>
            </Card>
            <Card>
                <CardHeader title="Design add-ons" description="Either a % of the design line or a fixed amount (ex GST). “extra_revision” is also the price of revisions beyond a package." />
                <CardBody className="space-y-4">
                    {Object.entries(g.addons).map(([k, a]) => {
                        const isPct = a.pct !== undefined && a.pct !== null;
                        return (
                            <div key={k} className="grid items-end gap-3 sm:grid-cols-[1.4fr_120px_1fr_auto]">
                                <Field label="Label">
                                    <Input value={a.label} onChange={(e) => set(['design', 'addons', k, 'label'], e.target.value)} />
                                </Field>
                                <Field label="Type">
                                    <Select value={isPct ? 'pct' : 'fixed'} onChange={(e) => set(['design', 'addons', k], e.target.value === 'pct' ? { label: a.label, pct: 0 } : { label: a.label, fixed: 0 })}>
                                        <option value="pct">Percent</option>
                                        <option value="fixed">Fixed</option>
                                    </Select>
                                </Field>
                                <Field label="Value">
                                    {isPct ? (
                                        <NumInput label={`${a.label} percent`} step="0.5" suffix="%" value={a.pct ?? ''} onChange={(v) => set(['design', 'addons', k, 'pct'], v)} error={err(`design.addons.${k}.pct`)} />
                                    ) : (
                                        <NumInput label={`${a.label} amount`} prefix="$" step="0.01" value={a.fixed ?? ''} onChange={(v) => set(['design', 'addons', k, 'fixed'], v)} error={err(`design.addons.${k}.fixed`)} />
                                    )}
                                </Field>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    aria-label={`Remove ${a.label}`}
                                    disabled={k === 'extra_revision'}
                                    onClick={() => {
                                        const next = { ...g.addons };
                                        delete next[k];
                                        set(['design', 'addons'], next);
                                    }}
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        );
                    })}
                    <form
                        className="flex gap-2 border-t border-border pt-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            const key = newAddon.trim().toLowerCase().replace(/[^a-z0-9]+/g, '_');
                            if (!key || g.addons[key]) return;
                            set(['design', 'addons', key], { label: newAddon.trim(), fixed: 0 });
                            setNewAddon('');
                        }}
                    >
                        <Input aria-label="New design add-on name" placeholder="New add-on name, e.g. Logo refresh" value={newAddon} onChange={(e) => setNewAddon(e.target.value)} />
                        <Button type="submit" variant="secondary" disabled={!newAddon.trim()}>
                            <Plus /> Add
                        </Button>
                    </form>
                </CardBody>
            </Card>
        </>
    );
}

/* ---------------------------------------------------------------- preview */

function PreviewCalculator({ draft, products, initialService = 'distribution' }: { draft: CardData; products: Product[]; initialService?: 'distribution' | 'print' | 'design' }) {
    const [svc, setSvc] = useState<'distribution' | 'print' | 'design'>(initialService);
    const [dz, setDz] = useState({ design_type: Object.keys(draft.design.types)[0] ?? '', package: 'standard' in draft.design.packages ? 'standard' : (Object.keys(draft.design.packages)[0] ?? ''), sides: 1, addons: [] as string[] });
    const [dist, setDist] = useState({ coverage: 'houses_units', material: 'standard_dl', houses: '4000', units: '1200' });
    const firstProduct = products.find((p) => p.is_active) ?? products[0];
    const [pr, setPr] = useState({ product_id: firstProduct?.id ?? 0, gsm: firstProduct?.gsm_options[0] ?? 0, sides: 1, quantity: firstProduct?.quantity_options[0] ?? 0, finish: 'matt', addons: [] as string[] });
    const [promo, setPromo] = useState('');
    const [state, setState] = useState<{ loading: boolean; quote: Quote | null; error: string | null }>({ loading: false, quote: null, error: null });
    const [nonce, setNonce] = useState(0);
    const product = products.find((p) => p.id === pr.product_id);

    const input =
        svc === 'distribution'
            ? { service: svc, ...dist, houses: Number(dist.houses || 0), units: Number(dist.units || 0), promo: promo || null }
            : svc === 'design'
              ? { service: svc, ...dz, promo: promo || null }
              : { service: svc, ...pr, promo: promo || null };
    const key = JSON.stringify([draft, input, nonce]);
    useEffect(() => {
        const ctl = { cancelled: false };
        setState((s) => ({ ...s, loading: true, error: null }));
        const t = setTimeout(async () => {
            try {
                const res = await api<{ quote: Quote }>('/admin/rate-card/preview', { json: { data: draft, input } });
                if (!ctl.cancelled) setState({ loading: false, quote: res.quote, error: null });
            } catch (e) {
                const msg = e instanceof ApiError ? ((e.problem as { message?: string; detail?: string } | null)?.message ?? (e.problem as { detail?: string } | null)?.detail ?? 'Preview failed.') : 'Preview failed — check your connection.';
                if (!ctl.cancelled) setState({ loading: false, quote: null, error: msg });
            }
        }, 300);
        return () => {
            ctl.cancelled = true;
            clearTimeout(t);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);

    return (
        <Card className="h-fit xl:sticky xl:top-24">
            <CardHeader title="Price preview" description="Same pricing engine as checkout, using your unsaved draft" action={<Calculator className="size-4 text-muted" aria-hidden />} />
            <CardBody className="space-y-4">
                <div role="radiogroup" aria-label="Service" className="grid grid-cols-3 gap-1 rounded-[12px] bg-surface-2 p-1">
                    {(['distribution', 'print', 'design'] as const).map((s) => (
                        <button key={s} role="radio" aria-checked={svc === s} type="button" onClick={() => setSvc(s)} className={`rounded-[9px] py-1.5 text-sm font-medium ${svc === s ? 'bg-surface shadow-xs' : 'text-muted'}`}>
                            {titleCase(s)}
                        </button>
                    ))}
                </div>
                {svc === 'design' ? (
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Service" className="col-span-2">
                            <Select data-testid="preview-design-type" value={dz.design_type} onChange={(e) => setDz({ ...dz, design_type: e.target.value })}>
                                {Object.entries(draft.design?.types ?? {}).map(([k, t]) => (
                                    <option key={k} value={k}>{t.label}</option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Package">
                            <Select value={dz.package} onChange={(e) => setDz({ ...dz, package: e.target.value })}>
                                {Object.entries(draft.design?.packages ?? {}).map(([k, p]) => (
                                    <option key={k} value={k}>{p.label}</option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Sides">
                            <Select value={dz.sides} onChange={(e) => setDz({ ...dz, sides: Number(e.target.value) })}>
                                <option value={1}>Single</option>
                                <option value={2}>Double</option>
                            </Select>
                        </Field>
                        <fieldset className="col-span-2 space-y-2">
                            <legend className="mb-1 text-sm font-medium">Add-ons</legend>
                            {Object.entries(draft.design?.addons ?? {}).map(([k, a]) => (
                                <label key={k} className="flex items-center gap-2 text-sm">
                                    <Checkbox checked={dz.addons.includes(k)} onCheckedChange={(c) => setDz({ ...dz, addons: c ? [...dz.addons, k] : dz.addons.filter((x) => x !== k) })} />
                                    {a.label}
                                </label>
                            ))}
                        </fieldset>
                    </div>
                ) : svc === 'distribution' ? (
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Coverage" className="col-span-2">
                            <Select value={dist.coverage} onChange={(e) => setDist({ ...dist, coverage: e.target.value })}>
                                {Object.entries(draft.distribution.coverage).map(([k, c]) => (
                                    <option key={k} value={k}>{c.label}</option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Material" className="col-span-2">
                            <Select value={dist.material} onChange={(e) => setDist({ ...dist, material: e.target.value })}>
                                {Object.entries(draft.distribution.materials).map(([k, m]) => (
                                    <option key={k} value={k}>{m.label}</option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Houses">
                            <Input type="number" min={0} value={dist.houses} onChange={(e) => setDist({ ...dist, houses: e.target.value })} />
                        </Field>
                        <Field label="Units">
                            <Input type="number" min={0} value={dist.units} onChange={(e) => setDist({ ...dist, units: e.target.value })} />
                        </Field>
                    </div>
                ) : (
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Product" className="col-span-2">
                            <Select
                                value={pr.product_id}
                                onChange={(e) => {
                                    const p = products.find((x) => x.id === Number(e.target.value));
                                    if (p) setPr({ ...pr, product_id: p.id, gsm: p.gsm_options[0], quantity: p.quantity_options[0] });
                                }}
                            >
                                {products.map((p) => (
                                    <option key={p.id} value={p.id}>{p.name}</option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Paper">
                            <Select value={pr.gsm} onChange={(e) => setPr({ ...pr, gsm: Number(e.target.value) })}>
                                {product?.gsm_options.map((g) => <option key={g} value={g}>{g}gsm</option>)}
                            </Select>
                        </Field>
                        <Field label="Sides">
                            <Select value={pr.sides} onChange={(e) => setPr({ ...pr, sides: Number(e.target.value) })}>
                                <option value={1}>Single</option>
                                <option value={2}>Double</option>
                            </Select>
                        </Field>
                        <Field label="Quantity">
                            <Select value={pr.quantity} onChange={(e) => setPr({ ...pr, quantity: Number(e.target.value) })}>
                                {product?.quantity_options.map((q) => <option key={q} value={q}>{num(q)}</option>)}
                            </Select>
                        </Field>
                        <Field label="Finish">
                            <Select value={pr.finish} onChange={(e) => setPr({ ...pr, finish: e.target.value })}>
                                {Object.entries(draft.print.finishes).map(([k, f]) => <option key={k} value={k}>{f.label}</option>)}
                            </Select>
                        </Field>
                        <fieldset className="col-span-2 space-y-2">
                            <legend className="mb-1 text-sm font-medium">Add-ons</legend>
                            {Object.entries(draft.print.addons).map(([k, a]) => (
                                <label key={k} className="flex items-center gap-2 text-sm">
                                    <Checkbox checked={pr.addons.includes(k)} onCheckedChange={(c) => setPr({ ...pr, addons: c ? [...pr.addons, k] : pr.addons.filter((x) => x !== k) })} />
                                    {a.label}
                                </label>
                            ))}
                        </fieldset>
                    </div>
                )}
                <Field label="Promo code" optional>
                    <Input value={promo} onChange={(e) => setPromo(e.target.value.toUpperCase())} placeholder="e.g. WELCOME10" />
                </Field>

                <div className="rounded-[12px] border border-border" aria-live="polite">
                    {state.error ? (
                        <div className="p-3">
                            <ErrorState title="Can't price this" description={state.error} onRetry={() => setNonce((n) => n + 1)} />
                        </div>
                    ) : !state.quote ? (
                        <div className="space-y-2 p-4">
                            <Skeleton className="h-4" />
                            <Skeleton className="h-4 w-2/3" />
                            <Skeleton className="h-6 w-1/2" />
                        </div>
                    ) : (
                        <div className={`divide-y divide-border text-sm transition-opacity ${state.loading ? 'opacity-60' : ''}`}>
                            {state.quote.lines.map((l, i) => (
                                <div key={i} className="flex justify-between gap-3 px-4 py-2">
                                    <span className="text-muted">
                                        {l.label}
                                        {l.quantity > 1 && <span className="block text-xs text-subtle">{num(l.quantity)} × {perItem(l.unit_cents)}</span>}
                                    </span>
                                    <span className="tabular">{money(l.amount_cents)}</span>
                                </div>
                            ))}
                            <div className="flex justify-between px-4 py-2">
                                <span className="text-muted">Subtotal (ex GST)</span>
                                <span className="tabular">{money(state.quote.subtotal_cents)}</span>
                            </div>
                            <div className="flex justify-between px-4 py-2">
                                <span className="text-muted">GST</span>
                                <span className="tabular">{money(state.quote.gst_cents)}</span>
                            </div>
                            <div className="flex justify-between px-4 py-3 text-base font-semibold">
                                <span>Total incl GST</span>
                                <span className="tabular">{money(state.quote.total_cents)}</span>
                            </div>
                            <div className="flex justify-between px-4 py-2 text-xs text-muted">
                                <span>Per item incl GST</span>
                                <span className="tabular">{perItem(state.quote.per_item_incl_gst_cents)}</span>
                            </div>
                        </div>
                    )}
                </div>
            </CardBody>
        </Card>
    );
}

/* ---------------------------------------------------------------- matrix */

function MatrixEditor({ products, matrix }: { products: Product[]; matrix: Props['matrix'] }) {
    const fmt = (hc: number | null) => (hc === null ? '' : (hc / 10000).toFixed(4));
    const initialCells = () => Object.fromEntries((matrix?.options ?? []).map((o) => [o.id, fmt(o.unit_price_hc)]));
    const hcOf = (v: string | undefined) => (v === undefined || v.trim() === '' ? null : Math.round(parseFloat(v) * 10000));
    // Initialise from props on mount (not only in the effect) so an edit made before the effect runs can't wipe the other cells.
    const [cells, setCells] = useState<Record<number, string>>(initialCells);
    const [pct, setPct] = useState('');
    const [saving, setSaving] = useState(false);
    const [confirm, setConfirm] = useState(false);
    // eslint-disable-next-line react-hooks/exhaustive-deps
    useEffect(() => setCells(initialCells()), [matrix]);
    if (!matrix) return <EmptyState title="No print products" description="Seed the print catalogue first." />;
    const opts = matrix.options;
    const gsms = Array.from(new Set(opts.map((o) => o.gsm))).sort((a, b) => a - b);
    const qtys = Array.from(new Set(opts.map((o) => o.quantity))).sort((a, b) => a - b);
    // Empty cells are unpriced combinations (new options): they can be saved later, but the product stays hidden until all are priced.
    const changed = opts.filter((o) => hcOf(cells[o.id]) !== o.unit_price_hc && hcOf(cells[o.id]) !== null);
    const invalid = opts.some((o) => (cells[o.id] ?? '').trim() !== '' && !(parseFloat(cells[o.id]) > 0));
    const unpriced = opts.filter((o) => hcOf(cells[o.id]) === null).length;
    const adjust = () => {
        const f = 1 + Number(pct) / 100;
        if (!isFinite(f) || f <= 0) return;
        setCells((c) => Object.fromEntries(Object.entries(c).map(([k, v]) => [k, v.trim() === '' ? '' : (parseFloat(v) * f).toFixed(4)])));
        setPct('');
    };
    const save = () => {
        setSaving(true);
        router.put(`/admin/rate-card/matrix/${matrix.product_id}`, { cells: changed.map((o) => ({ id: o.id, unit_price_hc: hcOf(cells[o.id]) })) }, { preserveScroll: true, preserveState: true, onFinish: () => (setSaving(false), setConfirm(false)) });
    };
    return (
        <Card>
            <CardHeader
                title="Per-piece price, ex GST (AUD)"
                description="Rows: paper weight × sides. Columns: quantity."
                action={
                    <Select aria-label="Product" value={matrix.product_id} onChange={(e) => router.get('/admin/rate-card', { tab: 'matrix', product: e.target.value }, { preserveScroll: true })} className="w-auto">
                        {products.map((p) => (
                            <option key={p.id} value={p.id}>{p.name}</option>
                        ))}
                    </Select>
                }
            />
            <CardBody className="space-y-4">
                <div className="flex flex-wrap items-end gap-3">
                    <Field label="Bulk adjust all cells by">
                        <NumInput label="Adjust by percent" step="0.5" suffix="%" value={pct} onChange={setPct} />
                    </Field>
                    <Button variant="secondary" onClick={adjust} disabled={!pct || Number(pct) === 0}>
                        <Percent /> Apply
                    </Button>
                    <div className="ml-auto flex items-center gap-2">
                        {changed.length > 0 && <Badge tone="warning">{changed.length} unsaved cell(s)</Badge>}
                        {unpriced > 0 && <Badge tone="danger">{unpriced} unpriced</Badge>}
                        <Button variant="ghost" disabled={changed.length === 0} onClick={() => setCells(initialCells())}>
                            Reset
                        </Button>
                        <Button onClick={() => setConfirm(true)} disabled={changed.length === 0 || invalid} data-testid="save-matrix">
                            <Save /> Save matrix
                        </Button>
                    </div>
                </div>
                {opts.length === 0 ? (
                    <EmptyState title="No price combinations for this product" />
                ) : (
                    <Table label="Print price matrix">
                        <THead>
                            <Th>Paper / sides</Th>
                            {qtys.map((q) => (
                                <Th key={q} numeric>{num(q)}</Th>
                            ))}
                        </THead>
                        <tbody>
                            {gsms.flatMap((g) =>
                                [1, 2].map((s) => {
                                    const row = qtys.map((q) => opts.find((o) => o.gsm === g && o.sides === s && o.quantity === q));
                                    if (row.every((c) => !c)) return null;
                                    return (
                                        <Tr key={`${g}-${s}`}>
                                            <Td className="font-medium whitespace-nowrap">{g}gsm · {s === 2 ? 'double' : 'single'}</Td>
                                            {row.map((o, i) => (
                                                <Td key={i} className="p-1.5">
                                                    {o ? (
                                                        <Input
                                                            aria-label={`${g}gsm ${s === 2 ? 'double' : 'single'}-sided, ${qtys[i]} pieces, price per piece`}
                                                            type="number"
                                                            step="0.0001"
                                                            min="0"
                                                            value={cells[o.id] ?? ''}
                                                            placeholder="Set price"
                                                            aria-invalid={!(parseFloat(cells[o.id] ?? '') > 0) || undefined}
                                                            onChange={(e) => {
                                                                const v = e.target.value;
                                                                setCells((c) => ({ ...c, [o.id]: v }));
                                                            }}
                                                            className={`tabular h-9 min-w-24 text-right ${hcOf(cells[o.id]) !== o.unit_price_hc ? 'border-warning bg-warning-soft' : ''}`}
                                                        />
                                                    ) : (
                                                        <span className="block text-center text-subtle">—</span>
                                                    )}
                                                </Td>
                                            ))}
                                        </Tr>
                                    );
                                }),
                            )}
                        </tbody>
                    </Table>
                )}
            </CardBody>
            <ConfirmDialog open={confirm} onOpenChange={setConfirm} title={`Save ${changed.length} price change(s)?`} description="New prices apply to quotes immediately. Paid orders are not affected." confirmLabel="Save matrix" processing={saving} onConfirm={save} />
        </Card>
    );
}

/* ---------------------------------------------------------------- promos */

function Promos({ promos }: { promos: Promo[] }) {
    const [edit, setEdit] = useState<Promo | 'new' | null>(null);
    const [del, setDel] = useState<Promo | null>(null);
    const act = useAction();
    return (
        <>
            <div className="mb-4 flex justify-end">
                <Button onClick={() => setEdit('new')}>
                    <Plus /> New promo code
                </Button>
            </div>
            {promos.length === 0 ? (
                <EmptyState icon={Tag} title="No promo codes" description="Create a code for campaigns, partners or win-backs." action={<Button onClick={() => setEdit('new')}>Create promo code</Button>} />
            ) : (
                <Table label="Promo codes">
                    <THead>
                        <Th>Code</Th>
                        <Th>Discount</Th>
                        <Th>Applies to</Th>
                        <Th numeric>Min subtotal</Th>
                        <Th>Valid</Th>
                        <Th numeric>Uses</Th>
                        <Th>Status</Th>
                        <Th className="w-0"><span className="sr-only">Actions</span></Th>
                    </THead>
                    <tbody>
                        {promos.map((p) => (
                            <Tr key={p.id}>
                                <Td>
                                    <div className="font-mono font-semibold">{p.code}</div>
                                    {p.description && <div className="text-xs text-muted">{p.description}</div>}
                                </Td>
                                <Td>{p.type === 'percent' ? `${p.value}%` : money(p.value)}</Td>
                                <Td>{titleCase(p.service)}</Td>
                                <Td numeric>{money(p.min_subtotal_cents)}</Td>
                                <Td className="whitespace-nowrap">{p.starts_at || p.ends_at ? `${p.starts_at ? date(p.starts_at) : 'Now'} – ${p.ends_at ? date(p.ends_at) : 'no end'}` : 'Always'}</Td>
                                <Td numeric>{num(p.uses)}{p.max_uses ? ` / ${num(p.max_uses)}` : ''}</Td>
                                <Td><StatusBadge status={p.is_active ? 'approved' : 'cancelled'} label={p.is_active ? 'Active' : 'Inactive'} /></Td>
                                <Td>
                                    <div className="flex gap-1">
                                        <Button size="sm" variant="ghost" onClick={() => setEdit(p)}>Edit</Button>
                                        <Button size="icon-sm" variant="ghost" aria-label={`Delete ${p.code}`} onClick={() => setDel(p)}><Trash2 /></Button>
                                    </div>
                                </Td>
                            </Tr>
                        ))}
                    </tbody>
                </Table>
            )}
            {edit && <PromoDialog promo={edit === 'new' ? null : edit} onClose={() => setEdit(null)} />}
            <ConfirmDialog open={del !== null} onOpenChange={(o) => !o && setDel(null)} title={`Delete ${del?.code}?`} description="Orders that already used it keep their discount." confirmLabel="Delete" tone="danger" processing={act.processing} error={act.error} onConfirm={() => del && act.run('delete', `/admin/promos/${del.id}`, {}, () => setDel(null))} />
        </>
    );
}

function PromoDialog({ promo, onClose }: { promo: Promo | null; onClose: () => void }) {
    const form = useForm({
        code: promo?.code ?? '',
        description: promo?.description ?? '',
        type: promo?.type ?? 'percent',
        value: promo ? String(promo.type === 'percent' ? promo.value : promo.value / 100) : '',
        service: promo?.service ?? 'any',
        min_subtotal: promo ? String(promo.min_subtotal_cents / 100) : '0',
        starts_at: promo?.starts_at?.slice(0, 10) ?? '',
        ends_at: promo?.ends_at?.slice(0, 10) ?? '',
        max_uses: promo?.max_uses ? String(promo.max_uses) : '',
        is_active: promo?.is_active ?? true,
    });
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, starts_at: d.starts_at || null, ends_at: d.ends_at || null, max_uses: d.max_uses || null }));
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (promo) form.put(`/admin/promos/${promo.id}`, opts);
        else form.post('/admin/promos', opts);
    };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent title={promo ? `Edit ${promo.code}` : 'New promo code'} wide>
                <form className="grid gap-4 sm:grid-cols-2" onSubmit={submit}>
                    <Field label="Code" error={form.errors.code} hint="Letters, numbers, - and _">
                        <Input value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} className="font-mono" />
                    </Field>
                    <Field label="Description" optional error={form.errors.description}>
                        <Input value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                    </Field>
                    <Field label="Type" error={form.errors.type}>
                        <Select value={form.data.type} onChange={(e) => form.setData('type', e.target.value as 'percent' | 'fixed')}>
                            <option value="percent">Percentage off</option>
                            <option value="fixed">Fixed amount off (AUD)</option>
                        </Select>
                    </Field>
                    <Field label={form.data.type === 'percent' ? 'Percent' : 'Amount (AUD, ex GST)'} error={form.errors.value}>
                        <Input type="number" step={form.data.type === 'percent' ? '1' : '0.01'} value={form.data.value} onChange={(e) => form.setData('value', e.target.value)} />
                    </Field>
                    <Field label="Applies to" error={form.errors.service}>
                        <Select value={form.data.service} onChange={(e) => form.setData('service', e.target.value)}>
                            <option value="any">Any service</option>
                            <option value="distribution">Distribution</option>
                            <option value="print">Print</option>
                        </Select>
                    </Field>
                    <Field label="Minimum subtotal (AUD)" error={form.errors.min_subtotal}>
                        <Input type="number" step="0.01" value={form.data.min_subtotal} onChange={(e) => form.setData('min_subtotal', e.target.value)} />
                    </Field>
                    <Field label="Starts" optional error={form.errors.starts_at}>
                        <Input type="date" value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} />
                    </Field>
                    <Field label="Ends" optional error={form.errors.ends_at}>
                        <Input type="date" value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} />
                    </Field>
                    <Field label="Maximum uses" optional error={form.errors.max_uses}>
                        <Input type="number" min={1} value={form.data.max_uses} onChange={(e) => form.setData('max_uses', e.target.value)} />
                    </Field>
                    <div className="flex items-center gap-3 self-end pb-2">
                        <Switch id="promo-active" checked={form.data.is_active} onCheckedChange={(c) => form.setData('is_active', c)} />
                        <Label htmlFor="promo-active">Active</Label>
                    </div>
                    <div className="flex justify-end gap-2 sm:col-span-2">
                        <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                        <Button type="submit" loading={form.processing}>{promo ? 'Save promo' : 'Create promo'}</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function RateHistory({ history }: { history: Props['history'] }) {
    const [target, setTarget] = useState<Props['history'][number] | null>(null);
    const act = useAction();
    if (history.length === 0) return <EmptyState icon={History} title="No saved versions yet" description="The seeded defaults are in use until you save a rate card." />;
    return (
        <>
            <Table label="Rate card versions">
                <THead>
                    <Th>Version</Th>
                    <Th>Saved by</Th>
                    <Th>Saved</Th>
                    <Th>Status</Th>
                    <Th className="w-0"><span className="sr-only">Actions</span></Th>
                </THead>
                <tbody>
                    {history.map((h) => (
                        <Tr key={h.id}>
                            <Td className="font-medium">#{h.id} · {h.name}</Td>
                            <Td>{h.creator_name ?? 'Seeder'}</Td>
                            <Td>{dateTime(h.created_at)}</Td>
                            <Td>{h.is_active ? <StatusBadge status="approved" label="Active" /> : <StatusBadge status="cancelled" label="Superseded" />}</Td>
                            <Td>{!h.is_active && <Button size="sm" variant="secondary" onClick={() => setTarget(h)}>Re-activate</Button>}</Td>
                        </Tr>
                    ))}
                </tbody>
            </Table>
            <ConfirmDialog open={target !== null} onOpenChange={(o) => !o && setTarget(null)} title={`Re-activate version #${target?.id}?`} description="New quotes will use this version's prices immediately." confirmLabel="Re-activate" processing={act.processing} error={act.error} onConfirm={() => target && act.run('post', `/admin/rate-card/${target.id}/activate`, {}, () => setTarget(null))} />
        </>
    );
}
