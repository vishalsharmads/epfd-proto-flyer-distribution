import { DateRange, JsonDiff, SelectFilter, Table, TableSkeleton, Td, Th, THead, Toolbar, Tr, useNavigating } from '@/components/admin/kit';
import { Button } from '@/components/ui/button';
import { Pagination } from '@/components/ui/navigation';
import { Dialog, DialogContent } from '@/components/ui/overlay';
import { EmptyState } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { dateTime, titleCase } from '@/lib/utils';
import type { Paginated } from '@/types';
import { router } from '@inertiajs/react';
import { History } from 'lucide-react';
import { useState } from 'react';

interface Log {
    id: number;
    action: string;
    subject: string | null;
    auditable_id: number | null;
    changes: Record<string, unknown> | null;
    ip: string | null;
    user_agent: string | null;
    created_at: string;
    user: { id: number; name: string; email: string } | null;
}

export default function AuditLog({ logs, filters, actions, subjects, users }: { logs: Paginated<Log>; filters: Record<string, string | undefined>; actions: string[]; subjects: string[]; users: { id: number; name: string }[] }) {
    const busy = useNavigating();
    const [open, setOpen] = useState<Log | null>(null);
    const prefixes = Array.from(new Set(actions.map((a) => a.split('.')[0])));
    const filtered = Object.values(filters).some(Boolean);
    return (
        <AdminLayout title="Audit log">
            <Toolbar>
                <SelectFilter param="action" label="Action" value={filters.action} options={[...prefixes.map((p) => ({ value: p + '.', label: `${titleCase(p)} (all)` })), ...actions.map((a) => ({ value: a, label: a }))]} />
                <SelectFilter param="user" label="User" value={filters.user} options={users.map((u) => ({ value: String(u.id), label: u.name }))} />
                <SelectFilter param="subject" label="Subject" value={filters.subject} options={subjects.map((s) => ({ value: s, label: titleCase(s) }))} />
                <DateRange from={filters.from} to={filters.to} />
            </Toolbar>
            {busy && logs.data.length === 0 ? (
                <TableSkeleton />
            ) : logs.data.length === 0 ? (
                <EmptyState icon={History} title={filtered ? 'No entries match' : 'No audit entries yet'} action={filtered && <Button variant="secondary" onClick={() => router.get('/admin/audit-log')}>Clear filters</Button>} />
            ) : (
                <>
                    <Table busy={busy} label="Audit log">
                        <THead>
                            <Th>When</Th>
                            <Th>User</Th>
                            <Th>Action</Th>
                            <Th>Subject</Th>
                            <Th>IP</Th>
                            <Th className="w-0"><span className="sr-only">Details</span></Th>
                        </THead>
                        <tbody>
                            {logs.data.map((l) => (
                                <Tr key={l.id} onClick={() => setOpen(l)}>
                                    <Td className="whitespace-nowrap">{dateTime(l.created_at)}</Td>
                                    <Td>{l.user?.name ?? <span className="text-muted">System</span>}</Td>
                                    <Td><code className="text-xs">{l.action}</code></Td>
                                    <Td>{l.subject ? `${titleCase(l.subject)} #${l.auditable_id}` : '—'}</Td>
                                    <Td className="text-muted">{l.ip ?? '—'}</Td>
                                    <Td><Button size="sm" variant="ghost" onClick={(e) => (e.stopPropagation(), setOpen(l))}>View</Button></Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                    <Pagination page={logs} />
                </>
            )}
            <Dialog open={open !== null} onOpenChange={(o) => !o && setOpen(null)}>
                {open && (
                    <DialogContent wide title={open.action} description={`${open.user?.name ?? 'System'} · ${dateTime(open.created_at)}${open.subject ? ` · ${titleCase(open.subject)} #${open.auditable_id}` : ''}`}>
                        <JsonDiff changes={open.changes} />
                        {open.user_agent && <p className="mt-4 text-xs break-all text-subtle">{open.user_agent}</p>}
                    </DialogContent>
                )}
            </Dialog>
        </AdminLayout>
    );
}
