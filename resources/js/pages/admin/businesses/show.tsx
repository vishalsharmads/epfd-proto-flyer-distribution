import { ConfirmDialog, Dl, ReadOnlyNote, Table, Td, Th, THead, Tr, useAction, useCan } from '@/components/admin/kit';
import { Button } from '@/components/ui/button';
import { Field, Input, Select, Textarea } from '@/components/ui/form';
import { Dialog, DialogContent } from '@/components/ui/overlay';
import { Tabs } from '@/components/ui/navigation';
import { Alert, Card, CardBody, CardHeader, EmptyState, StatusBadge } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { date, dateTime, money, num, titleCase } from '@/lib/utils';
import { Link, useForm } from '@inertiajs/react';
import { Ban, CheckCircle2, Coins, RotateCcw } from 'lucide-react';
import { useState } from 'react';

interface Business {
    id: number;
    name: string;
    contact_name: string;
    email: string;
    mobile: string;
    abn: string | null;
    industry: string | null;
    address: string;
    state: string | null;
    status: string;
    status_note: string | null;
    approved_at: string | null;
    created_at: string;
    users: { id: number; name: string; email: string; mobile: string | null; email_verified_at: string | null; last_login_at: string | null }[];
}
interface Ledger {
    id: number;
    amount_cents: number;
    balance_after_cents: number;
    reason: string;
    created_at: string;
    creator: { name: string } | null;
}
interface Props {
    business: Business;
    credit_balance_cents: number;
    ledger: Ledger[];
    campaigns: { id: number; reference: string; name: string; status: string; letterboxes: number; total_cents: number; created_at: string }[];
    print_orders: { id: number; reference: string; status: string; quantity: number | null; total_cents: number; created_at: string; product: { name: string } | null }[];
    payments: { id: number; payable_type: string; gateway: string; amount_cents: number; credits_applied_cents: number; refunded_cents: number; status: string; card_brand: string | null; card_last4: string | null; paid_at: string | null; created_at: string }[];
}

type StatusAction = 'approve' | 'suspend' | 'reinstate';

