import { FilterChips, SearchBox, SelectFilter, Table, TableSkeleton, Td, Th, THead, Toolbar, Tr, useNavigating } from '@/components/admin/kit';
import { Button } from '@/components/ui/button';
import { Pagination } from '@/components/ui/navigation';
import { Badge, EmptyState, StatusBadge } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { dateTime } from '@/lib/utils';
import type { Paginated } from '@/types';
import { Link, router } from '@inertiajs/react';
import { Inbox } from 'lucide-react';

interface Row {
    id: number;
    subject: string;
    status: string;
    priority: string;
    channel: string;
    last_message_at: string | null;
    created_at: string;
    messages_count: number;
    business: { id: number; name: string } | null;
    user: { name: string } | null;
    assignee: { name: string } | null;
}

export default function SupportIndex({ threads, filters, counts }: { threads: Paginated<Row>; filters: Record<string, string | undefined>; counts: Record<string, number> }) {
    const busy = useNavigating();
    const filtered = Object.values(filters).some(Boolean);
    return (
        <AdminLayout title="Support inbox">
            <Toolbar>
                <SearchBox value={filters.q} placeholder="Search subject or business…" />
                <FilterChips param="status" value={filters.status} counts={counts} options={[{ value: 'open', label: 'Open' }, { value: 'pending', label: 'Pending' }, { value: 'resolved', label: 'Resolved' }]} />
                <SelectFilter param="channel" label="Channel" value={filters.channel} options={[{ value: 'support', label: 'Support' }, { value: 'job', label: 'Job messages' }]} />
                <SelectFilter param="assignee" label="Assignee" value={filters.assignee} options={[{ value: 'me', label: 'Assigned to me' }, { value: 'none', label: 'Unassigned' }]} />
            </Toolbar>
            {busy && threads.data.length === 0 ? (
                <TableSkeleton />
            ) : threads.data.length === 0 ? (
                <EmptyState icon={Inbox} title={filtered ? 'No threads match' : 'Inbox zero'} description={filtered ? 'Try different filters.' : 'New support messages from businesses appear here.'} action={filtered && <Button variant="secondary" onClick={() => router.get('/admin/support')}>Clear filters</Button>} />
            ) : (
                <>
                    <Table busy={busy} label="Support threads">
                        <THead>
                            <Th>Subject</Th>
                            <Th>Business</Th>
                            <Th>Channel</Th>
                            <Th>Assignee</Th>
                            <Th>Last message</Th>
                            <Th>Status</Th>
                        </THead>
                        <tbody>
                            {threads.data.map((t) => (
                                <Tr key={t.id} href={`/admin/support/${t.id}`}>
                                    <Td>
                                        <Link href={`/admin/support/${t.id}`} className="font-medium hover:underline">{t.subject}</Link>
                                        <div className="text-xs text-muted">{t.messages_count} message(s){t.priority !== 'normal' && <Badge tone={['high', 'urgent'].includes(t.priority) ? 'danger' : 'neutral'} className="ml-2">{t.priority}</Badge>}</div>
                                    </Td>
                                    <Td>{t.business?.name ?? t.user?.name ?? '—'}</Td>
                                    <Td><Badge tone={t.channel === 'job' ? 'info' : 'neutral'}>{t.channel === 'job' ? 'Job' : 'Support'}</Badge></Td>
                                    <Td>{t.assignee?.name ?? <span className="text-subtle">Unassigned</span>}</Td>
                                    <Td className="whitespace-nowrap">{dateTime(t.last_message_at ?? t.created_at)}</Td>
                                    <Td><StatusBadge status={t.status} /></Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                    <Pagination page={threads} />
                </>
            )}
        </AdminLayout>
    );
}
