import { AdminMap, ConfirmDialog, Dl, useAction } from '@/components/admin/kit';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/form';
import { Alert, Card, CardBody, CardHeader } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { dateTime, num } from '@/lib/utils';
import type { GeoJsonPolygon } from '@/types';
import { Link, router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { BoundaryBadge } from '@/components/admin/badges';

interface Props {
    suburb: { id: number; name: string; state: string; postcode: string | null; boundary_source: string; is_serviced: boolean; locality_pid: string | null; stats: { houses: number; units: number; primary_with_units: number; commercial: number; letterboxes: number; computed_at: string | null } | null };
    parts: GeoJsonPolygon[];
    area_km2: number | null;
    addresses: number;
}

export default function ServiceAreaShow({ suburb, parts, area_km2, addresses }: Props) {
    const [confirm, setConfirm] = useState(false);
    const act = useAction();
    const s = suburb.stats;
    return (
        <AdminLayout
            title={`${suburb.name} ${suburb.state}`}
            actions={
                <Button variant="secondary" onClick={() => setConfirm(true)}>
                    <RefreshCw /> Recompute stats
                </Button>
            }
        >
            <p className="-mt-4 mb-6 text-sm text-muted">
                <Link href="/admin/service-areas" className="hover:underline">Service areas</Link> / {suburb.name}
            </p>
            {suburb.boundary_source === 'derived' && (
                <Alert tone="warning" className="mb-6" title="Approximate boundary">
                    Derived from the G-NAF address points (concave hull). Import official Geoscape locality boundaries with <code>php artisan suburbs:import</code>; localities that are not gazetted have no official boundary.
                </Alert>
            )}
            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
                <Card className="overflow-hidden">
                    <CardHeader title="Boundary" description={area_km2 ? `${area_km2} km²` : undefined} action={<BoundaryBadge source={suburb.boundary_source} />} />
                    {parts.length ? (
                        <AdminMap className="h-[460px] rounded-none border-0" readOnly mode="admin" areas={parts.map((g, i) => ({ id: i, name: parts.length > 1 ? `${suburb.name} (part ${i + 1})` : suburb.name, draw_mode: 'suburb' as const, geometry: g, approximate: suburb.boundary_source === 'derived' }))} />
                    ) : (
                        <CardBody><p className="text-sm text-muted">This suburb has no boundary.</p></CardBody>
                    )}
                </Card>
                <div className="space-y-6">
                    <Card>
                        <CardHeader title="Letterboxes (G-NAF)" description={s?.computed_at ? `Computed ${dateTime(s.computed_at)}` : 'Not computed yet'} />
                        <CardBody>
                            <p className="tabular text-3xl font-semibold">{num(s?.letterboxes ?? 0)}</p>
                            <p className="mb-4 text-xs text-muted">Estimated deliverable letterboxes</p>
                            <Dl className="sm:grid-cols-[1fr_auto]" items={[['Houses', num(s?.houses)], ['Units', num(s?.units)], ['Primaries with units (excluded)', num(s?.primary_with_units)], ['Commercial (excluded)', num(s?.commercial)], ['G-NAF addresses (all)', num(addresses)]]} />
                        </CardBody>
                    </Card>
                    <Card>
                        <CardHeader title="Settings" />
                        <CardBody className="space-y-3 text-sm">
                            <label className="flex items-center justify-between gap-3">
                                <span>Serviced (shown in suburb search)</span>
                                <Switch checked={suburb.is_serviced} onCheckedChange={(c) => router.post(`/admin/service-areas/${suburb.id}/serviced`, { is_serviced: c }, { preserveScroll: true })} />
                            </label>
                            <Dl items={[['Postcode', suburb.postcode], ['Locality PID', suburb.locality_pid]]} />
                        </CardBody>
                    </Card>
                </div>
            </div>
            <ConfirmDialog open={confirm} onOpenChange={setConfirm} title={`Recompute stats for ${suburb.state}?`} description="Re-counts houses, units and letterboxes for every suburb in this state from the address table. Takes a few seconds." confirmLabel="Recompute" processing={act.processing} error={act.error} onConfirm={() => act.run('post', `/admin/service-areas/${suburb.id}/recompute`, {}, () => setConfirm(false))} />
        </AdminLayout>
    );
}
