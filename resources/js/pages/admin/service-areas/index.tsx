import { SearchBox, SelectFilter, Table, TableSkeleton, Td, Th, THead, Toolbar, Tr, useNavigating } from '@/components/admin/kit';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/form';
import { Pagination } from '@/components/ui/navigation';
import { BoundaryBadge } from '@/components/admin/badges';
import { EmptyState } from '@/components/ui/surface';
import { GnafAttribution } from '@/components/brand';
import AdminLayout from '@/layouts/admin-layout';
import { num } from '@/lib/utils';
import type { Paginated } from '@/types';
import { Link, router } from '@inertiajs/react';
import { MapPinned } from 'lucide-react';

interface Row {
    id: number;
    name: string;
    state: string;
    postcode: string | null;
    boundary_source: 'derived' | 'official';
    is_serviced: boolean;
    houses: number | null;
    units: number | null;
    primary_with_units: number | null;
    commercial: number | null;
    letterboxes: number | null;
}

export default function ServiceAreas({ suburbs, filters, states }: { suburbs: Paginated<Row>; filters: { q: string | null; state: string | null; serviced: string | null }; states: string[] }) {
    const busy = useNavigating();
    const filtered = !!(filters.q || filters.state || filters.serviced);
    return (
        <AdminLayout title="Service areas">
            <Toolbar>
                <SearchBox value={filters.q} placeholder="Search suburb or postcode…" />
                <SelectFilter param="state" label="State" value={filters.state} options={states.map((s) => ({ value: s, label: s }))} />
                <SelectFilter param="serviced" label="Serviced" value={filters.serviced} options={[{ value: 'yes', label: 'Serviced' }, { value: 'no', label: 'Not serviced' }]} />
            </Toolbar>
            {busy && suburbs.data.length === 0 ? (
                <TableSkeleton />
            ) : suburbs.data.length === 0 ? (
                <EmptyState icon={MapPinned} title={filtered ? 'No suburbs match' : 'No suburbs yet'} description={filtered ? 'Try another search.' : 'Run make gnaf to import G-NAF and derive suburbs.'} action={filtered && <Button variant="secondary" onClick={() => router.get('/admin/service-areas')}>Clear filters</Button>} />
            ) : (
                <>
                    <Table busy={busy} label="Suburbs">
                        <THead>
                            <Th>Suburb</Th>
                            <Th>State</Th>
                            <Th>Boundary</Th>
                            <Th numeric>Houses</Th>
                            <Th numeric>Units</Th>
                            <Th numeric>Primaries excluded</Th>
                            <Th numeric>Letterboxes</Th>
                            <Th>Serviced</Th>
                        </THead>
                        <tbody>
                            {suburbs.data.map((s) => (
                                <Tr key={s.id} href={`/admin/service-areas/${s.id}`}>
                                    <Td>
                                        <Link href={`/admin/service-areas/${s.id}`} className="font-medium hover:underline">{s.name}</Link>
                                        {s.postcode && <span className="text-muted"> {s.postcode}</span>}
                                    </Td>
                                    <Td>{s.state}</Td>
                                    <Td><BoundaryBadge source={s.boundary_source} /></Td>
                                    <Td numeric>{num(s.houses)}</Td>
                                    <Td numeric>{num(s.units)}</Td>
                                    <Td numeric>{num(s.primary_with_units)}</Td>
                                    <Td numeric className="font-semibold">{num(s.letterboxes)}</Td>
                                    <Td>
                                        <Switch
                                            aria-label={`${s.name} serviced`}
                                            checked={s.is_serviced}
                                            onCheckedChange={(c) => router.post(`/admin/service-areas/${s.id}/serviced`, { is_serviced: c }, { preserveScroll: true, preserveState: true })}
                                        />
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                    <Pagination page={suburbs} />
                </>
            )}
            <p className="mt-6 text-xs text-muted">Counts are G-NAF-derived estimates (houses + units, primaries with units excluded, commercial excluded).</p>
            <GnafAttribution className="mt-2" />
        </AdminLayout>
    );
}
