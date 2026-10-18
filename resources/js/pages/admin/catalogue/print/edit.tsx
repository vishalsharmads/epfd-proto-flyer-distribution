import { ImageDropzone } from '@/components/admin/image-dropzone';
import { ConfirmDialog, useAction } from '@/components/admin/kit';
import { OptionChips } from '@/components/admin/option-chips';
import { fromPrice, ProductArt } from '@/components/app/print-bits';
import { Button } from '@/components/ui/button';
import { Checkbox, Field, Input, Label, Select, Switch, Textarea } from '@/components/ui/form';
import { Alert, Badge, Card, CardBody, CardHeader } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { cn } from '@/lib/utils';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Check, Eye, Info, Trash2, Truck } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

interface Product {
    id: number | null;
    slug?: string;
    name?: string;
    description?: string | null;
    category: string | null;
    size_label?: string;
    width_mm?: number;
    height_mm?: number;
    bleed_mm: number;
    from_price_cents?: number;
    gsm_options: number[];
    sides_options: number[];
    finish_options: Record<string, string[]>;
    quantity_options: number[];
    distributable: boolean;
    distribution_material?: string | null;
    turnaround_days: number;
    sort: number;
    is_active: boolean;
    image_url: string | null;
    unpriced: number;
}
type Option = { value: string; label: string };
type EditProps = {
    product: Product;
    categories: { slug: string; name: string; icon: string; is_active: boolean }[];
    finishes: Option[];
    materials: Option[];
};

const GSM_PRESETS = [115, 130, 150, 170, 250, 300, 350];
const QTY_PRESETS = [100, 250, 500, 1000, 2500, 5000, 10000];
const SIZE_PRESETS: { label: string; w: number; h: number; size: string }[] = [
    { label: 'A6', w: 105, h: 148, size: '105 × 148 mm' },
    { label: 'A5', w: 148, h: 210, size: '148 × 210 mm' },
    { label: 'A4', w: 210, h: 297, size: '210 × 297 mm' },
    { label: 'A3', w: 297, h: 420, size: '297 × 420 mm' },
    { label: 'A2', w: 420, h: 594, size: '420 × 594 mm' },
    { label: 'DL', w: 99, h: 210, size: '99 × 210 mm' },
    { label: 'Business card', w: 90, h: 55, size: '90 × 55 mm' },
];
const slugify = (s: string) =>
    s
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
        .slice(0, 120);
const comboKeys = (gsms: number[], sides: number[], qtys: number[]) =>
    new Set(gsms.flatMap((g) => sides.flatMap((s) => qtys.map((q) => `${g}|${s}|${q}`))));

/** Input with a unit shown inside on the right ("mm", "days"). */
function UnitInput({ unit, ...props }: React.InputHTMLAttributes<HTMLInputElement> & { unit: string }) {
    return (
        <div className="relative">
            <Input type="number" inputMode="numeric" {...props} className={cn('pr-12 tabular-nums', props.className)} />
            <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-subtle">{unit}</span>
        </div>
    );
}

/** Create and edit share this page: key by product so "Create product" → edit starts from the saved product, not the create form's state. */
export default function PrintProductPage(props: EditProps) {
    return <PrintProductEdit key={props.product.id ?? 'new'} {...props} />;
}

