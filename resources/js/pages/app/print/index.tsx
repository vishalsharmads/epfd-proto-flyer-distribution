import { fromPrice, printStepUrl, ProductArt } from '@/components/app/print-bits';
import type { PrintOrderT, ProductT } from '@/components/app/types';
import { Button } from '@/components/ui/button';
import { Tabs } from '@/components/ui/navigation';
import { Badge, Card, EmptyState, PageHeader, StatusBadge } from '@/components/ui/surface';
import AppLayout from '@/layouts/app-layout';
import { date, money } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { Plus, Printer, Truck } from 'lucide-react';
import { useState } from 'react';

export default function PrintHub({ orders, products }: { orders: PrintOrderT[]; products: ProductT[] }) {
    const [tab, setTab] = useState(orders.length ? 'orders' : 'new');
    return (
        <AppLayout title="Print Store">
            <PageHeader
                title="Print Store"
                description="Flyers, brochures, magnets and more — printed and delivered, or letterboxed by EPFD."
                actions={
                    <Button asChild>
                        <Link href="/app/print/new">
                            <Plus /> New Print Order
                        </Link>
                    </Button>
                }
            />
            <Tabs value={tab} onChange={setTab} tabs={[{ value: 'orders', label: 'My Orders', count: orders.length }, { value: 'new', label: 'New Order' }]} />
            {tab === 'orders' ? (
                orders.length === 0 ? (
                    <EmptyState icon={Printer} title="No print orders yet" description="Order flyers from 3c a piece and add letterbox distribution in the same checkout." action={<Button onClick={() => setTab('new')}>Browse products</Button>} />
                ) : (
                    <Card className="divide-y divide-border">
                        {orders.map((o) => (
                            <Link key={o.id} href={o.status === 'draft' ? printStepUrl(o) : `/app/jobs/print/${o.id}`} className="flex items-center gap-4 px-4 py-3.5 hover:bg-surface-2 sm:px-5">
                                {o.product ? <ProductArt product={o.product} className="size-12 shrink-0 rounded-[10px]" /> : <div className="size-12 rounded-[10px] bg-surface-2" />}
                                <div className="min-w-0 flex-1">
                                    <p className="truncate font-medium">{o.name}</p>
                                    <p className="text-xs text-muted">
                                        {o.reference} · {date(o.submitted_at ?? o.updated_at)}
                                        {o.also_distribute && (
                                            <span className="ml-2 inline-flex items-center gap-1">
                                                <Truck className="size-3" aria-hidden /> + distribution
                                            </span>
                                        )}
                                    </p>
                                </div>
                                <span className="tabular hidden text-sm font-medium sm:block">{money(o.total_cents)}</span>
                                {o.status === 'draft' ? <Badge>Draft</Badge> : <StatusBadge status={o.status} />}
                            </Link>
                        ))}
                    </Card>
                )
            ) : (
                <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
                    {products.map((p) => (
                        <button key={p.id} type="button" data-testid={`product-${p.slug}`} onClick={() => router.post('/app/print', { product_id: p.id })} className="overflow-hidden rounded-[16px] border border-border bg-surface text-left shadow-sm transition-shadow hover:shadow-md focus-visible:outline-2">
                            <ProductArt product={p} className="aspect-[4/3]" />
                            <div className="p-4">
                                <p className="font-medium">{p.name}</p>
                                <p className="text-xs text-muted">{p.size_label}</p>
                                <p className="mt-2 text-sm font-semibold">{fromPrice(p)}</p>
                            </div>
                        </button>
                    ))}
                </div>
            )}
        </AppLayout>
    );
}
