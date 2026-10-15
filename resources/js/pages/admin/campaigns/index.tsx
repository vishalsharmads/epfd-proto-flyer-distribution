import { FilterChips, SearchBox, Table, TableSkeleton, Td, Th, THead, Toolbar, Tr, useNavigating } from '@/components/admin/kit';
import { Button } from '@/components/ui/button';
import { Pagination } from '@/components/ui/navigation';
import { EmptyState, StatusBadge } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { date, money, num } from '@/lib/utils';
import type { Paginated } from '@/types';
import { Link, router } from '@inertiajs/react';
import { Send } from 'lucide-react';

interface Row {
    id: number;
    reference: string;
    name: string;
    status: string;
    letterboxes: number;
    total_cents: number;
    available_from: string | null;
    submitted_at: string | null;
    created_at: string;
    runs_count: number;
    business: { id: number; name: string } | null;
}

export const CAMPAIGN_STATUSES = [
    { value: 'submitted', label: 'Submitted' },
    { value: 'runs_published', label: 'Runs published' },
    { value: 'allocated', label: 'Allocated' },
    { value: 'collected', label: 'Collected' },
    { value: 'in_delivery', label: 'In delivery' },
    { value: 'completed', label: 'Completed' },
    { value: 'cancelled', label: 'Cancelled' },
];

export default function CampaignsIndex({ campaigns, filters, counts }: { campaigns: Paginated<Row>; filters: { status: string | null; q: string | null }; counts: Record<string, number> }) {
    const busy = useNavigating();
    const filtered = !!(filters.q || filters.status);
    return (
        <AdminLayout title="Distribution campaigns">
            <Toolbar>
                <SearchBox value={filters.q} placeholder="Search reference, name, business…" />
                <FilterChips param="status" value={filters.status} counts={counts} options={CAMPAIGN_STATUSES} />
            </Toolbar>
            {busy && campaigns.data.length === 0 ? (
                <TableSkeleton />
            ) : campaigns.data.length === 0 ? (
                <EmptyState
                    icon={Send}
                    title={filtered ? 'No campaigns match' : 'No submitted campaigns yet'}
                    description={filtered ? 'Try another status or search.' : 'Paid distribution campaigns appear here, ready to split into runs.'}
                    action={filtered && <Button variant="secondary" onClick={() => router.get('/admin/campaigns')}>Clear filters</Button>}
                />
            ) : (
                <>
                    <Table busy={busy} label="Distribution campaigns">
                        <THead>
                            <Th>Reference</Th>
                            <Th>Campaign</Th>
                            <Th>Business</Th>
                            <Th numeric>Letterboxes</Th>
                            <Th numeric>Runs</Th>
                            <Th numeric>Total incl GST</Th>
                            <Th>Available from</Th>
                            <Th>Status</Th>
                        </THead>
                        <tbody>
                            {campaigns.data.map((c) => (
                                <Tr key={c.id} href={`/admin/campaigns/${c.id}`}>
                                    <Td>
                                        <Link href={`/admin/campaigns/${c.id}`} className="font-medium hover:underline">
                                            {c.reference}
                                        </Link>
                                    </Td>
                                    <Td>{c.name}</Td>
                                    <Td>{c.business?.name}</Td>
                                    <Td numeric>{num(c.letterboxes)}</Td>
                                    <Td numeric>{c.runs_count || <span className="text-warning">0</span>}</Td>
                                    <Td numeric>{money(c.total_cents)}</Td>
                                    <Td className="whitespace-nowrap">{date(c.available_from)}</Td>
                                    <Td>
                                        <StatusBadge status={c.status} />
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                    <Pagination page={campaigns} />
                </>
            )}
        </AdminLayout>
    );
}
