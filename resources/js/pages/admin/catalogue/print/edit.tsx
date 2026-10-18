import { ConfirmDialog, useAction } from '@/components/admin/kit';
import { ProductArt } from '@/components/app/print-bits';
import { Button } from '@/components/ui/button';
import { Checkbox, Field, Input, Label, Select, Switch, Textarea } from '@/components/ui/form';
import { Alert, Card, CardBody, CardHeader } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { num } from '@/lib/utils';
import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Trash2, X } from 'lucide-react';
import { useState } from 'react';

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

/** Editable list of positive integers shown as removable chips (paper weights, quantities). */
function NumberList({ id, values, onChange, suffix = '', placeholder }: { id: string; values: number[]; onChange: (v: number[]) => void; suffix?: string; placeholder: string }) {
    const [draft, setDraft] = useState('');
    const add = () => {
        const n = Math.round(Number(draft.replace(/[, ]/g, '')));
        if (n > 0 && !values.includes(n)) onChange([...values, n].sort((a, b) => a - b));
        setDraft('');
    };
    return (
        <div className="space-y-2">
            <div className="flex flex-wrap gap-1.5">
                {values.map((v) => (
                    <span key={v} className="inline-flex items-center gap-1 rounded-full border border-border bg-surface-2 py-0.5 pr-1 pl-2.5 text-sm tabular-nums">
                        {num(v)}
                        {suffix}
                        <button type="button" className="rounded-full p-0.5 hover:bg-surface" aria-label={`Remove ${v}${suffix}`} onClick={() => onChange(values.filter((x) => x !== v))}>
                            <X className="size-3.5" />
                        </button>
                    </span>
                ))}
                {values.length === 0 && <span className="text-sm text-muted">None yet</span>}
            </div>
            <div className="flex gap-2">
                <Input
                    id={id}
                    inputMode="numeric"
                    value={draft}
                    placeholder={placeholder}
                    onChange={(e) => setDraft(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            add();
                        }
                    }}
                />
                <Button type="button" variant="secondary" onClick={add} disabled={!draft.trim()}>
                    Add
                </Button>
            </div>
        </div>
    );
}