function PrintProductEdit({ product, categories, finishes, materials }: EditProps) {
    const creating = product.id === null;
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [slugTouched, setSlugTouched] = useState(!creating);
    const act = useAction();
    const form = useForm({
        name: product.name ?? '',
        slug: product.slug ?? '',
        category: product.category ?? categories[0]?.slug ?? '',
        description: product.description ?? '',
        size_label: product.size_label ?? '',
        width_mm: product.width_mm ?? ('' as number | ''),
        height_mm: product.height_mm ?? ('' as number | ''),
        bleed_mm: product.bleed_mm,
        gsm_options: product.gsm_options,
        sides_options: product.sides_options,
        finish_options: product.finish_options,
        quantity_options: product.quantity_options,
        distributable: product.distributable,
        distribution_material: product.distribution_material ?? '',
        turnaround_days: product.turnaround_days,
        sort: product.sort,
        is_active: product.is_active,
        image: null as File | null,
        remove_image: false,
    });
    const d = form.data;
    const errorFor = (prefix: string) => Object.entries(form.errors).find(([k]) => k === prefix || k.startsWith(`${prefix}.`))?.[1];
    const errorList = Object.values(form.errors).filter(Boolean);
    const category = categories.find((c) => c.slug === d.category);
    const submitting = useRef(false);

    // Warn before leaving with unsaved changes (Inertia visits and tab close/reload).
    useEffect(() => {
        const off = router.on('before', (event) => {
            if (submitting.current || !form.isDirty) return;
            if (event.detail.visit.method !== 'get') return;
            if (!window.confirm('You have unsaved changes to this product. Leave without saving?')) event.preventDefault();
        });
        const unload = (e: BeforeUnloadEvent) => {
            if (form.isDirty && !submitting.current) e.preventDefault();
        };
        window.addEventListener('beforeunload', unload);
        return () => {
            off();
            window.removeEventListener('beforeunload', unload);
        };
    }, [form.isDirty]);

    const setName = (name: string) => form.setData((x) => ({ ...x, name, slug: slugTouched ? x.slug : slugify(name) }));
    const setGsms = (gsms: number[]) =>
        form.setData((x) => ({
            ...x,
            gsm_options: gsms,
            finish_options: Object.fromEntries(
                gsms.map((g) => [String(g), x.finish_options[String(g)] ?? [finishes[0]?.value].filter(Boolean)]),
            ) as Record<string, string[]>,
        }));
    const toggleFinish = (gsm: number, finish: string, on: boolean) =>
        form.setData((x) => {
            const cur = x.finish_options[String(gsm)] ?? [];
            return { ...x, finish_options: { ...x.finish_options, [String(gsm)]: on ? [...cur, finish] : cur.filter((f) => f !== finish) } };
        });
    const toggleSide = (s: number) =>
        form.setData('sides_options', d.sides_options.includes(s) ? d.sides_options.filter((x) => x !== s) : [...d.sides_options, s].sort());
    const applySize = (p: (typeof SIZE_PRESETS)[number]) => form.setData((x) => ({ ...x, width_mm: p.w, height_mm: p.h, size_label: p.size }));

    // How the save will change the price matrix (new combinations start unpriced).
    const matrix = useMemo(() => {
        const now = comboKeys(d.gsm_options, d.sides_options, d.quantity_options);
        const saved = creating ? new Set<string>() : comboKeys(product.gsm_options, product.sides_options, product.quantity_options);
        return { total: now.size, added: [...now].filter((k) => !saved.has(k)).length, removed: [...saved].filter((k) => !now.has(k)).length };
    }, [d.gsm_options, d.sides_options, d.quantity_options, creating, product]);
    const willNeedPrices = matrix.added + (creating ? 0 : product.unpriced) > 0;

    const submit = (e?: React.FormEvent) => {
        e?.preventDefault();
        submitting.current = true;
        const opts = {
            preserveScroll: true,
            onFinish: () => (submitting.current = false),
            onSuccess: () => {
                form.setData((x) => ({ ...x, image: null, remove_image: false }));
                form.setDefaults();
            },
            onError: () =>
                requestAnimationFrame(() => document.getElementById('form-errors')?.scrollIntoView({ behavior: 'smooth', block: 'start' })),
        };
        if (creating) form.post('/admin/catalogue/print', opts);
        else {
            // POST + _method so an image upload (multipart) works for updates too.
            form.transform((x) => ({ ...x, _method: 'put' }));
            form.post(`/admin/catalogue/print/${product.id}`, opts);
        }
    };

    const status = creating ? null : product.unpriced > 0 ? (
        <Badge tone="warning">Needs prices</Badge>
    ) : product.is_active ? (
        <Badge tone="success">On sale</Badge>
    ) : (
        <Badge>Hidden</Badge>
    );
    const previewProduct = {
        width_mm: Number(d.width_mm) || 148,
        height_mm: Number(d.height_mm) || 210,
        image_url: d.remove_image ? null : product.image_url,
        category_icon: category?.icon,
    };

    return (
        <AdminLayout
            title={creating ? 'Add print product' : (product.name ?? 'Print product')}
            actions={
                <div className="flex items-center gap-2">
                    {status}
                    <Button asChild variant="secondary" size="sm">
                        <Link href="/admin/catalogue/print">
                            <ArrowLeft className="size-4" aria-hidden /> Print products
                        </Link>
                    </Button>
                </div>
            }
        >
            <form onSubmit={submit} className="pb-24" noValidate>
                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px] [&>*]:min-w-0">
                    <div className="space-y-6">
                        {errorList.length > 0 && (
                            <div id="form-errors" className="scroll-mt-24">
                                <Alert
                                    tone="danger"
                                    title={`Fix ${errorList.length} ${errorList.length === 1 ? 'problem' : 'problems'} before saving`}
                                >
                                    <ul className="mt-1 list-inside list-disc">
                                        {errorList.slice(0, 8).map((m) => (
                                            <li key={m}>{m}</li>
                                        ))}
                                    </ul>
                                </Alert>
                            </div>
                        )}
                        {!creating && product.unpriced > 0 && (
                            <Alert
                                tone="warning"
                                title={`${product.unpriced} option(s) have no price`}
                                action={
                                    <Button asChild size="sm" variant="secondary">
                                        <Link href={`/admin/rate-card?tab=matrix&product=${product.id}`}>Set prices</Link>
                                    </Button>
                                }
                            >
                                Customers can’t buy this product until every paper weight × sides × quantity combination is priced.
                            </Alert>
                        )}

                        <Card>
                            <CardHeader title="Details" description="What customers see in the catalogue" />
                            <CardBody className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-[1.4fr_1fr]">
                                    <Field label="Name" error={form.errors.name}>
                                        <Input
                                            value={d.name}
                                            onChange={(e) => setName(e.target.value)}
                                            placeholder="e.g. A5 Flyers"
                                            autoFocus={creating}
                                        />
                                    </Field>
                                    <Field label="Category" error={form.errors.category}>
                                        <Select value={d.category} onChange={(e) => form.setData('category', e.target.value)}>
                                            {categories.map((c) => (
                                                <option key={c.slug} value={c.slug}>
                                                    {c.name}
                                                    {c.is_active ? '' : ' (hidden)'}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                </div>
                                <Field
                                    label="Description"
                                    optional
                                    hint={`One or two sentences shown under the name · ${d.description.length}/1000`}
                                    error={form.errors.description}
                                >
                                    <Textarea
                                        rows={2}
                                        maxLength={1000}
                                        value={d.description}
                                        onChange={(e) => form.setData('description', e.target.value)}
                                        placeholder="e.g. Our most popular flyer — fits every letterbox."
                                    />
                                </Field>
                                <Field
                                    label="URL key"
                                    hint={slugTouched ? 'Used in links and the API' : 'Follows the name until you edit it'}
                                    error={form.errors.slug}
                                >
                                    <Input
                                        value={d.slug}
                                        onChange={(e) => {
                                            setSlugTouched(true);
                                            form.setData('slug', slugify(e.target.value));
                                        }}
                                        placeholder="a5-flyers"
                                        className="font-mono text-sm"
                                    />
                                </Field>
                            </CardBody>
                        </Card>

                        <Card>
                            <CardHeader title="Size" description="Pick a standard size or enter your own" />
                            <CardBody className="space-y-4">
                                <div className="flex flex-wrap gap-1.5" role="group" aria-label="Standard sizes">
                                    {SIZE_PRESETS.map((p) => {
                                        const on = Number(d.width_mm) === p.w && Number(d.height_mm) === p.h;
                                        return (
                                            <button
                                                key={p.label}
                                                type="button"
                                                aria-pressed={on}
                                                onClick={() => applySize(p)}
                                                className={cn(
                                                    'inline-flex h-8 items-center gap-1 rounded-full border px-3 text-sm transition-colors focus-visible:outline-2 focus-visible:outline-focus',
                                                    on
                                                        ? 'border-fg bg-fg text-bg'
                                                        : 'border-border bg-surface text-muted hover:border-border-strong hover:text-fg',
                                                )}
                                            >
                                                {on && <Check className="size-3.5" aria-hidden />}
                                                {p.label}
                                            </button>
                                        );
                                    })}
                                </div>
                                <div className="grid gap-4 sm:grid-cols-[1.4fr_1fr_1fr_1fr]">
                                    <Field label="Size label" hint="As customers see it" error={form.errors.size_label}>
                                        <Input
                                            value={d.size_label}
                                            onChange={(e) => form.setData('size_label', e.target.value)}
                                            placeholder="148 × 210 mm"
                                        />
                                    </Field>
                                    <Field label="Width" error={form.errors.width_mm}>
                                        <UnitInput
                                            unit="mm"
                                            min={10}
                                            value={d.width_mm}
                                            onChange={(e) => form.setData('width_mm', e.target.value === '' ? '' : Number(e.target.value))}
                                        />
                                    </Field>
                                    <Field label="Height" error={form.errors.height_mm}>
                                        <UnitInput
                                            unit="mm"
                                            min={10}
                                            value={d.height_mm}
                                            onChange={(e) => form.setData('height_mm', e.target.value === '' ? '' : Number(e.target.value))}
                                        />
                                    </Field>
                                    <Field label="Bleed" error={form.errors.bleed_mm}>
                                        <UnitInput
                                            unit="mm"
                                            min={0}
                                            value={d.bleed_mm}
                                            onChange={(e) => form.setData('bleed_mm', Number(e.target.value))}
                                        />
                                    </Field>
                                </div>
                            </CardBody>
                        </Card>

                        <Card>
                            <CardHeader title="Options" description="Every paper weight × sides × quantity becomes a price in the print matrix" />
                            <CardBody className="space-y-7">
                                <section className="space-y-2">
                                    <h3 id="gsm-label" className="text-sm font-medium">
                                        Paper weights
                                    </h3>
                                    <OptionChips
                                        id="gsm"
                                        values={d.gsm_options}
                                        presets={GSM_PRESETS}
                                        onChange={setGsms}
                                        suffix="gsm"
                                        placeholder="Paper weight"
                                        invalid={!!errorFor('gsm_options')}
                                    />
                                    {errorFor('gsm_options') && <p className="text-sm text-danger">{errorFor('gsm_options')}</p>}
                                </section>

                                <section className="space-y-2">
                                    <h3 className="text-sm font-medium">Finishes per paper weight</h3>
                                    {d.gsm_options.length === 0 ? (
                                        <p className="text-sm text-muted">Choose a paper weight first.</p>
                                    ) : (
                                        <div className="overflow-x-auto rounded-[12px] border border-border">
                                            <table className="w-full text-sm">
                                                <thead className="bg-surface-2 text-xs text-muted">
                                                    <tr>
                                                        <th scope="col" className="px-4 py-2 text-left font-medium">
                                                            Paper
                                                        </th>
                                                        {finishes.map((f) => (
                                                            <th key={f.value} scope="col" className="px-4 py-2 text-center font-medium">
                                                                {f.label}
                                                            </th>
                                                        ))}
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {d.gsm_options.map((g) => {
                                                        const none = (d.finish_options[String(g)] ?? []).length === 0;
                                                        return (
                                                            <tr key={g} className={cn('border-t border-border', none && 'bg-danger-soft/50')}>
                                                                <th scope="row" className="px-4 py-2.5 text-left font-medium tabular-nums">
                                                                    {g}gsm{' '}
                                                                    {none && (
                                                                        <span className="ml-1 text-xs font-normal text-danger">needs a finish</span>
                                                                    )}
                                                                </th>
                                                                {finishes.map((f) => (
                                                                    <td key={f.value} className="px-4 py-2.5 text-center">
                                                                        <Checkbox
                                                                            aria-label={`${g}gsm ${f.label}`}
                                                                            checked={(d.finish_options[String(g)] ?? []).includes(f.value)}
                                                                            onCheckedChange={(v) => toggleFinish(g, f.value, v === true)}
                                                                        />
                                                                    </td>
                                                                ))}
                                                            </tr>
                                                        );
                                                    })}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                    {errorFor('finish_options') && <p className="text-sm text-danger">{errorFor('finish_options')}</p>}
                                </section>

                                <section className="space-y-2">
                                    <h3 className="text-sm font-medium">Sides</h3>
                                    <div className="grid max-w-md grid-cols-2 gap-2" role="group" aria-label="Sides offered">
                                        {[
                                            [1, 'Single-sided', 'Front only'],
                                            [2, 'Double-sided', 'Front and back'],
                                        ].map(([s, label, sub]) => {
                                            const on = d.sides_options.includes(s as number);
                                            return (
                                                <button
                                                    key={s}
                                                    type="button"
                                                    aria-pressed={on}
                                                    onClick={() => toggleSide(s as number)}
                                                    className={cn(
                                                        'flex items-start gap-2.5 rounded-[12px] border p-3 text-left transition-colors focus-visible:outline-2 focus-visible:outline-focus',
                                                        on ? 'border-fg bg-surface-2 ring-2 ring-fg/10' : 'border-border hover:border-border-strong',
                                                    )}
                                                >
                                                    <span
                                                        className={cn(
                                                            'mt-0.5 grid size-4 shrink-0 place-items-center rounded-[4px] border',
                                                            on ? 'border-fg bg-fg text-bg' : 'border-border-strong',
                                                        )}
                                                    >
                                                        {on && <Check className="size-3" aria-hidden />}
                                                    </span>
                                                    <span>
                                                        <span className="block text-sm font-medium">{label}</span>
                                                        <span className="block text-xs text-muted">{sub}</span>
                                                    </span>
                                                </button>
                                            );
                                        })}
                                    </div>
                                    {errorFor('sides_options') && <p className="text-sm text-danger">Choose at least one.</p>}
                                </section>

                                <section className="space-y-2">
                                    <h3 id="qty-label" className="text-sm font-medium">
                                        Quantities
                                    </h3>
                                    <OptionChips
                                        id="qty"
                                        values={d.quantity_options}
                                        presets={QTY_PRESETS}
                                        onChange={(v) => form.setData('quantity_options', v)}
                                        placeholder="Quantity"
                                        invalid={!!errorFor('quantity_options')}
                                    />
                                    {errorFor('quantity_options') && <p className="text-sm text-danger">{errorFor('quantity_options')}</p>}
                                </section>

                                <div
                                    className={cn(
                                        'flex items-start gap-2.5 rounded-[12px] p-3 text-sm',
                                        matrix.added > 0 || matrix.removed > 0 ? 'bg-info-soft' : 'bg-surface-2',
                                    )}
                                >
                                    <Info className="mt-0.5 size-4 shrink-0 text-info" aria-hidden />
                                    <p>
                                        <span className="font-medium">{matrix.total} price combination(s).</span>{' '}
                                        {creating
                                            ? 'Each needs a price in the print matrix before the product can go on sale.'
                                            : matrix.added || matrix.removed
                                              ? `Saving adds ${matrix.added} unpriced and removes ${matrix.removed} priced combination(s).`
                                              : 'No change to the print matrix.'}
                                    </p>
                                </div>
                            </CardBody>
                        </Card>

                        <Card>
                            <CardHeader title="Production & distribution" />
                            <CardBody className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field label="Turnaround" hint="Business days to print" error={form.errors.turnaround_days}>
                                        <UnitInput
                                            unit="days"
                                            min={1}
                                            max={60}
                                            value={d.turnaround_days}
                                            onChange={(e) => form.setData('turnaround_days', Number(e.target.value))}
                                        />
                                    </Field>
                                    <Field label="Display order" hint="Lower shows first in its category" error={form.errors.sort}>
                                        <Input type="number" min={0} value={d.sort} onChange={(e) => form.setData('sort', Number(e.target.value))} />
                                    </Field>
                                </div>
                                <div className="rounded-[12px] border border-border p-4">
                                    <div className="flex items-center justify-between gap-4">
                                        <Label htmlFor="distributable" className="flex items-center gap-2">
                                            <Truck className="size-4 text-muted" aria-hidden /> Can be letterboxed by EPFD
                                        </Label>
                                        <Switch
                                            id="distributable"
                                            checked={d.distributable}
                                            onCheckedChange={(c) => form.setData('distributable', c)}
                                        />
                                    </div>
                                    {d.distributable && (
                                        <Field
                                            className="mt-4"
                                            label="Distribution material"
                                            hint="Sets the distribution price when customers also book a letterbox drop"
                                            error={form.errors.distribution_material}
                                        >
                                            <Select
                                                value={d.distribution_material}
                                                onChange={(e) => form.setData('distribution_material', e.target.value)}
                                            >
                                                <option value="">Choose…</option>
                                                {materials.map((m) => (
                                                    <option key={m.value} value={m.value}>
                                                        {m.label}
                                                    </option>
                                                ))}
                                            </Select>
                                        </Field>
                                    )}
                                </div>
                            </CardBody>
                        </Card>
                    </div>

                    <aside className="space-y-6 xl:sticky xl:top-20 xl:self-start">
                        <Card>
                            <CardHeader title="Image" description="Optional — without one, customers see the category icon" />
                            <CardBody>
                                <ImageDropzone
                                    file={d.image}
                                    currentUrl={d.remove_image ? null : product.image_url}
                                    aspect={4 / 3}
                                    onChange={(f) => form.setData((x) => ({ ...x, image: f, remove_image: false }))}
                                    onRemove={() => form.setData((x) => ({ ...x, image: null, remove_image: !!product.image_url }))}
                                    progress={form.progress?.percentage ?? null}
                                    error={form.errors.image}
                                />
                            </CardBody>
                        </Card>

                        <Card>
                            <CardHeader
                                title="Customer preview"
                                description="How the product card looks in the catalogue"
                                action={<Eye className="size-4 text-muted" aria-hidden />}
                            />
                            <CardBody>
                                <div
                                    className="mx-auto max-w-[260px] overflow-hidden rounded-[16px] border border-border bg-surface shadow-sm"
                                    aria-hidden
                                >
                                    {d.image ? <PreviewImage file={d.image} /> : <ProductArt product={previewProduct} className="aspect-[4/3]" />}
                                    <div className="p-4">
                                        <p className="truncate font-medium">{d.name || 'Product name'}</p>
                                        <p className="truncate text-xs text-muted">{d.size_label || 'Size'}</p>
                                        {d.description && <p className="mt-1.5 line-clamp-2 text-xs text-muted">{d.description}</p>}
                                        <p className="mt-2 flex items-center justify-between text-sm">
                                            <span className="font-semibold">
                                                {product.from_price_cents
                                                    ? fromPrice({ from_price_cents: product.from_price_cents } as never)
                                                    : 'from —'}
                                            </span>
                                            {d.distributable && (
                                                <span className="inline-flex items-center gap-1 text-xs text-muted">
                                                    <Truck className="size-3.5" aria-hidden /> Distributable
                                                </span>
                                            )}
                                        </p>
                                    </div>
                                </div>
                                <p className="mt-3 text-center text-xs text-muted">{category ? `Listed under ${category.name}` : ''}</p>
                            </CardBody>
                        </Card>

                        <Card>
                            <CardBody className="space-y-3">
                                <div className="flex items-center justify-between gap-4">
                                    <Label htmlFor="active" className="font-medium">
                                        On sale
                                    </Label>
                                    <Switch id="active" checked={d.is_active} onCheckedChange={(c) => form.setData('is_active', c)} />
                                </div>
                                <p className="text-xs text-muted">
                                    {d.is_active && willNeedPrices
                                        ? 'Goes on sale automatically once every option has a price.'
                                        : d.is_active
                                          ? 'Visible to customers after saving.'
                                          : 'Hidden from customers.'}
                                </p>
                                {!creating && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="w-full text-danger"
                                        onClick={() => setConfirmDelete(true)}
                                    >
                                        <Trash2 /> Delete product
                                    </Button>
                                )}
                            </CardBody>
                        </Card>
                    </aside>
                </div>

                <div className="sticky bottom-0 z-20 -mx-4 mt-6 border-t border-border bg-surface/90 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
                    <div className="flex items-center justify-end gap-3">
                        <p className={cn('mr-auto text-sm', form.isDirty ? 'text-warning' : 'text-muted')} aria-live="polite">
                            {form.processing
                                ? form.progress
                                    ? `Uploading image… ${form.progress.percentage}%`
                                    : 'Saving…'
                                : form.isDirty
                                  ? 'Unsaved changes'
                                  : creating
                                    ? 'Fill in the details to create the product'
                                    : 'All changes saved'}
                        </p>
                        {!creating && form.isDirty && (
                            <Button type="button" variant="ghost" onClick={() => form.reset()} disabled={form.processing}>
                                Discard
                            </Button>
                        )}
                        <Button type="submit" loading={form.processing} disabled={!creating && !form.isDirty}>
                            {creating ? 'Create product' : 'Save changes'}
                        </Button>
                    </div>
                </div>
            </form>
            <ConfirmDialog
                open={confirmDelete}
                onOpenChange={setConfirmDelete}
                title={`Delete ${product.name}?`}
                description="Only hidden products that have never been ordered can be deleted. Ordered products should be hidden instead so past orders keep their details."
                confirmLabel="Delete"
                tone="danger"
                processing={act.processing}
                error={act.error}
                onConfirm={() => act.run('delete', `/admin/catalogue/print/${product.id}`)}
            />
        </AdminLayout>
    );
}

/** Local preview of a not-yet-saved image in the customer card. */
function PreviewImage({ file }: { file: File }) {
    const [url, setUrl] = useState<string | null>(null);
    useEffect(() => {
        const u = URL.createObjectURL(file);
        setUrl(u);
        return () => URL.revokeObjectURL(u);
    }, [file]);
    return <div className="aspect-[4/3] overflow-hidden bg-surface-2">{url && <img src={url} alt="" className="size-full object-cover" />}</div>;
}
