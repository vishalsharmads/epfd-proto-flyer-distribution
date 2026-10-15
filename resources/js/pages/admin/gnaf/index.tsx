import { Dl, Table, Td, Th, THead, Tr } from '@/components/admin/kit';
import { GnafAttribution } from '@/components/brand';
import { Alert, Badge, Card, CardBody, CardHeader, EmptyState, StatusBadge } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { dateTime, num, titleCase } from '@/lib/utils';
import { Database } from 'lucide-react';

interface Release {
    id: number;
    label: string;
    crs: string;
    srid: number;
    states: string[];
    is_sample: boolean;
    status: string;
    row_counts: Record<string, number> | null;
    skipped_counts: Record<string, number> | null;
    kind_counts: Record<string, unknown> | null;
    discovery: Record<string, unknown> | null;
    source_path: string | null;
    error: string | null;
    started_at: string | null;
    imported_at: string | null;
}
interface Props {
    current: Release | null;
    releases: Release[];
    by_state: { state: string; total: number; houses: number; units: number; primaries: number; commercial: number }[];
    suburbs: { state: string; total: number; official: number }[];
    imports: { id: number; source: string; path: string; features: number; matched: number; unmatched: string[]; created_at: string }[];
    licence: { attribution: string; url: string; disclaimer: string };
}

function Counts({ data }: { data: Record<string, unknown> | null }) {
    if (!data || Object.keys(data).length === 0) return <p className="text-sm text-muted">Not recorded.</p>;
    const entries = Object.entries(data);
    // Full releases record counts per state ({NSW: {houses: …}}); render each state as its own group.
    if (entries.every(([, v]) => v && typeof v === 'object' && !Array.isArray(v))) {
        return (
            <div className="space-y-4">
                {entries.map(([k, v]) => (
                    <div key={k}>
                        <p className="mb-1.5 text-xs font-semibold tracking-wide text-muted uppercase">{k}</p>
                        <Counts data={v as Record<string, unknown>} />
                    </div>
                ))}
            </div>
        );
    }
    return (
        <dl className="grid min-w-0 grid-cols-[minmax(0,1fr)_auto] gap-x-6 gap-y-1.5 text-sm">
            {entries.map(([k, v]) => (
                <div key={k} className="contents">
                    <dt className="min-w-0 break-words text-muted">{titleCase(k)}</dt>
                    <dd className="tabular text-right">{typeof v === 'number' ? num(v) : typeof v === 'object' ? <code className="text-xs break-all">{JSON.stringify(v)}</code> : String(v)}</dd>
                </div>
            ))}
        </dl>
    );
}

