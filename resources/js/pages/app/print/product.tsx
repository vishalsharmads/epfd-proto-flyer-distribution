import { fromPrice, ProductArt } from '@/components/app/print-bits';
import type { PrintOrderT, ProductT } from '@/components/app/types';
import { PRINT_STEPS, WizardShell } from '@/components/app/wizard';
import { Alert } from '@/components/ui/surface';
import AppLayout from '@/layouts/app-layout';
import { api, ApiError, cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { Check, Truck } from 'lucide-react';
import { useState } from 'react';

/** Products arrive in category order (admin-managed); group them under their category name. */
function groupByCategory(products: ProductT[]) {
    const groups: { k: string; label: string; items: ProductT[] }[] = [];
    for (const p of products) {
        const g = groups.find((x) => x.k === p.category);
        if (g) g.items.push(p);
        else groups.push({ k: p.category, label: p.category_name ?? p.category, items: [p] });
    }
    return groups;
}

export default function PrintProduct({ order, products }: { order: PrintOrderT; products: ProductT[] }) {
    const [selected, setSelected] = useState<number | null>(order.product?.id ?? null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const groups = groupByCategory(products);

    const choose = async (id: number) => {
        setSelected(id);
        setBusy(true);
        setError(null);
        try {
            await api(`/app/print/${order.id}`, { method: 'PATCH', json: { product_id: id } });
            router.visit(`/app/print/${order.id}/specs`);
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'Could not save your choice. Try again.');
            setBusy(false);
        }
    };

    return (
        <AppLayout title="Choose a product" fullBleed hideFooter>
            <WizardShell steps={PRINT_STEPS} current={1} onStep={() => router.visit('/app/print/new')} back={() => router.visit('/app/print/new')} next={() => selected && choose(selected)} nextDisabled={!selected} nextLoading={busy}>
                <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">Choose a product</h1>
                <p className="mt-1.5 text-muted">Prices include full-colour printing. GST is added at checkout and shown itemised.</p>
                {error && <Alert tone="danger" className="mt-6">{error}</Alert>}
                {groups.map((g) => (
                    <section key={g.k} className="mt-8">
                        <h2 className="mb-3 text-sm font-semibold tracking-wide text-muted uppercase">{g.label}</h2>
                        <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
                            {g.items.map((p) => (
                                <button key={p.id} type="button" data-testid={`product-${p.slug}`} aria-pressed={selected === p.id} disabled={busy} onClick={() => choose(p.id)} className={cn('relative overflow-hidden rounded-[16px] border bg-surface text-left shadow-sm transition-[box-shadow,border] hover:shadow-md focus-visible:outline-2 disabled:opacity-70', selected === p.id ? 'border-fg ring-2 ring-fg/10' : 'border-border')}>
                                    {selected === p.id && <Check className="absolute top-3 right-3 z-10 size-5 rounded-full bg-ink-900 p-0.5 text-white" aria-hidden />}
                                    <ProductArt product={p} className="aspect-[4/3]" />
                                    <div className="p-4">
                                        <p className="font-medium">{p.name}</p>
                                        <p className="text-xs text-muted">{p.size_label}</p>
                                        {p.description && <p className="mt-1.5 line-clamp-2 text-xs text-muted">{p.description}</p>}
                                        <p className="mt-2 flex items-center justify-between text-sm">
                                            <span className="font-semibold">{fromPrice(p)}</span>
                                            {p.distributable && (
                                                <span className="inline-flex items-center gap-1 text-xs text-muted" title="Can be letterboxed by EPFD">
                                                    <Truck className="size-3.5" aria-hidden /> Distributable
                                                </span>
                                            )}
                                        </p>
                                    </div>
                                </button>
                            ))}
                        </div>
                    </section>
                ))}
            </WizardShell>
        </AppLayout>
    );
}
