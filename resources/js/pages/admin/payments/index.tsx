import { DateRange, SearchBox, SelectFilter, Table, TableSkeleton, Td, Th, THead, Toolbar, Tr, useCan, useNavigating } from '@/components/admin/kit';
import { RefundDialog, type RefundablePayment } from '@/components/admin/refund-dialog';
import { Button } from '@/components/ui/button';
import { Pagination, Tabs } from '@/components/ui/navigation';
import { EmptyState, StatusBadge } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { date, dateTime, money, titleCase } from '@/lib/utils';
import type { Paginated } from '@/types';
import { Link, router } from '@inertiajs/react';
import { Download, FileText, Receipt } from 'lucide-react';
import { useState } from 'react';

interface PaymentRow extends RefundablePayment {
    gateway: string;
    gateway_ref: string | null;
    status: string;
    card_brand: string | null;
    card_last4: string | null;
    failure_reason: string | null;
    paid_at: string | null;
    created_at: string;
    business: { id: number; name: string } | null;
    payable: { type: string; id: number; reference: string | null };
    invoice: { id: number; number: string } | null;
}
interface InvoiceRow {
    id: number;
    number: string;
    status: string;
    subtotal_cents: number;
    gst_cents: number;
    total_cents: number;
    credits_applied_cents: number;
    issued_at: string;
    business: { id: number; name: string } | null;
}
interface Props {
    tab: 'payments' | 'invoices';
    payments: Paginated<PaymentRow> | null;
    invoices: Paginated<InvoiceRow> | null;
    filters: Record<string, string | undefined>;
    gateways: string[];
}