export default function BusinessShow({ business, credit_balance_cents, ledger, campaigns, print_orders, payments }: Props) {
    const can = useCan();
    const canManage = can('businesses.manage');
    const [action, setAction] = useState<StatusAction | null>(null);
    const [creditOpen, setCreditOpen] = useState(false);
    const [tab, setTab] = useState('campaigns');
    const act = useAction();

    const actions = canManage && (
        <>
            {business.status === 'pending' && (
                <Button data-testid="approve-business" onClick={() => setAction('approve')}>
                    <CheckCircle2 /> Approve
                </Button>
            )}
            {business.status !== 'suspended' && (
                <Button variant="danger-soft" onClick={() => setAction('suspend')}>
                    <Ban /> Suspend
                </Button>
            )}
            {business.status === 'suspended' && (
                <Button onClick={() => setAction('reinstate')}>
                    <RotateCcw /> Reinstate
                </Button>
            )}
        </>
    );

    return (
        <AdminLayout title={business.name} actions={actions || undefined}>
            <p className="-mt-4 mb-6 text-sm text-muted">
                <Link href="/admin/businesses" className="hover:underline">
                    Businesses
                </Link>{' '}
                / {business.name} · <StatusBadge status={business.status} />
            </p>
            <ReadOnlyNote show={!canManage} />
            {business.status_note && (
                <Alert tone={business.status === 'suspended' ? 'danger' : 'info'} title="Status note" className="mb-6">
                    {business.status_note}
                </Alert>
            )}

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
                <div className="min-w-0 space-y-6">
                    <Card>
                        <CardHeader title="Profile" />
                        <CardBody>
                            <Dl
                                items={[
                                    ['Contact', business.contact_name],
                                    ['Email', <a href={`mailto:${business.email}`} className="text-primary hover:underline">{business.email}</a>],
                                    ['Mobile', business.mobile],
                                    ['ABN', business.abn],
                                    ['Industry', business.industry],
                                    ['Address', business.address],
                                    ['State', business.state],
                                    ['Joined', date(business.created_at)],
                                    ['Approved', business.approved_at ? date(business.approved_at) : null],
                                ]}
                            />
                        </CardBody>
                    </Card>

                    <Card>
                        <CardHeader title="Users" description={`${business.users.length} login(s)`} />
                        {business.users.length === 0 ? (
                            <CardBody>
                                <EmptyState title="No users" />
                            </CardBody>
                        ) : (
                            <Table className="rounded-none border-0" label="Business users">
                                <THead>
                                    <Th>Name</Th>
                                    <Th>Email</Th>
                                    <Th>Mobile</Th>
                                    <Th>Verified</Th>
                                    <Th>Last login</Th>
                                </THead>
                                <tbody>
                                    {business.users.map((u) => (
                                        <Tr key={u.id}>
                                            <Td className="font-medium">{u.name}</Td>
                                            <Td>{u.email}</Td>
                                            <Td>{u.mobile ?? '—'}</Td>
                                            <Td>{u.email_verified_at ? 'Yes' : 'No'}</Td>
                                            <Td>{u.last_login_at ? dateTime(u.last_login_at) : 'Never'}</Td>
                                        </Tr>
                                    ))}
                                </tbody>
                            </Table>
                        )}
                    </Card>

                    <div>
                        <Tabs
                            value={tab}
                            onChange={setTab}
                            tabs={[
                                { value: 'campaigns', label: 'Campaigns', count: campaigns.length },
                                { value: 'print', label: 'Print orders', count: print_orders.length },
                                { value: 'payments', label: 'Payments', count: payments.length },
                            ]}
                        />
                        {tab === 'campaigns' &&
                            (campaigns.length === 0 ? (
                                <EmptyState title="No campaigns yet" />
                            ) : (
                                <Table label="Campaigns">
                                    <THead>
                                        <Th>Reference</Th>
                                        <Th>Name</Th>
                                        <Th numeric>Letterboxes</Th>
                                        <Th numeric>Total</Th>
                                        <Th>Created</Th>
                                        <Th>Status</Th>
                                    </THead>
                                    <tbody>
                                        {campaigns.map((c) => (
                                            <Tr key={c.id} href={c.status !== 'draft' ? `/admin/campaigns/${c.id}` : undefined}>
                                                <Td className="font-medium">{c.status !== 'draft' ? <Link href={`/admin/campaigns/${c.id}`} className="hover:underline">{c.reference}</Link> : c.reference}</Td>
                                                <Td>{c.name}</Td>
                                                <Td numeric>{num(c.letterboxes)}</Td>
                                                <Td numeric>{money(c.total_cents)}</Td>
                                                <Td>{date(c.created_at)}</Td>
                                                <Td><StatusBadge status={c.status} /></Td>
                                            </Tr>
                                        ))}
                                    </tbody>
                                </Table>
                            ))}
                        {tab === 'print' &&
                            (print_orders.length === 0 ? (
                                <EmptyState title="No print orders yet" />
                            ) : (
                                <Table label="Print orders">
                                    <THead>
                                        <Th>Reference</Th>
                                        <Th>Product</Th>
                                        <Th numeric>Qty</Th>
                                        <Th numeric>Total</Th>
                                        <Th>Created</Th>
                                        <Th>Status</Th>
                                    </THead>
                                    <tbody>
                                        {print_orders.map((o) => (
                                            <Tr key={o.id} href={o.status !== 'draft' ? `/admin/print-orders/${o.id}` : undefined}>
                                                <Td className="font-medium">{o.status !== 'draft' ? <Link href={`/admin/print-orders/${o.id}`} className="hover:underline">{o.reference}</Link> : o.reference}</Td>
                                                <Td>{o.product?.name ?? '—'}</Td>
                                                <Td numeric>{num(o.quantity)}</Td>
                                                <Td numeric>{money(o.total_cents)}</Td>
                                                <Td>{date(o.created_at)}</Td>
                                                <Td><StatusBadge status={o.status} /></Td>
                                            </Tr>
                                        ))}
                                    </tbody>
                                </Table>
                            ))}
                        {tab === 'payments' &&
                            (payments.length === 0 ? (
                                <EmptyState title="No payments yet" />
                            ) : (
                                <Table label="Payments">
                                    <THead>
                                        <Th>Date</Th>
                                        <Th>For</Th>
                                        <Th>Method</Th>
                                        <Th numeric>Charged</Th>
                                        <Th numeric>Credits</Th>
                                        <Th numeric>Refunded</Th>
                                        <Th>Status</Th>
                                    </THead>
                                    <tbody>
                                        {payments.map((p) => (
                                            <Tr key={p.id}>
                                                <Td>{date(p.paid_at ?? p.created_at)}</Td>
                                                <Td>{titleCase(p.payable_type)}</Td>
                                                <Td>{p.card_last4 ? `${p.card_brand ?? 'Card'} •••• ${p.card_last4}` : titleCase(p.gateway)}</Td>
                                                <Td numeric>{money(p.amount_cents)}</Td>
                                                <Td numeric>{money(p.credits_applied_cents)}</Td>
                                                <Td numeric>{money(p.refunded_cents)}</Td>
                                                <Td><StatusBadge status={p.status} /></Td>
                                            </Tr>
                                        ))}
                                    </tbody>
                                </Table>
                            ))}
                    </div>
                </div>

                <Card className="h-fit">
                    <CardHeader
                        title="Account credit"
                        description="Applied automatically at checkout"
                        action={
                            can('credits.manage') && (
                                <Button size="sm" variant="secondary" onClick={() => setCreditOpen(true)}>
                                    <Coins /> Adjust
                                </Button>
                            )
                        }
                    />
                    <CardBody>
                        <p className="tabular text-3xl font-semibold tracking-tight">{money(credit_balance_cents)}</p>
                        <p className="text-xs text-muted">Current balance (AUD)</p>
                    </CardBody>
                    {ledger.length === 0 ? (
                        <p className="border-t border-border px-6 py-4 text-sm text-muted">No credit movements yet.</p>
                    ) : (
                        <ul className="max-h-[480px] divide-y divide-border overflow-y-auto border-t border-border">
                            {ledger.map((l) => (
                                <li key={l.id} className="flex items-start justify-between gap-3 px-5 py-3 text-sm sm:px-6">
                                    <div className="min-w-0">
                                        <p className="font-medium break-words">{l.reason}</p>
                                        <p className="text-xs text-muted">
                                            {dateTime(l.created_at)} · {l.creator?.name ?? 'System'}
                                        </p>
                                    </div>
                                    <div className="text-right">
                                        <p className={l.amount_cents < 0 ? 'tabular font-semibold text-danger' : 'tabular font-semibold text-success'}>
                                            {l.amount_cents < 0 ? '−' : '+'}
                                            {money(Math.abs(l.amount_cents))}
                                        </p>
                                        <p className="tabular text-xs text-subtle">bal {money(l.balance_after_cents)}</p>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>

            <ConfirmDialog
                open={action !== null}
                onOpenChange={(o) => !o && setAction(null)}
                title={action === 'approve' ? `Approve ${business.name}?` : action === 'suspend' ? `Suspend ${business.name}?` : `Reinstate ${business.name}?`}
                description={action === 'approve' ? 'They will be able to pay for campaigns and print orders.' : action === 'suspend' ? 'They will not be able to place or pay for new orders. Existing jobs continue.' : 'Their account will be active again.'}
                confirmLabel={action ? titleCase(action) : 'Confirm'}
                tone={action === 'suspend' ? 'danger' : 'primary'}
                note={{ label: action === 'suspend' ? 'Reason (shared with the team)' : 'Note', required: action === 'suspend' }}
                processing={act.processing}
                error={act.error}
                testId="approve-business"
                onConfirm={(note) => act.run('post', `/admin/businesses/${business.id}/status`, { action, note }, () => setAction(null))}
            />
            <CreditDialog open={creditOpen} onOpenChange={setCreditOpen} businessId={business.id} balance={credit_balance_cents} />
        </AdminLayout>
    );
}

function CreditDialog({ open, onOpenChange, businessId, balance }: { open: boolean; onOpenChange: (o: boolean) => void; businessId: number; balance: number }) {
    const form = useForm({ direction: 'add', amount: '', reason: '' });
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent title="Adjust account credit" description={`Current balance ${money(balance)}. Every adjustment is recorded in the ledger and audit log.`}>
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/admin/businesses/${businessId}/credits`, { preserveScroll: true, onSuccess: () => (form.reset(), onOpenChange(false)) });
                    }}
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Type" error={form.errors.direction}>
                            <Select value={form.data.direction} onChange={(e) => form.setData('direction', e.target.value)}>
                                <option value="add">Add credit</option>
                                <option value="deduct">Deduct credit</option>
                            </Select>
                        </Field>
                        <Field label="Amount (AUD)" error={form.errors.amount}>
                            <Input inputMode="decimal" type="number" step="0.01" min="0.01" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} placeholder="0.00" />
                        </Field>
                    </div>
                    <Field label="Reason" error={form.errors.reason} hint="Visible to staff in the ledger.">
                        <Textarea rows={2} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} placeholder="e.g. Goodwill credit for late delivery" />
                    </Field>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={form.processing} disabled={!form.data.amount || form.data.reason.length < 3}>
                            {form.data.direction === 'add' ? 'Add credit' : 'Deduct credit'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
