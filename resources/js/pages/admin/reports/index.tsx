import { DateRange, Table, Td, Th, THead, Toolbar, Tr, useNavigating, TableSkeleton } from '@/components/admin/kit';
import { Button } from '@/components/ui/button';
import { Tabs } from '@/components/ui/navigation';
import { EmptyState } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { money, num, titleCase } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { BarChart3, Download } from 'lucide-react';

interface Props {
    report: string;
    reports: { key: string; title: string; columns: Record<string, string> }[];
    rows: Record<string, string | number | null>[];
    filters: { from?: string; to?: string };
}

function cell(key: string, v: string | number | null) {
    if (v === null || v === undefined) return '—';
    if (key.endsWith('_cents')) return money(Number(v));
    if (key === 'status') return titleCase(String(v));
    if (key === 'avg_coverage') return `${v}%`;
    if (key === 'month' && /^\d{4}-\d{2}$/.test(String(v))) {
        const [y, m] = String(v).split('-').map(Number);
        return new Date(y, m - 1, 1).toLocaleDateString('en-AU', { month: 'short', year: 'numeric' });
    }
    if (typeof v === 'number' || /^\d+$/.test(String(v))) return num(Number(v));
    return String(v);
}

export default function Reports({ report, reports, rows, filters }: Props) {
    const busy = useNavigating();
    const def = reports.find((r) => r.key === report)!;
    const cols = Object.entries(def.columns);
    const qs = new URLSearchParams(Object.entries(filters).filter(([, v]) => v) as [string, string][]).toString();
    const totals = cols.map(([k]) => (k.endsWith('_cents') || ['payments', 'campaigns', 'letterboxes', 'orders', 'pieces', 'runs', 'print_orders'].includes(k) ? rows.reduce((s, r) => s + Number(r[k] ?? 0), 0) : null));
    return (
        <AdminLayout
            title="Reports"
            actions={
                <Button asChild variant="secondary">
                    <a href={`/admin/reports/${report}/export${qs ? `?${qs}` : ''}`} download data-testid="export-report">
                        <Download /> Export CSV
                    </a>
                </Button>
            }
        >
            <Tabs value={report} onChange={(r) => router.get('/admin/reports', { ...filters, report: r }, { preserveScroll: true })} tabs={reports.map((r) => ({ value: r.key, label: r.title }))} />
            <Toolbar>
                <DateRange from={filters.from} to={filters.to} />
                <p className="text-sm text-muted">{filters.from || filters.to ? 'Custom range' : 'Default: last 12 months'} · Australia/Sydney time · amounts in AUD incl GST unless noted</p>
            </Toolbar>
            {busy && rows.length === 0 ? (
                <TableSkeleton />
            ) : rows.length === 0 ? (
                <EmptyState icon={BarChart3} title="No data for this range" description="Widen the date range or pick another report." />
            ) : (
                <Table busy={busy} label={def.title}>
                    <THead>
                        {cols.map(([k, l]) => (
                            <Th key={k} numeric={!['month', 'service', 'status', 'state', 'suburb', 'product', 'business'].includes(k)}>{l}</Th>
                        ))}
                    </THead>
                    <tbody>
                        {rows.map((r, i) => (
                            <Tr key={i}>
                                {cols.map(([k]) => (
                                    <Td key={k} numeric={!['month', 'service', 'status', 'state', 'suburb', 'product', 'business'].includes(k)}>{cell(k, r[k])}</Td>
                                ))}
                            </Tr>
                        ))}
                    </tbody>
                    <tfoot className="sticky bottom-0 bg-surface-2/95 font-semibold">
                        <tr className="border-t border-border">
                            {cols.map(([k], i) => (
                                <Td key={k} numeric={i > 0}>{i === 0 ? 'Total' : totals[i] !== null ? cell(k, totals[i]) : ''}</Td>
                            ))}
                        </tr>
                    </tfoot>
                </Table>
            )}
        </AdminLayout>
    );
}
