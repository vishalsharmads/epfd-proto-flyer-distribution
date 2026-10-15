import { FilterChips, SearchBox, Table, TableSkeleton, Td, Th, THead, Toolbar, Tr, useNavigating } from '@/components/admin/kit';
import { Button } from '@/components/ui/button';
import { Pagination } from '@/components/ui/navigation';
import { EmptyState, StatusBadge } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { date, money, num } from '@/lib/utils';
import type { Paginated } from '@/types';
import { Link, router } from '@inertiajs/react';
import { Printer } from 'lucide-react';

interface Row {
    id: number;
    reference: string;
    status: string;
    quantity: number | null;
    gsm: number | null;
    sides: number;
    total_cents: number;
    submitted_at: string | null;
    created_at: string;
    business: { id: number; name: string } | null;
    product: { id: number; name: string } | null;
}

export const PRINT_STATUSES = [
    { value: 'submitted', label: 'Submitted' },
    { value: 'awaiting_artwork_approval', label: 'Awaiting artwork' },
    { value: 'in_printing', label: 'In printing' },
    { value: 'shipped', label: 'Shipped' },
    { value: 'completed', label: 'Completed' },
    { value: 'cancelled', label: 'Cancelled' },
];

export default function PrintOrdersIndex({ orders, filters, counts }: { orders: Paginated<Row>; filters: { status: string | null; q: string | null }; counts: Record<string, number> }) {
    const busy = useNavigating();
    const filtered = !!(filters.q || filters.status);
    return (
        <AdminLayout title="Print orders">
            <Toolbar>
                <SearchBox value={filters.q} placeholder="Search reference or business…" />
                <FilterChips param="status" value={filters.status} counts={counts} options={PRINT_STATUSES} />
            </Toolbar>
            {busy && orders.data.length === 0 ? (
                <TableSkeleton />
            ) : orders.data.length === 0 ? (
                <EmptyState
                    icon={Printer}
                    title={filtered ? 'No print orders match' : 'No print orders yet'}
                    description={filtered ? 'Try another status or search.' : 'Paid print orders from businesses appear here for artwork review.'}
                    action={filtered && <Button variant="secondary" onClick={() => router.get('/admin/print-orders')}>Clear filters</Button>}
                />
            ) : (
                <>
                    <Table busy={busy} label="Print orders">
                        <THead>
                            <Th>Reference</Th>
                            <Th>Business</Th>
                            <Th>Product</Th>
                            <Th numeric>Qty</Th>
                            <Th numeric>Total incl GST</Th>
                            <Th>Submitted</Th>
                            <Th>Status</Th>
                        </THead>
                        <tbody>
                            {orders.data.map((o) => (
                                <Tr key={o.id} href={`/admin/print-orders/${o.id}`}>
                                    <Td>
                                        <Link href={`/admin/print-orders/${o.id}`} className="font-medium hover:underline">
                                            {o.reference}
                                        </Link>
                                    </Td>
                                    <Td>{o.business?.name}</Td>
                                    <Td>
                                        {o.product?.name ?? '—'}
                                        {o.gsm && <span className="text-muted"> · {o.gsm}gsm · {o.sides === 2 ? 'double' : 'single'}-sided</span>}
                                    </Td>
                                    <Td numeric>{num(o.quantity)}</Td>
                                    <Td numeric>{money(o.total_cents)}</Td>
                                    <Td className="whitespace-nowrap">{date(o.submitted_at ?? o.created_at)}</Td>
                                    <Td>
                                        <StatusBadge status={o.status} />
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                    <Pagination page={orders} />
                </>
            )}
        </AdminLayout>
    );
}
