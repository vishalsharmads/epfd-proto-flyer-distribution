import { FilterChips, SearchBox, SelectFilter, Table, TableSkeleton, Td, Th, THead, Toolbar, Tr, useNavigating } from '@/components/admin/kit';
import { Button } from '@/components/ui/button';
import { Pagination } from '@/components/ui/navigation';
import { EmptyState, StatusBadge } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { date, money, titleCase } from '@/lib/utils';
import type { Paginated } from '@/types';
import { Link, router } from '@inertiajs/react';
import { Palette } from 'lucide-react';

interface Row {
    id: number;
    reference: string;
    status: string;
    design_type: string;
    type_label: string;
    package: string;
    package_label?: string;
    headline: string | null;
    total_cents: number;
    due_date: string | null;
    revisions_used: number;
    revisions_included: number;
    submitted_at: string | null;
    created_at: string;
    business: { id: number; name: string } | null;
    designer: { id: number; name: string } | null;
}

export const DESIGN_STATUSES = [
    { value: 'awaiting_proof', label: 'Awaiting proof' },
    { value: 'submitted', label: 'Submitted' },
    { value: 'in_design', label: 'In design' },
    { value: 'proof_ready', label: 'Proof ready' },
    { value: 'changes_requested', label: 'Changes requested' },
    { value: 'approved', label: 'Approved' },
    { value: 'completed', label: 'Completed' },
    { value: 'cancelled', label: 'Cancelled' },
];

export default function DesignOrdersIndex({ orders, filters, counts, designers, types: TYPES = [] }: { orders: Paginated<Row>; filters: { status: string | null; type: string | null; q: string | null; designer: string | null }; counts: Record<string, number>; designers: { id: number; name: string }[]; types?: { value: string; label: string }[] }) {
    const busy = useNavigating();
    const filtered = !!(filters.q || filters.status || filters.type || filters.designer);
    return (
        <AdminLayout title="Design orders">
            <Toolbar>
                <SearchBox value={filters.q} placeholder="Search reference, headline or business…" />
                <SelectFilter param="type" value={filters.type} label="Type" options={TYPES} />
                <SelectFilter param="designer" value={filters.designer} label="Designer" options={[{ value: 'unassigned', label: 'Unassigned' }, ...designers.map((d) => ({ value: String(d.id), label: d.name }))]} />
                <FilterChips param="status" value={filters.status} counts={counts} options={DESIGN_STATUSES} />
            </Toolbar>
            {busy && orders.data.length === 0 ? (
                <TableSkeleton />
            ) : orders.data.length === 0 ? (
                <EmptyState
                    icon={Palette}
                    title={filtered ? 'No design orders match' : 'No design orders yet'}
                    description={filtered ? 'Try another status, type or search.' : 'Paid Design Studio orders appear here for the studio to pick up.'}
                    action={filtered && <Button variant="secondary" onClick={() => router.get('/admin/design-orders')}>Clear filters</Button>}
                />
            ) : (
                <>
                    <Table busy={busy} label="Design orders">
                        <THead>
                            <Th>Reference</Th>
                            <Th>Business</Th>
                            <Th>Service</Th>
                            <Th>Designer</Th>
                            <Th>Revisions</Th>
                            <Th>Due</Th>
                            <Th numeric>Total incl GST</Th>
                            <Th>Status</Th>
                        </THead>
                        <tbody>
                            {orders.data.map((o) => (
                                <Tr key={o.id} href={`/admin/design-orders/${o.id}`}>
                                    <Td>
                                        <Link href={`/admin/design-orders/${o.id}`} className="font-medium hover:underline">
                                            {o.reference}
                                        </Link>
                                        {o.headline && <span className="block max-w-56 truncate text-xs text-muted">“{o.headline}”</span>}
                                    </Td>
                                    <Td>{o.business?.name}</Td>
                                    <Td>
                                        {o.type_label} <span className="text-muted">· {o.package_label ?? titleCase(o.package)}</span>
                                    </Td>
                                    <Td>{o.designer?.name ?? <span className="text-muted">Unassigned</span>}</Td>
                                    <Td className="tabular">
                                        {o.revisions_used} / {o.revisions_included >= 99 ? '∞' : o.revisions_included}
                                    </Td>
                                    <Td className="whitespace-nowrap">{o.due_date ? date(o.due_date) : '—'}</Td>
                                    <Td numeric>{money(o.total_cents)}</Td>
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
