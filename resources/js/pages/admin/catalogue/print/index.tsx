import { ConfirmDialog, Table, Td, Th, THead, Tr, useAction } from '@/components/admin/kit';
import { CATEGORY_ICONS, CategoryIcon, ProductArt } from '@/components/app/print-bits';
import { Button } from '@/components/ui/button';
import { Field, Input, Label, Select, Switch } from '@/components/ui/form';
import { Dialog, DialogContent } from '@/components/ui/overlay';
import { Badge, Card, CardHeader, EmptyState, StatusBadge } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { money } from '@/lib/utils';
import { Link, useForm } from '@inertiajs/react';
import { Pencil, Plus, Printer, Trash2 } from 'lucide-react';
import { useState } from 'react';

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

export default function PrintProducts({ products, categories, icons }: { products: Product[]; categories: Category[]; icons: string[] }) {
    const [editCat, setEditCat] = useState<Category | 'new' | null>(null);
    const [delCat, setDelCat] = useState<Category | null>(null);
    const act = useAction();
    const iconOf = (slug: string) => categories.find((c) => c.slug === slug)?.icon;

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
                What customers can order under Print. Prices per paper weight, sides and quantity are set in{' '}
                <Link href="/admin/rate-card?tab=matrix" className="font-medium text-primary-hover underline underline-offset-2">
                    Rate card → Print matrix
                </Link>
                ; a product only goes on sale once every option has a price.
            </p>
            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px] [&>*]:min-w-0">
                {products.length === 0 ? (
                    <EmptyState icon={Printer} title="No print products" description="Add the first product customers can order." action={<Button asChild><Link href="/admin/catalogue/print/create">Add product</Link></Button>} />
                ) : (
                    <Table label="Print products">
                        <THead>
                            <Th>Product</Th>
                            <Th>Category</Th>
                            <Th numeric>From</Th>
                            <Th>Status</Th>
                            <Th className="w-0">
                                <span className="sr-only">Actions</span>
                            </Th>
                        </THead>
                        <tbody>
                            {products.map((p) => (
                                <Tr key={p.id} href={`/admin/catalogue/print/${p.id}/edit`}>
                                    <Td>
                                        <div className="flex min-w-0 items-center gap-3">
                                            <ProductArt product={p} icon={iconOf(p.category)} className="size-10 shrink-0 rounded-[8px]" />
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">{p.name}</p>
                                                <p className="truncate text-xs text-muted">{p.size_label}</p>
                                            </div>
                                        </div>
                                    </Td>
                                    <Td className="text-muted">{p.category_name ?? p.category}</Td>
                                    <Td numeric>{p.from_price_cents > 0 ? (p.from_price_cents < 100 ? `${p.from_price_cents}c` : money(p.from_price_cents)) : '—'}</Td>
                                    <Td>
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <StatusBadge status={p.is_active ? 'approved' : 'cancelled'} label={p.is_active ? 'On sale' : 'Hidden'} />
                                            {p.unpriced > 0 && <Badge tone="warning">{p.unpriced} unpriced</Badge>}
                                        </div>
                                    </Td>
                                    <Td onClick={(e) => e.stopPropagation()}>
                                        <Button size="sm" variant="ghost" disabled={!p.is_active && p.unpriced > 0} title={!p.is_active && p.unpriced > 0 ? 'Price every option first' : undefined} onClick={() => act.run('post', `/admin/catalogue/print/${p.id}/toggle`)}>
                                            {p.is_active ? 'Hide' : 'Put on sale'}
                                        </Button>
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                )}

                <Card>
                    <CardHeader
                        title="Categories"
                        description="Group products in the customer catalogue"
                        action={
                            <Button size="sm" variant="secondary" onClick={() => setEditCat('new')}>
                                <Plus /> Add
                            </Button>
                        }
                    />
                    <ul className="divide-y divide-border">
                        {categories.map((c) => (
                            <li key={c.id} className="flex items-center gap-3 px-5 py-3">
                                <CategoryIcon icon={c.icon} className="size-4 shrink-0 text-muted" />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">
                                        {c.name} {!c.is_active && <Badge className="ml-1">Hidden</Badge>}
                                    </p>
                                    <p className="text-xs text-muted">{c.products_count} product(s)</p>
                                </div>
                                <Button size="icon-sm" variant="ghost" aria-label={`Edit ${c.name}`} onClick={() => setEditCat(c)}>
                                    <Pencil />
                                </Button>
                                <Button size="icon-sm" variant="ghost" aria-label={`Delete ${c.name}`} disabled={c.products_count > 0} title={c.products_count > 0 ? 'Has products' : undefined} onClick={() => setDelCat(c)}>
                                    <Trash2 />
                                </Button>
                            </li>
                        ))}
                    </ul>
                </Card>
            </div>

            {editCat && <CategoryDialog category={editCat === 'new' ? null : editCat} icons={icons} nextSort={categories.length} onClose={() => setEditCat(null)} />}
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

function CategoryDialog({ category, icons, nextSort, onClose }: { category: Category | null; icons: string[]; nextSort: number; onClose: () => void }) {
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
            <DialogContent title={category ? `Edit ${category.name}` : 'Add category'}>
                <form className="space-y-4" onSubmit={submit}>
                    <Field label="Name (plural, as customers see it)" error={form.errors.name}>
                        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="e.g. Door hangers" />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Key" optional hint={category ? 'Renaming updates its products' : 'Generated from the name'} error={form.errors.slug}>
                            <Input value={form.data.slug} onChange={(e) => form.setData('slug', e.target.value)} placeholder="door_hanger" />
                        </Field>
                        <Field label="Order" error={form.errors.sort}>
                            <Input type="number" min={0} value={form.data.sort} onChange={(e) => form.setData('sort', Number(e.target.value))} />
                        </Field>
                    </div>
                    <Field label="Icon" error={form.errors.icon} hint="Shown when a product has no image">
                        <Select value={form.data.icon} onChange={(e) => form.setData('icon', e.target.value)}>
                            {icons.filter((i) => CATEGORY_ICONS[i]).map((i) => (
                                <option key={i} value={i}>
                                    {i}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <div className="flex items-center gap-3">
                        <Switch id="cat-active" checked={form.data.is_active} onCheckedChange={(c) => form.setData('is_active', c)} />
                        <Label htmlFor="cat-active">Visible to customers</Label>
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

