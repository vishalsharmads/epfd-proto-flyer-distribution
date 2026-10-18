import { IconPicker } from '@/components/admin/icon-picker';
import { ConfirmDialog, Table, Td, Th, THead, Tr, useAction } from '@/components/admin/kit';
import { CategoryIcon, ProductArt } from '@/components/app/print-bits';
import { Button } from '@/components/ui/button';
import { Field, Input, Label, Select, Switch } from '@/components/ui/form';
import { Dialog, DialogContent } from '@/components/ui/overlay';
import { Badge, Card, CardHeader, EmptyState } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { cn, money, num } from '@/lib/utils';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Eye, EyeOff, Pencil, Plus, Printer, Search, Trash2, Truck, X } from 'lucide-react';
import { useMemo, useState } from 'react';

interface Product {
    id: number;
    slug: string;
    name: string;
    category: string;
    category_name: string | null;
    size_label: string;
    width_mm: number;
    height_mm: number;
    from_price_cents: number;
    image_url: string | null;
    is_active: boolean;
    distributable: boolean;
    sort: number;
    unpriced: number;
    orders: number;
    gsm_options: number[];
    sides_options: number[];
    quantity_options: number[];
}
interface Category {
    id: number;
    slug: string;
    name: string;
    icon: string;
    sort: number;
    is_active: boolean;
    products_count: number;
}
type StatusFilter = 'all' | 'on_sale' | 'hidden' | 'needs_prices';

function StatusBadge({ p }: { p: Product }) {
    const s = statusOf(p);
    if (s === 'needs_prices') {
        return (
            <Link href={`/admin/rate-card?tab=matrix&product=${p.id}`} title="Set the missing prices">
                <Badge tone="warning" dot>
                    {p.unpriced} unpriced
                </Badge>
            </Link>
        );
    }
    return s === 'on_sale' ? (
        <Badge tone="success" dot>
            On sale
        </Badge>
    ) : (
        <Badge dot>Hidden</Badge>
    );
}

const statusOf = (p: Product): Exclude<StatusFilter, 'all'> => (p.unpriced > 0 ? 'needs_prices' : p.is_active ? 'on_sale' : 'hidden');
const cents = (c: number) => (c <= 0 ? '—' : c < 100 ? `${c}c` : money(c));
const range = (v: number[], suffix = '') =>
    v.length === 0 ? '—' : v.length === 1 ? `${num(v[0])}${suffix}` : `${num(Math.min(...v))}–${num(Math.max(...v))}${suffix}`;

