import { ConfirmDialog, SearchBox, Table, TableSkeleton, Td, Th, THead, Toolbar, Tr, useAction, useNavigating } from '@/components/admin/kit';
import { Button } from '@/components/ui/button';
import { Checkbox, Field, Input, Select } from '@/components/ui/form';
import { Dialog, DialogContent } from '@/components/ui/overlay';
import { Pagination, Tabs } from '@/components/ui/navigation';
import { Badge, Card, CardHeader, EmptyState } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { dateTime, num, titleCase } from '@/lib/utils';
import type { Paginated, SharedProps } from '@/types';
import { router, useForm, usePage } from '@inertiajs/react';
import { Check, Minus, UserPlus, Users } from 'lucide-react';
import { useState } from 'react';

interface Row {
    id: number;
    name: string;
    email: string;
    mobile: string | null;
    roles: string[];
    last_login_at: string | null;
    created_at: string;
    deactivated_at: string | null;
    runs_count: number | null;
}
interface Props {
    tab: 'staff' | 'distributors';
    users: Paginated<Row>;
    filters: { q: string | null };
    roles: string[];
    permissions: string[];
    matrix: Record<string, string[]>;
}

export default function UsersPage({ tab, users, filters, roles, permissions, matrix }: Props) {
    const busy = useNavigating();
    const me = usePage<SharedProps>().props.auth.user;
    const [create, setCreate] = useState(false);
    const [roleChange, setRoleChange] = useState<{ user: Row; role: string } | null>(null);
    const [deactivate, setDeactivate] = useState<Row | null>(null);
    const act = useAction();
    return (
        <AdminLayout title="Users & roles" actions={<Button onClick={() => setCreate(true)}><UserPlus /> {tab === 'staff' ? 'Invite staff' : 'Add distributor'}</Button>}>
            <Tabs value={tab} onChange={(t) => router.get('/admin/users', { tab: t })} tabs={[{ value: 'staff', label: 'Staff' }, { value: 'distributors', label: 'Distributors' }]} />
            <Toolbar>
                <SearchBox value={filters.q} placeholder="Search name or email…" />
            </Toolbar>
            {busy && users.data.length === 0 ? (
                <TableSkeleton />
            ) : users.data.length === 0 ? (
                <EmptyState icon={Users} title={filters.q ? 'No users match' : tab === 'staff' ? 'No staff yet' : 'No distributors yet'} action={<Button onClick={() => setCreate(true)}>{tab === 'staff' ? 'Invite staff' : 'Add distributor'}</Button>} />
            ) : (
                <>
                    <Table busy={busy} label={tab === 'staff' ? 'Staff users' : 'Distributors'}>
                        <THead>
                            <Th>Name</Th>
                            <Th>Email</Th>
                            {tab === 'staff' ? <Th>Role</Th> : <Th numeric>Runs</Th>}
                            <Th>Last login</Th>
                            <Th>Status</Th>
                            <Th className="w-0"><span className="sr-only">Actions</span></Th>
                        </THead>
                        <tbody>
                            {users.data.map((u) => (
                                <Tr key={u.id}>
                                    <Td className="font-medium">{u.name}{u.id === me?.id && <Badge className="ml-2">You</Badge>}</Td>
                                    <Td>{u.email}{u.mobile && <div className="text-xs text-muted">{u.mobile}</div>}</Td>
                                    {tab === 'staff' ? (
                                        <Td>
                                            {u.deactivated_at ? (
                                                <span className="text-muted">—</span>
                                            ) : (
                                                <Select aria-label={`Role for ${u.name}`} value={u.roles[0] ?? ''} onChange={(e) => setRoleChange({ user: u, role: e.target.value })} className="h-9 w-auto" disabled={u.id === me?.id}>
                                                    {roles.map((r) => <option key={r} value={r}>{titleCase(r)}</option>)}
                                                </Select>
                                            )}
                                        </Td>
                                    ) : (
                                        <Td numeric>{num(u.runs_count)}</Td>
                                    )}
                                    <Td className="whitespace-nowrap">{u.last_login_at ? dateTime(u.last_login_at) : 'Never'}</Td>
                                    <Td>{u.deactivated_at ? <Badge tone="danger">Deactivated</Badge> : <Badge tone="success">Active</Badge>}</Td>
                                    <Td>{!u.deactivated_at && u.id !== me?.id && <Button size="sm" variant="ghost" onClick={() => setDeactivate(u)}>Deactivate</Button>}</Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                    <Pagination page={users} />
                </>
            )}

            {tab === 'staff' && (
                <Card className="mt-8">
                    <CardHeader title="Role permissions" description="Read from the permission tables. Super admin bypasses every check." />
                    <Table className="rounded-none border-0" label="Role permission matrix">
                        <THead>
                            <Th>Permission</Th>
                            {Object.keys(matrix).map((r) => <Th key={r} className="text-center">{titleCase(r)}</Th>)}
                        </THead>
                        <tbody>
                            {permissions.map((p) => (
                                <Tr key={p}>
                                    <Td><code className="text-xs">{p}</code></Td>
                                    {Object.entries(matrix).map(([r, perms]) => (
                                        <Td key={r} className="text-center">
                                            {perms.includes(p) ? <Check className="inline size-4 text-success" aria-label="Allowed" /> : <Minus className="inline size-4 text-subtle" aria-label="Not allowed" />}
                                        </Td>
                                    ))}
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                </Card>
            )}

            {create && <CreateUser kind={tab === 'staff' ? 'staff' : 'distributor'} roles={roles} onClose={() => setCreate(false)} />}
            <ConfirmDialog open={roleChange !== null} onOpenChange={(o) => !o && setRoleChange(null)} title={`Change ${roleChange?.user.name}'s role?`} description={`New role: ${titleCase(roleChange?.role ?? '')}. Their permissions change immediately.`} confirmLabel="Change role" processing={act.processing} error={act.error} onConfirm={() => roleChange && act.run('post', `/admin/users/${roleChange.user.id}/role`, { role: roleChange.role }, () => setRoleChange(null))} />
            <ConfirmDialog open={deactivate !== null} onOpenChange={(o) => !o && setDeactivate(null)} title={`Deactivate ${deactivate?.name}?`} description="Removes all roles, signs them out of the API and resets their password. You can re-invite them later." confirmLabel="Deactivate" tone="danger" processing={act.processing} error={act.error} onConfirm={() => deactivate && act.run('post', `/admin/users/${deactivate.id}/deactivate`, {}, () => setDeactivate(null))} />
        </AdminLayout>
    );
}

function CreateUser({ kind, roles, onClose }: { kind: 'staff' | 'distributor'; roles: string[]; onClose: () => void }) {
    const form = useForm({ kind, name: '', email: '', mobile: '', role: kind === 'staff' ? 'support' : '', send_invite: true });
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent title={kind === 'staff' ? 'Invite a staff member' : 'Add a distributor'} description="They'll receive an email to set their password.">
                <form className="space-y-4" onSubmit={(e) => (e.preventDefault(), form.post('/admin/users', { preserveScroll: true, onSuccess: onClose }))}>
                    <Field label="Full name" error={form.errors.name}><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoComplete="off" /></Field>
                    <Field label="Email" error={form.errors.email}><Input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} autoComplete="off" /></Field>
                    <Field label="Mobile" optional error={form.errors.mobile}><Input type="tel" value={form.data.mobile} onChange={(e) => form.setData('mobile', e.target.value)} placeholder="0412 345 678" /></Field>
                    {kind === 'staff' && (
                        <Field label="Role" error={form.errors.role}>
                            <Select value={form.data.role} onChange={(e) => form.setData('role', e.target.value)}>
                                {roles.map((r) => <option key={r} value={r}>{titleCase(r)}</option>)}
                            </Select>
                        </Field>
                    )}
                    <label className="flex items-center gap-2 text-sm"><Checkbox checked={form.data.send_invite} onCheckedChange={(c) => form.setData('send_invite', c === true)} /> Send password set-up email</label>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                        <Button type="submit" loading={form.processing}>{kind === 'staff' ? 'Invite' : 'Add distributor'}</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