export default function GnafStatus({ current, releases, by_state, suburbs, imports, licence }: Props) {
    const lastImport = imports[0];
    return (
        <AdminLayout title="G-NAF data status">
            {!current ? (
                <EmptyState icon={Database} title="No G-NAF release imported" description={<>Run <code>make gnaf</code> (or <code>php artisan gnaf:import --sample</code>) to load address points.</>} />
            ) : (
                <div className="space-y-6">
                    {current.is_sample && (
                        <Alert tone="warning" title="Sample data">
                            This is the bundled G-NAF sample, not a full release. Letterbox counts only cover the sample area.
                        </Alert>
                    )}
                    <div className="grid gap-6 xl:grid-cols-3 [&>*]:min-w-0">
                        <Card className="xl:col-span-2">
                            <CardHeader title={`Current release · ${current.label}`} action={<StatusBadge status={current.status} />} />
                            <CardBody>
                                <Dl
                                    items={[
                                        ['CRS', `${current.crs} · EPSG:${current.srid} (geographic); distances in GDA2020 MGA zones`],
                                        ['Imported', dateTime(current.imported_at)],
                                        ['States', current.states.join(', ')],
                                        ['Source', <code key="source" className="text-xs break-all">{current.source_path ?? '—'}</code>],
                                        ['Sample', current.is_sample ? <Badge tone="warning">Yes</Badge> : 'No'],
                                    ]}
                                />
                            </CardBody>
                        </Card>
                        <Card>
                            <CardHeader title="Skipped rows" description="Excluded during import" />
                            <CardBody>
                                <Counts data={current.skipped_counts} />
                            </CardBody>
                        </Card>
                    </div>

                    <Card>
                        <CardHeader title="Addresses by state" description="Live counts from the addresses table" />
                        <Table className="rounded-none border-0" label="Addresses by state">
                            <THead>
                                <Th>State</Th>
                                <Th numeric>Addresses</Th>
                                <Th numeric>Houses</Th>
                                <Th numeric>Units</Th>
                                <Th numeric>Primaries with units</Th>
                                <Th numeric>Commercial</Th>
                                <Th numeric>Suburbs</Th>
                                <Th numeric>Official boundaries</Th>
                            </THead>
                            <tbody>
                                {by_state.map((s) => {
                                    const sub = suburbs.find((x) => x.state === s.state);
                                    return (
                                        <Tr key={s.state}>
                                            <Td className="font-medium">{s.state}</Td>
                                            <Td numeric>{num(s.total)}</Td>
                                            <Td numeric>{num(s.houses)}</Td>
                                            <Td numeric>{num(s.units)}</Td>
                                            <Td numeric>{num(s.primaries)}</Td>
                                            <Td numeric>{num(s.commercial)}</Td>
                                            <Td numeric>{num(sub?.total)}</Td>
                                            <Td numeric>{num(sub?.official)}</Td>
                                        </Tr>
                                    );
                                })}
                            </tbody>
                        </Table>
                    </Card>

                    <div className="grid gap-6 xl:grid-cols-3 [&>*]:min-w-0">
                        <Card>
                            <CardHeader title="Kind counts" description="Recorded at import" />
                            <CardBody>
                                <Counts data={current.kind_counts} />
                            </CardBody>
                        </Card>
                        <Card>
                            <CardHeader title="Staged table rows" description="PSV rows loaded per G-NAF table" />
                            <CardBody>
                                <Counts data={current.row_counts} />
                            </CardBody>
                        </Card>
                        <Card>
                            <CardHeader title="Discovery summary" description="What the importer found in the release folder" />
                            <CardBody>
                                {current.discovery ? <pre className="max-h-72 overflow-auto rounded-[10px] bg-surface-2 p-3 text-xs">{JSON.stringify(current.discovery, null, 2)}</pre> : <p className="text-sm text-muted">Not recorded.</p>}
                            </CardBody>
                        </Card>
                    </div>
                </div>
            )}

            <div className="mt-6 grid gap-6 xl:grid-cols-2 [&>*]:min-w-0">
                <Card>
                    <CardHeader title="Official boundary imports" description="php artisan suburbs:import {path} --source=ABS" />
                    {imports.length === 0 ? (
                        <CardBody>
                            <p className="text-sm text-muted">No imports yet. All suburb boundaries are derived (approximate) from G-NAF points.</p>
                        </CardBody>
                    ) : (
                        <CardBody className="space-y-4">
                            {lastImport.unmatched.length > 0 && (
                                <Alert tone="warning" title={`${lastImport.unmatched.length} unmatched name(s) in the last import`}>
                                    <ul className="mt-1 max-h-40 list-inside list-disc overflow-y-auto">
                                        {lastImport.unmatched.slice(0, 200).map((n) => <li key={n}>{n}</li>)}
                                    </ul>
                                </Alert>
                            )}
                            <Table label="Boundary imports">
                                <THead>
                                    <Th>When</Th>
                                    <Th>Source</Th>
                                    <Th numeric>Features</Th>
                                    <Th numeric>Matched</Th>
                                    <Th numeric>Unmatched</Th>
                                </THead>
                                <tbody>
                                    {imports.map((i) => (
                                        <Tr key={i.id}>
                                            <Td className="whitespace-nowrap">{dateTime(i.created_at)}</Td>
                                            <Td><span title={i.path}>{i.source}</span></Td>
                                            <Td numeric>{num(i.features)}</Td>
                                            <Td numeric>{num(i.matched)}</Td>
                                            <Td numeric>{num(i.unmatched.length)}</Td>
                                        </Tr>
                                    ))}
                                </tbody>
                            </Table>
                        </CardBody>
                    )}
                </Card>
                <Card>
                    <CardHeader title="Import history" />
                    {releases.length === 0 ? (
                        <CardBody><p className="text-sm text-muted">No imports recorded.</p></CardBody>
                    ) : (
                        <Table className="rounded-none border-0" label="G-NAF imports">
                            <THead>
                                <Th>Release</Th>
                                <Th>States</Th>
                                <Th>Started</Th>
                                <Th>Status</Th>
                            </THead>
                            <tbody>
                                {releases.map((r) => (
                                    <Tr key={r.id}>
                                        <Td className="font-medium">{r.label}{r.is_sample && <Badge className="ml-2" tone="warning">Sample</Badge>}</Td>
                                        <Td>{r.states.join(', ')}</Td>
                                        <Td className="whitespace-nowrap">{dateTime(r.started_at)}</Td>
                                        <Td><StatusBadge status={r.status === 'running' ? 'pending' : r.status} label={titleCase(r.status)} />{r.error && <p className="mt-1 text-xs text-danger">{r.error}</p>}</Td>
                                    </Tr>
                                ))}
                            </tbody>
                        </Table>
                    )}
                </Card>
            </div>

            <Card className="mt-6">
                <CardHeader title="Licence & attribution" />
                <CardBody className="space-y-3 text-sm">
                    <p>{licence.attribution}</p>
                    <p className="text-muted">{licence.disclaimer}</p>
                    <Alert tone="info" title="Confirm commercial-use terms">
                        G-NAF is released under the Open G-NAF EULA. Before launch, confirm the current licence terms permit this commercial use (letterbox estimation for paid distribution) and keep the attribution visible wherever counts are shown.{' '}
                        <a href={licence.url} target="_blank" rel="noreferrer" className="underline">View licence</a>
                    </Alert>
                    <div>
                        <p className="mb-1 font-medium">Refreshing the data</p>
                        <pre className="overflow-x-auto rounded-[10px] bg-surface-2 p-3 text-xs">{`# full release (set GNAF_PATH in .env to the unzipped G-NAF folder)
make gnaf

# official suburb boundaries (ABS SAL 2021 GeoJSON / Shapefile, GDA2020)
php artisan suburbs:import /path/to/SAL_2021_AUST_GDA2020.shp --source=ABS`}</pre>
                    </div>
                    <GnafAttribution />
                </CardBody>
            </Card>
        </AdminLayout>
    );
}