export default function Payments({ tab, payments, invoices, filters, gateways }: Props) {
    const can = useCan();
    const busy = useNavigating();
    const [refund, setRefund] = useState<RefundablePayment | null>(null);
    const qs = new URLSearchParams(Object.entries(filters).filter(([, v]) => v) as [string, string][]).toString();
    const filtered = Object.values(filters).some(Boolean);
    return (
        <AdminLayout
            title="Payments & invoices"
            actions={
                <Button asChild variant="secondary">
                    <a href={`/admin/${tab === 'payments' ? 'payments' : 'invoices'}/export${qs ? `?${qs}` : ''}`} download>
                        <Download /> Export CSV
                    </a>
                </Button>
            }
        >
            <Tabs value={tab} onChange={(t) => router.get('/admin/payments', { tab: t })} tabs={[{ value: 'payments', label: 'Payments' }, { value: 'invoices', label: 'Invoices' }]} />
            <Toolbar>
                <SearchBox value={filters.q} placeholder={tab === 'payments' ? 'Search business or gateway ref…' : 'Search invoice number or business…'} />
                {tab === 'payments' ? (
                    <>
                        <SelectFilter param="status" label="Status" value={filters.status} options={['succeeded', 'pending', 'failed', 'partially_refunded', 'refunded'].map((s) => ({ value: s, label: titleCase(s) }))} />
                        <SelectFilter param="gateway" label="Gateway" value={filters.gateway} options={gateways.map((g) => ({ value: g, label: titleCase(g) }))} />
                    </>
                ) : (
                    <SelectFilter param="invoice_status" label="Status" value={filters.invoice_status} options={['paid', 'refunded', 'void'].map((s) => ({ value: s, label: titleCase(s) }))} />
                )}
                <DateRange from={filters.from} to={filters.to} />
            </Toolbar>

            {tab === 'payments' && payments && (
                busy && payments.data.length === 0 ? <TableSkeleton /> :
                payments.data.length === 0 ? (
                    <EmptyState icon={Receipt} title={filtered ? 'No payments match' : 'No payments yet'} action={filtered && <Button variant="secondary" onClick={() => router.get('/admin/payments')}>Clear filters</Button>} />
                ) : (
                    <>
                        <Table busy={busy} label="Payments">
                            <THead>
                                <Th>Date</Th>
                                <Th>Business</Th>
                                <Th>For</Th>
                                <Th>Method</Th>
                                <Th numeric>Charged</Th>
                                <Th numeric>Credits</Th>
                                <Th numeric>Refunded</Th>
                                <Th>Status</Th>
                                <Th>Invoice</Th>
                                <Th className="w-0"><span className="sr-only">Actions</span></Th>
                            </THead>
                            <tbody>
                                {payments.data.map((p) => (
                                    <Tr key={p.id}>
                                        <Td className="whitespace-nowrap">{dateTime(p.paid_at ?? p.created_at)}</Td>
                                        <Td>{p.business && <Link href={`/admin/businesses/${p.business.id}`} className="hover:underline">{p.business.name}</Link>}</Td>
                                        <Td>
                                            {p.payable.reference ? (
                                                <Link className="hover:underline" href={p.payable.type === 'campaign' ? `/admin/campaigns/${p.payable.id}` : `/admin/print-orders/${p.payable.id}`}>{p.payable.reference}</Link>
                                            ) : titleCase(p.payable.type)}
                                        </Td>
                                        <Td>
                                            {p.card_last4 ? `${p.card_brand ?? 'Card'} •••• ${p.card_last4}` : titleCase(p.gateway)}
                                            {p.gateway_ref && <div className="max-w-40 truncate text-xs text-subtle" title={p.gateway_ref}>{p.gateway_ref}</div>}
                                        </Td>
                                        <Td numeric>{money(p.amount_cents)}</Td>
                                        <Td numeric>{money(p.credits_applied_cents)}</Td>
                                        <Td numeric>{money(p.refunded_cents)}</Td>
                                        <Td><StatusBadge status={p.status} />{p.failure_reason && <div className="mt-1 text-xs text-danger">{p.failure_reason}</div>}</Td>
                                        <Td>{p.invoice ? <a className="text-primary hover:underline" href={`/admin/invoices/${p.invoice.id}/pdf`} target="_blank" rel="noreferrer">{p.invoice.number}</a> : '—'}</Td>
                                        <Td>
                                            {can('payments.refund') && ['succeeded', 'partially_refunded'].includes(p.status) && p.refunded_cents < p.amount_cents + p.credits_applied_cents && (
                                                <Button size="sm" variant="secondary" onClick={() => setRefund(p)}>Refund</Button>
                                            )}
                                        </Td>
                                    </Tr>
                                ))}
                            </tbody>
                        </Table>
                        <Pagination page={payments} />
                    </>
                )
            )}

            {tab === 'invoices' && invoices && (
                busy && invoices.data.length === 0 ? <TableSkeleton /> :
                invoices.data.length === 0 ? (
                    <EmptyState icon={FileText} title={filtered ? 'No invoices match' : 'No invoices yet'} action={filtered && <Button variant="secondary" onClick={() => router.get('/admin/payments?tab=invoices')}>Clear filters</Button>} />
                ) : (
                    <>
                        <Table busy={busy} label="Invoices">
                            <THead>
                                <Th>Invoice</Th>
                                <Th>Issued</Th>
                                <Th>Business</Th>
                                <Th numeric>Subtotal ex GST</Th>
                                <Th numeric>GST</Th>
                                <Th numeric>Total incl GST</Th>
                                <Th>Status</Th>
                                <Th className="w-0"><span className="sr-only">PDF</span></Th>
                            </THead>
                            <tbody>
                                {invoices.data.map((i) => (
                                    <Tr key={i.id}>
                                        <Td className="font-medium">{i.number}</Td>
                                        <Td className="whitespace-nowrap">{date(i.issued_at)}</Td>
                                        <Td>{i.business?.name}</Td>
                                        <Td numeric>{money(i.subtotal_cents)}</Td>
                                        <Td numeric>{money(i.gst_cents)}</Td>
                                        <Td numeric>{money(i.total_cents)}</Td>
                                        <Td><StatusBadge status={i.status} /></Td>
                                        <Td>
                                            <Button asChild size="sm" variant="ghost">
                                                <a href={`/admin/invoices/${i.id}/pdf`} target="_blank" rel="noreferrer"><FileText /> PDF</a>
                                            </Button>
                                        </Td>
                                    </Tr>
                                ))}
                            </tbody>
                        </Table>
                        <Pagination page={invoices} />
                    </>
                )
            )}
            <RefundDialog payment={refund} open={refund !== null} onOpenChange={(o) => !o && setRefund(null)} />
        </AdminLayout>
    );
}