export default function PrintProductEdit({ product, categories, finishes, materials }: { product: Product; categories: { slug: string; name: string; icon: string; is_active: boolean }[]; finishes: Option[]; materials: Option[] }) {
    const creating = product.id === null;
    const [preview, setPreview] = useState<string | null>(product.image_url);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const act = useAction();
    const form = useForm({
        name: product.name ?? '',
        slug: product.slug ?? '',
        category: product.category ?? categories[0]?.slug ?? '',
        description: product.description ?? '',
        size_label: product.size_label ?? '',
        width_mm: product.width_mm ?? '',
        height_mm: product.height_mm ?? '',
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

    const setGsms = (gsms: number[]) =>
        form.setData((x) => ({
            ...x,
            gsm_options: gsms,
            finish_options: Object.fromEntries(gsms.map((g) => [String(g), x.finish_options[String(g)] ?? [finishes[0]?.value].filter(Boolean)])) as Record<string, string[]>,
        }));
    const toggleFinish = (gsm: number, finish: string, on: boolean) =>
        form.setData((x) => {
            const cur = x.finish_options[String(gsm)] ?? [];
            return { ...x, finish_options: { ...x.finish_options, [String(gsm)]: on ? [...cur, finish] : cur.filter((f) => f !== finish) } };
        });
    const toggleSide = (s: number, on: boolean) => form.setData('sides_options', on ? [...d.sides_options, s].sort() : d.sides_options.filter((x) => x !== s));

    const combos = d.gsm_options.length * d.sides_options.length * d.quantity_options.length;
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (creating) form.post('/admin/catalogue/print', { preserveScroll: true });
        else {
            // POST + _method so an image upload (multipart) works for updates too.
            form.transform((x) => ({ ...x, _method: 'put' }));
            form.post(`/admin/catalogue/print/${product.id}`, { preserveScroll: true, onSuccess: () => form.setData((x) => ({ ...x, image: null, remove_image: false })) });
        }
    };

    return (
        <AdminLayout
            title={creating ? 'Add print product' : (product.name ?? 'Print product')}
            actions={
                <Button asChild variant="secondary" size="sm">
                    <Link href="/admin/catalogue/print">
                        <ArrowLeft className="size-4" aria-hidden /> Print products
                    </Link>
                </Button>
            }
        >
            <form onSubmit={submit} className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px] [&>*]:min-w-0">
                <div className="space-y-6">
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
                        <CardHeader title="Details" />
                        <CardBody className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Name" error={form.errors.name}>
                                    <Input value={d.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="e.g. A5 Flyers" />
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
                            <Field label="Description" optional hint="One or two sentences shown under the product name" error={form.errors.description}>
                                <Textarea rows={2} maxLength={1000} value={d.description} onChange={(e) => form.setData('description', e.target.value)} />
                            </Field>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Size label" hint="As customers see it, e.g. 148 × 210 mm" error={form.errors.size_label}>
                                    <Input value={d.size_label} onChange={(e) => form.setData('size_label', e.target.value)} />
                                </Field>
                                <Field label="URL key" optional hint="Generated from the name" error={form.errors.slug}>
                                    <Input value={d.slug} onChange={(e) => form.setData('slug', e.target.value)} placeholder="a5-flyers" />
                                </Field>
                            </div>
                            <div className="grid grid-cols-3 gap-4">
                                <Field label="Width (mm)" error={form.errors.width_mm}>
                                    <Input type="number" min={10} value={d.width_mm} onChange={(e) => form.setData('width_mm', e.target.value === '' ? '' : Number(e.target.value))} />
                                </Field>
                                <Field label="Height (mm)" error={form.errors.height_mm}>
                                    <Input type="number" min={10} value={d.height_mm} onChange={(e) => form.setData('height_mm', e.target.value === '' ? '' : Number(e.target.value))} />
                                </Field>
                                <Field label="Bleed (mm)" error={form.errors.bleed_mm}>
                                    <Input type="number" min={0} value={d.bleed_mm} onChange={(e) => form.setData('bleed_mm', Number(e.target.value))} />
                                </Field>
                            </div>
                        </CardBody>
                    </Card>

                    <Card>
                        <CardHeader title="Options" description={`${combos} price combination(s): paper weights × sides × quantities`} />
                        <CardBody className="space-y-6">
                            <Field label="Paper weights (gsm)" error={errorFor('gsm_options')}>
                                <NumberList id="gsm" values={d.gsm_options} onChange={setGsms} suffix="gsm" placeholder="e.g. 170" />
                            </Field>
                            <fieldset className="space-y-2">
                                <legend className="text-sm font-medium">Finishes per paper weight</legend>
                                {errorFor('finish_options') && <p className="text-sm text-danger">{errorFor('finish_options')}</p>}
                                {d.gsm_options.length === 0 ? (
                                    <p className="text-sm text-muted">Add a paper weight first.</p>
                                ) : (
                                    <div className="space-y-2">
                                        {d.gsm_options.map((g) => (
                                            <div key={g} className="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                                                <span className="w-16 font-medium tabular-nums">{g}gsm</span>
                                                {finishes.map((f) => {
                                                    const id = `finish-${g}-${f.value}`;
                                                    return (
                                                        <span key={f.value} className="inline-flex items-center gap-2">
                                                            <Checkbox id={id} checked={(d.finish_options[String(g)] ?? []).includes(f.value)} onCheckedChange={(v) => toggleFinish(g, f.value, v === true)} />
                                                            <Label htmlFor={id}>{f.label}</Label>
                                                        </span>
                                                    );
                                                })}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </fieldset>
                            <fieldset className="space-y-2">
                                <legend className="text-sm font-medium">Sides</legend>
                                <div className="flex gap-6 text-sm">
                                    {[
                                        [1, 'Single-sided'],
                                        [2, 'Double-sided'],
                                    ].map(([s, label]) => (
                                        <span key={s} className="inline-flex items-center gap-2">
                                            <Checkbox id={`sides-${s}`} checked={d.sides_options.includes(s as number)} onCheckedChange={(v) => toggleSide(s as number, v === true)} />
                                            <Label htmlFor={`sides-${s}`}>{label}</Label>
                                        </span>
                                    ))}
                                </div>
                                {errorFor('sides_options') && <p className="text-sm text-danger">{errorFor('sides_options')}</p>}
                            </fieldset>
                            <Field label="Quantities" error={errorFor('quantity_options')}>
                                <NumberList id="qty" values={d.quantity_options} onChange={(v) => form.setData('quantity_options', v)} placeholder="e.g. 2500" />
                            </Field>
                            {!creating && <p className="text-xs text-muted">Adding options creates unpriced cells in the print matrix; removing options deletes their prices.</p>}
                        </CardBody>
                    </Card>

                    <Card>
                        <CardHeader title="Production & distribution" />
                        <CardBody className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Turnaround (business days)" error={form.errors.turnaround_days}>
                                    <Input type="number" min={1} max={60} value={d.turnaround_days} onChange={(e) => form.setData('turnaround_days', Number(e.target.value))} />
                                </Field>
                                <Field label="Display order" error={form.errors.sort}>
                                    <Input type="number" min={0} value={d.sort} onChange={(e) => form.setData('sort', Number(e.target.value))} />
                                </Field>
                            </div>
                            <div className="flex items-center gap-3">
                                <Switch id="distributable" checked={d.distributable} onCheckedChange={(c) => form.setData('distributable', c)} />
                                <Label htmlFor="distributable">Can be letterboxed by EPFD</Label>
                            </div>
                            {d.distributable && (
                                <Field label="Distribution material" hint="Sets the distribution price when customers also book a letterbox drop" error={form.errors.distribution_material}>
                                    <Select value={d.distribution_material} onChange={(e) => form.setData('distribution_material', e.target.value)}>
                                        <option value="">Choose…</option>
                                        {materials.map((m) => (
                                            <option key={m.value} value={m.value}>
                                                {m.label}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                            )}
                        </CardBody>
                    </Card>
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader title="Image" description="Optional. JPG, PNG or WebP up to 4 MB." />
                        <CardBody className="space-y-3">
                            <ProductArt product={{ width_mm: Number(d.width_mm) || 148, height_mm: Number(d.height_mm) || 210, image_url: preview }} icon={categories.find((c) => c.slug === d.category)?.icon} className="aspect-[4/3] rounded-[12px]" />
                            <Input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                aria-label="Product image"
                                onChange={(e) => {
                                    const f = e.target.files?.[0] ?? null;
                                    form.setData((x) => ({ ...x, image: f, remove_image: false }));
                                    setPreview(f ? URL.createObjectURL(f) : product.image_url);
                                }}
                            />
                            {form.errors.image && <p className="text-sm text-danger">{form.errors.image}</p>}
                            {preview && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => {
                                        form.setData((x) => ({ ...x, image: null, remove_image: true }));
                                        setPreview(null);
                                    }}
                                >
                                    Remove image
                                </Button>
                            )}
                        </CardBody>
                    </Card>
                    <Card>
                        <CardBody className="space-y-4">
                            <div className="flex items-center gap-3">
                                <Switch id="active" checked={d.is_active} onCheckedChange={(c) => form.setData('is_active', c)} />
                                <Label htmlFor="active">On sale</Label>
                            </div>
                            <p className="text-xs text-muted">Stays hidden until every option has a price.</p>
                            <Button type="submit" className="w-full" loading={form.processing}>
                                {creating ? 'Create product' : 'Save changes'}
                            </Button>
                            {!creating && (
                                <Button type="button" variant="ghost" className="w-full text-danger" onClick={() => setConfirmDelete(true)}>
                                    <Trash2 /> Delete product
                                </Button>
                            )}
                        </CardBody>
                    </Card>
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
