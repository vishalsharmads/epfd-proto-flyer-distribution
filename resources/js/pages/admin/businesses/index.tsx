import { FilterChips, SearchBox, Table, TableSkeleton, Td, Th, THead, Toolbar, Tr, useNavigating } from '@/components/admin/kit';
import { Button } from '@/components/ui/button';
import { Pagination } from '@/components/ui/navigation';
import { EmptyState, StatusBadge } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { date, num } from '@/lib/utils';
import type { Paginated } from '@/types';
import { Link, router } from '@inertiajs/react';
import { Building2 } from 'lucide-react';

interface Row {
    id: number;
    name: string;
    contact_name: string;
    email: string;
    abn: string | null;
    state: string | null;
    status: string;
    campaigns_count: number;
    print_orders_count: number;
    created_at: string;
}

export default function BusinessesIndex({ businesses, filters, counts }: { businesses: Paginated<Row>; filters: { status: string | null; q: string | null }; counts: Record<string, number> }) {
    const busy = useNavigating();
    return (
        <AdminLayout title="Businesses">
            <Toolbar>
                <SearchBox value={filters.q} placeholder="Search name, email, ABN…" />
                <FilterChips
                    param="status"
                    value={filters.status}
                    counts={counts}
                    options={[
                        { value: 'pending', label: 'Pending' },
                        { value: 'approved', label: 'Approved' },
                        { value: 'suspended', label: 'Suspended' },
                    ]}
                />
            </Toolbar>
            {busy && businesses.data.length === 0 ? (
                <TableSkeleton />
            ) : businesses.data.length === 0 ? (
                <EmptyState
                    icon={Building2}
                    title={filters.q || filters.status ? 'No businesses match' : 'No businesses yet'}
                    description={filters.q || filters.status ? 'Try a different search or clear the filters.' : 'Businesses appear here as soon as they sign up.'}
                    action={(filters.q || filters.status) && <Button variant="secondary" onClick={() => router.get('/admin/businesses')}>Clear filters</Button>}
                />
            ) : (
                <>
                    <Table busy={busy} label="Businesses">
                        <THead>
                            <Th>Business</Th>
                            <Th>Contact</Th>
                            <Th>ABN</Th>
                            <Th>State</Th>
                            <Th numeric>Campaigns</Th>
                            <Th numeric>Print</Th>
                            <Th>Joined</Th>
                            <Th>Status</Th>
                        </THead>
                        <tbody>
                            {businesses.data.map((b) => (
                                <Tr key={b.id} href={`/admin/businesses/${b.id}`}>
                                    <Td>
                                        <Link href={`/admin/businesses/${b.id}`} className="font-medium hover:underline">
                                            {b.name}
                                        </Link>
                                    </Td>
                                    <Td>
                                        <div>{b.contact_name}</div>
                                        <div className="text-xs text-muted">{b.email}</div>
                                    </Td>
                                    <Td className="tabular">{b.abn ? b.abn.replace(/(\d{2})(\d{3})(\d{3})(\d{3})/, '$1 $2 $3 $4') : '—'}</Td>
                                    <Td>{b.state ?? '—'}</Td>
                                    <Td numeric>{num(b.campaigns_count)}</Td>
                                    <Td numeric>{num(b.print_orders_count)}</Td>
                                    <Td className="whitespace-nowrap">{date(b.created_at)}</Td>
                                    <Td>
                                        <StatusBadge status={b.status} />
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                    <Pagination page={businesses} />
                </>
            )}
        </AdminLayout>
    );
}