export default function PrintProducts({ products, categories, icons }: { products: Product[]; categories: Category[]; icons: string[] }) {
    const [q, setQ] = useState('');
    const [status, setStatus] = useState<StatusFilter>('all');
    const [cat, setCat] = useState('');
    const [editCat, setEditCat] = useState<Category | 'new' | null>(null);
    const [delCat, setDelCat] = useState<Category | null>(null);
    const act = useAction();
    const iconOf = (slug: string) => categories.find((c) => c.slug === slug)?.icon;

    const counts = useMemo(() => {
        const c = { all: products.length, on_sale: 0, hidden: 0, needs_prices: 0 };
        products.forEach((p) => c[statusOf(p)]++);
        return c;
    }, [products]);
    const shown = useMemo(() => {
        const term = q.trim().toLowerCase();
        return products.filter(
            (p) =>
                (status === 'all' || statusOf(p) === status) &&
                (!cat || p.category === cat) &&
                (!term || `${p.name} ${p.size_label} ${p.slug}`.toLowerCase().includes(term)),
        );
    }, [products, q, status, cat]);
    const filtered = q !== '' || status !== 'all' || cat !== '';

    const move = (i: number, delta: number) => {
        const ids = categories.map((c) => c.id);
        const j = i + delta;
        if (j < 0 || j >= ids.length) return;
        [ids[i], ids[j]] = [ids[j], ids[i]];
        router.post('/admin/catalogue/categories/reorder', { ids }, { preserveScroll: true });
    };

    const chips: { value: StatusFilter; label: string }[] = [
        { value: 'all', label: 'All' },
        { value: 'on_sale', label: 'On sale' },
        { value: 'hidden', label: 'Hidden' },
        { value: 'needs_prices', label: 'Needs prices' },
    ];

    return (
        <AdminLayout
            title="Print products"
            actions={
                <Button asChild>
                    <Link href="/admin/catalogue/print/create">
                        <Plus /> Add product
                    </Link>
                </Button>
            }
        >
            <p className="mb-6 max-w-3xl text-sm text-muted">
                What customers can order in the Print Store. Prices per paper weight, sides and quantity live in{' '}
                <Link href="/admin/rate-card?tab=matrix" className="font-medium text-primary-hover underline underline-offset-2">
                    Rate card → Print price matrix
                </Link>
                ; a product only goes on sale once every option has a price.
            </p>

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px] [&>*]:min-w-0">
                <div className="space-y-4">
                    <div className="space-y-3">
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <div className="relative flex-1">
                                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-subtle" aria-hidden />
                                <Input
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                    placeholder="Search products"
                                    aria-label="Search products"
                                    className="pr-9 pl-9"
                                />
                                {q && (
                                    <button
                                        type="button"
                                        onClick={() => setQ('')}
                                        aria-label="Clear search"
                                        className="absolute top-1/2 right-2 -translate-y-1/2 rounded-full p-1 text-subtle hover:bg-surface-2 hover:text-fg"
                                    >
                                        <X className="size-4" />
                                    </button>
                                )}
                            </div>
                            <Select aria-label="Filter by category" value={cat} onChange={(e) => setCat(e.target.value)} className="sm:w-52">
                                <option value="">All categories</option>
                                {categories.map((c) => (
                                    <option key={c.slug} value={c.slug}>
                                        {c.name}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        <div className="flex flex-wrap gap-1.5" role="group" aria-label="Filter by status">
                            {chips.map((c) => (
                                <button
                                    key={c.value}
                                    type="button"
                                    aria-pressed={status === c.value}
                                    onClick={() => setStatus(c.value)}
                                    className={cn(
                                        'inline-flex h-9 items-center gap-1.5 rounded-full border px-3 text-sm transition-colors focus-visible:outline-2 focus-visible:outline-focus',
                                        status === c.value ? 'border-fg bg-fg text-bg' : 'border-border bg-surface text-muted hover:text-fg',
                                    )}
                                >
                                    {c.label}
                                    <span
                                        className={cn(
                                            'rounded-full px-1.5 text-xs tabular-nums',
                                            status === c.value ? 'bg-bg/20' : 'bg-surface-2',
                                            c.value === 'needs_prices' &&
                                                counts.needs_prices > 0 &&
                                                status !== c.value &&
                                                'bg-warning-soft text-warning',
                                        )}
                                    >
                                        {counts[c.value]}
                                    </span>
                                </button>
                            ))}
                        </div>
                    </div>

                    {products.length === 0 ? (
                        <EmptyState
                            icon={Printer}
                            title="No print products"
                            description="Add the first product customers can order."
                            action={
                                <Button asChild>
                                    <Link href="/admin/catalogue/print/create">Add product</Link>
                                </Button>
                            }
                        />
                    ) : shown.length === 0 ? (
                        <EmptyState
                            icon={Search}
                            title="No products match"
                            description="Try another search or filter."
                            action={
                                filtered ? (
                                    <Button
                                        variant="secondary"
                                        onClick={() => {
                                            setQ('');
                                            setStatus('all');
                                            setCat('');
                                        }}
                                    >
                                        Clear filters
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <Table label="Print products">
                            <THead>
                                <Th>Product</Th>
                                <Th className="hidden 2xl:table-cell">Options</Th>
                                <Th numeric className="hidden sm:table-cell">
                                    From
                                </Th>
                                <Th className="hidden md:table-cell">Status</Th>
                                <Th className="w-0">
                                    <span className="sr-only">Actions</span>
                                </Th>
                            </THead>
                            <tbody>
                                {shown.map((p) => {
                                    return (
                                        <Tr key={p.id} href={`/admin/catalogue/print/${p.id}/edit`}>
                                            <Td>
                                                <div className="flex min-w-0 items-center gap-3">
                                                    <ProductArt product={p} icon={iconOf(p.category)} className="size-10 shrink-0 rounded-[10px] sm:size-12" />
                                                    <div className="min-w-0">
                                                        <p className="truncate font-medium">{p.name}</p>
                                                        <p className="truncate text-xs text-muted">
                                                            {p.category_name ?? p.category} · {p.size_label}
                                                        </p>
                                                        <div className="mt-1 flex items-center gap-2 md:hidden">
                                                            <StatusBadge p={p} />
                                                            <span className="text-xs text-muted sm:hidden">from {cents(p.from_price_cents)}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </Td>
                                            <Td className="hidden text-xs whitespace-nowrap text-muted 2xl:table-cell">
                                                <span className="block">
                                                    {range(p.gsm_options, 'gsm')} ·{' '}
                                                    {p.sides_options.length === 2 ? '1–2 sides' : p.sides_options[0] === 2 ? '2 sides' : '1 side'}
                                                </span>
                                                <span className="flex items-center gap-1">
                                                    {range(p.quantity_options)} pcs
                                                    {p.distributable && (
                                                        <Truck className="ml-1 size-3.5 text-subtle" aria-label="Can be letterboxed" />
                                                    )}
                                                </span>
                                            </Td>
                                            <Td numeric className="hidden sm:table-cell">
                                                {cents(p.from_price_cents)}
                                            </Td>
                                            <Td className="hidden md:table-cell">
                                                <StatusBadge p={p} />
                                            </Td>
                                            <Td>
                                                <div className="flex justify-end gap-1">
                                                    <Button
                                                        size="icon-sm"
                                                        variant="ghost"
                                                        aria-label={p.is_active ? `Hide ${p.name}` : `Put ${p.name} on sale`}
                                                        title={
                                                            p.is_active
                                                                ? 'Hide from customers'
                                                                : p.unpriced > 0
                                                                  ? 'Price every option first'
                                                                  : 'Put on sale'
                                                        }
                                                        disabled={!p.is_active && p.unpriced > 0}
                                                        onClick={() => act.run('post', `/admin/catalogue/print/${p.id}/toggle`)}
                                                    >
                                                        {p.is_active ? <EyeOff /> : <Eye />}
                                                    </Button>
                                                    <Button asChild size="icon-sm" variant="ghost" className="hidden sm:inline-flex">
                                                        <Link href={`/admin/catalogue/print/${p.id}/edit`} aria-label={`Edit ${p.name}`}>
                                                            <Pencil />
                                                        </Link>
                                                    </Button>
                                                </div>
                                            </Td>
                                        </Tr>
                                    );
                                })}
                            </tbody>
                        </Table>
                    )}
                    {filtered && shown.length > 0 && (
                        <p className="text-xs text-muted">
                            Showing {shown.length} of {products.length} products
                        </p>
                    )}
                </div>

                <Card className="xl:self-start">
                    <CardHeader
                        title="Categories"
                        description="Group products in the Print Store, in this order"
                        action={
                            <Button size="sm" variant="secondary" onClick={() => setEditCat('new')}>
                                <Plus /> Add
                            </Button>
                        }
                    />
                    <ul className="divide-y divide-border">
                        {categories.map((c, i) => (
                            <li key={c.id} className="group flex items-center gap-3 px-5 py-2.5">
                                <span
                                    className={cn(
                                        'grid size-9 shrink-0 place-items-center rounded-[10px] border border-border bg-surface-2',
                                        !c.is_active && 'opacity-50',
                                    )}
                                >
                                    <CategoryIcon icon={c.icon} className="size-4" />
                                </span>
                                <button
                                    type="button"
                                    className="min-w-0 flex-1 text-left"
                                    onClick={() => setCat(cat === c.slug ? '' : c.slug)}
                                    aria-pressed={cat === c.slug}
                                    title="Show only this category"
                                >
                                    <span className={cn('block truncate text-sm font-medium', cat === c.slug && 'text-primary-hover')}>
                                        {c.name} {!c.is_active && <Badge className="ml-1">Hidden</Badge>}
                                    </span>
                                    <span className="block text-xs text-muted">
                                        {c.products_count === 1 ? '1 product' : `${c.products_count} products`}
                                    </span>
                                </button>
                                <div className="flex items-center opacity-60 transition-opacity group-hover:opacity-100 focus-within:opacity-100">
                                    <Button
                                        size="icon-sm"
                                        variant="ghost"
                                        aria-label={`Move ${c.name} up`}
                                        disabled={i === 0}
                                        onClick={() => move(i, -1)}
                                    >
                                        <ArrowUp />
                                    </Button>
                                    <Button
                                        size="icon-sm"
                                        variant="ghost"
                                        aria-label={`Move ${c.name} down`}
                                        disabled={i === categories.length - 1}
                                        onClick={() => move(i, 1)}
                                    >
                                        <ArrowDown />
                                    </Button>
                                    <Button size="icon-sm" variant="ghost" aria-label={`Edit ${c.name}`} onClick={() => setEditCat(c)}>
                                        <Pencil />
                                    </Button>
                                    <Button
                                        size="icon-sm"
                                        variant="ghost"
                                        aria-label={`Delete ${c.name}`}
                                        disabled={c.products_count > 0}
                                        title={c.products_count > 0 ? 'Move or delete its products first' : undefined}
                                        onClick={() => setDelCat(c)}
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                </Card>
            </div>

            {editCat && (
                <CategoryDialog
                    category={editCat === 'new' ? null : editCat}
                    icons={icons}
                    nextSort={categories.length}
                    onClose={() => setEditCat(null)}
                />
            )}
            <ConfirmDialog
                open={delCat !== null}
                onOpenChange={(o) => !o && setDelCat(null)}
                title={`Delete ${delCat?.name}?`}
                description="Only empty categories can be deleted."
                confirmLabel="Delete"
                tone="danger"
                processing={act.processing}
                error={act.error}
                onConfirm={() => delCat && act.run('delete', `/admin/catalogue/categories/${delCat.id}`, {}, () => setDelCat(null))}
            />
        </AdminLayout>
    );
}

function CategoryDialog({
    category,
    icons,
    nextSort,
    onClose,
}: {
    category: Category | null;
    icons: string[];
    nextSort: number;
    onClose: () => void;
}) {
    const form = useForm({
        name: category?.name ?? '',
        slug: category?.slug ?? '',
        icon: category?.icon ?? 'printer',
        sort: category?.sort ?? nextSort,
        is_active: category?.is_active ?? true,
    });
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const o = { preserveScroll: true, onSuccess: onClose };
        if (category) form.put(`/admin/catalogue/categories/${category.id}`, o);
        else form.post('/admin/catalogue/categories', o);
    };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent title={category ? `Edit ${category.name}` : 'Add category'} description="Categories group products in the Print Store.">
                <form className="space-y-5" onSubmit={submit}>
                    <div className="flex items-end gap-3">
                        <span className="grid size-11 shrink-0 place-items-center rounded-[12px] border border-border bg-surface-2" aria-hidden>
                            <CategoryIcon icon={form.data.icon} className="size-5" />
                        </span>
                        <Field label="Name (plural, as customers see it)" error={form.errors.name} className="flex-1">
                            <Input
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="e.g. Door hangers"
                                autoFocus
                            />
                        </Field>
                    </div>
                    <div>
                        <p className="mb-2 text-sm font-medium">
                            Icon <span className="font-normal text-muted">· shown when a product has no image</span>
                        </p>
                        <IconPicker
                            id="cat-icon"
                            ariaLabel="Category icon"
                            value={form.data.icon}
                            onChange={(i) => form.setData('icon', i)}
                            icons={icons}
                        />
                        {form.errors.icon && <p className="mt-1 text-sm text-danger">{form.errors.icon}</p>}
                    </div>
                    {category && (
                        <Field label="Key" hint="Used in links and the API; changing it updates its products" error={form.errors.slug}>
                            <Input value={form.data.slug} onChange={(e) => form.setData('slug', e.target.value)} className="font-mono text-sm" />
                        </Field>
                    )}
                    <div className="flex items-center justify-between gap-4 rounded-[12px] border border-border p-3">
                        <Label htmlFor="cat-active">Visible to customers</Label>
                        <Switch id="cat-active" checked={form.data.is_active} onCheckedChange={(c) => form.setData('is_active', c)} />
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            {category ? 'Save' : 'Add category'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
