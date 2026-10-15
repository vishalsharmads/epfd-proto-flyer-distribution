import { Dl } from '@/components/admin/kit';
import { GnafAttribution } from '@/components/brand';
import { Button } from '@/components/ui/button';
import { Alert, Badge, Card, CardBody, CardHeader, Skeleton } from '@/components/ui/surface';
import AdminLayout from '@/layouts/admin-layout';
import { date, num } from '@/lib/utils';
import { Deferred, Link } from '@inertiajs/react';
import { ArrowLeft, ExternalLink } from 'lucide-react';
import type { ReactNode } from 'react';

interface Dataset {
    title: string;
    page: string;
    licence: string | null;
    modified: string | null;
    release: string | null;
    download: { name: string; url: string; size: number | null; published: string | null } | null;
    documents: { name: string; url: string }[];
}
interface Props {
    current: { label: string; states: string[]; is_sample: boolean; imported_at: string | null; crs: string } | null;
    states: string[];
    latest?: { gnaf: Dataset | null; boundaries: Dataset | null };
    coverage: { total: number; official: number; derived: number; unkeyed: number };
    last_boundary_import: { source: string; matched_by: string; created_at: string } | null;
}

/** Reference links, checked Oct 2026. Download links come live from data.gov.au (see OpenDataCatalogue). */
const LINKS = {
    gnaf: 'https://data.gov.au/data/dataset/geocoded-national-address-file-g-naf',
    boundaries: 'https://data.gov.au/data/dataset/geoscape-administrative-boundaries',
    gnafDocs: 'https://docs.geoscape.com.au/projects/gnaf_desc/en/stable/',
    localitiesDocs: 'https://docs.geoscape.com.au/projects/localities_guide/en/stable/',
    eula: 'https://data.gov.au/data/dataset/19432f89-dc3a-4ef3-b943-5326ef1dbecc/resource/09f74802-08b1-4214-a6ea-3591b2753d30/download/20160226-eula-open-g-naf.pdf',
    useRestriction:
        'https://data.gov.au/data/dataset/19432f89-dc3a-4ef3-b943-5326ef1dbecc/resource/9a8f6baa-f790-49a0-84b1-3cb39a6a1b88/download/fact-sheet-open-g-naf-use-restriction.pdf',
    absBoundaries:
        'https://www.abs.gov.au/statistics/standards/australian-statistical-geography-standard-asgs/edition-4-july-2026-june-2031/access-and-downloads/digital-boundary-files',
    absSal: 'https://www.abs.gov.au/statistics/standards/australian-statistical-geography-standard-asgs/edition-4-july-2026-june-2031/non-abs-structures/suburbs-and-localities',
    gda2020: 'https://www.spatial.nsw.gov.au/surveying/geodesy/gda2020',
    gdaShift: 'https://www.ga.gov.au/news/news-archive/australia-officially-moves-1.8-metres-northeast',
    epsg7844: 'https://epsg.io/7844',
    ogr2ogr: 'https://gdal.org/en/stable/programs/ogr2ogr.html',
};

function A({ href, children }: { href: string; children: ReactNode }) {
    return (
        <a
            href={href}
            target="_blank"
            rel="noreferrer"
            className="inline-flex items-center gap-1 font-medium text-primary-hover underline underline-offset-2 break-words"
        >
            {children}
            <ExternalLink className="size-3 shrink-0" aria-hidden />
        </a>
    );
}

function Code({ children }: { children: string }) {
    return <pre className="overflow-x-auto rounded-[10px] bg-surface-2 p-3 text-xs">{children}</pre>;
}

function gb(bytes: number | null | undefined): string {
    return bytes ? `${(bytes / 1e9).toFixed(1)} GB` : '';
}

function LatestCard({
    title,
    dataset,
    installed,
    installedLabel,
}: {
    title: string;
    dataset: Dataset | null | undefined;
    installed: string | null;
    installedLabel: string;
}) {
    if (!dataset) {
        return (
            <Card>
                <CardHeader title={title} />
                <CardBody className="text-sm text-muted">Couldn’t reach data.gov.au. Use the dataset page links below.</CardBody>
            </Card>
        );
    }
    const upToDate = installed !== null && dataset.release !== null && installed.toUpperCase().includes(dataset.release);
    return (
        <Card>
            <CardHeader
                title={title}
                description={<A href={dataset.page}>{dataset.title}</A>}
                action={
                    installed === null ? (
                        <Badge tone="warning">Not imported</Badge>
                    ) : upToDate ? (
                        <Badge tone="success">Up to date</Badge>
                    ) : (
                        <Badge tone="warning">Newer release available</Badge>
                    )
                }
            />
            <CardBody className="space-y-3 text-sm">
                <Dl
                    items={[
                        ['Latest release', dataset.release ?? '—'],
                        [installedLabel, installed ?? '—'],
                        ['Licence', dataset.licence ?? '—'],
                        ['Published', date(dataset.download?.published ?? dataset.modified)],
                    ]}
                />
                {dataset.download && (
                    <Button asChild size="sm">
                        <a href={dataset.download.url} rel="noreferrer" title={dataset.download.name}>
                            Download {dataset.release ?? 'latest'} · GDA2020{dataset.download.size ? ` · ${gb(dataset.download.size)}` : ''}
                        </a>
                    </Button>
                )}
                {dataset.documents.length > 0 && (
                    <ul className="list-inside list-disc text-muted">
                        {dataset.documents.map((d) => (
                            <li key={d.url}>
                                <A href={d.url}>{d.name}</A>
                            </li>
                        ))}
                    </ul>
                )}
            </CardBody>
        </Card>
    );
}

export default function GnafGuide({ current, states, latest, coverage, last_boundary_import }: Props) {
    const stateArg = states.join(',');
    const lower = states.map((s) => s.toLowerCase());
    return (
        <AdminLayout
            title="Import guide"
            actions={
                <Button asChild variant="secondary" size="sm">
                    <Link href="/admin/gnaf">
                        <ArrowLeft className="size-4" aria-hidden /> Data status
                    </Link>
                </Button>
            }
        >
            <div className="space-y-6">
                <p className="max-w-3xl text-sm text-muted">
                    Letterbox counts, address search and suburb boundaries come from two free Geoscape datasets published on data.gov.au every
                    quarter. Import both from the same release, in GDA2020.
                </p>

                <section aria-labelledby="latest" className="space-y-3">
                    <h2 id="latest" className="text-base font-semibold">
                        Latest releases
                    </h2>
                    <Deferred
                        data="latest"
                        fallback={
                            <div className="grid gap-6 lg:grid-cols-2">
                                <Skeleton className="h-56 animate-pulse" />
                                <Skeleton className="h-56 animate-pulse" />
                            </div>
                        }
                    >
                        <div className="grid gap-6 lg:grid-cols-2 [&>*]:min-w-0">
                            <LatestCard
                                title="1 · G-NAF addresses"
                                dataset={latest?.gnaf}
                                installed={current && !current.is_sample ? current.label : null}
                                installedLabel="Installed"
                            />
                            <LatestCard
                                title="2 · Suburb boundaries (Localities)"
                                dataset={latest?.boundaries}
                                installed={last_boundary_import?.source ?? null}
                                installedLabel="Last boundary import"
                            />
                        </div>
                    </Deferred>
                    <p className="text-sm text-muted">
                        Suburbs: {num(coverage.total)} · official boundary {num(coverage.official)} · approximate {num(coverage.derived)}
                        {coverage.unkeyed > 0 && (
                            <>
                                {' '}
                                · {num(coverage.unkeyed)} not linked to a G-NAF locality (run <code>php artisan suburbs:rekey</code>)
                            </>
                        )}
                    </p>
                </section>

                <Card>
                    <CardHeader title="What each dataset is for" />
                    <CardBody className="grid gap-6 text-sm lg:grid-cols-3 [&>*]:min-w-0">
                        <div className="space-y-2">
                            <p className="font-semibold">G-NAF (Geocoded National Address File)</p>
                            <p>
                                Every physical address in Australia with a map point. We use it for live house and unit counts inside drawn areas, the
                                address search, pickup distances and the approximate suburb shapes.
                            </p>
                            <p className="text-muted">
                                Required. Format: PSV text files. <A href={LINKS.gnafDocs}>Product guide</A>
                            </p>
                        </div>
                        <div className="space-y-2">
                            <p className="font-semibold">Geoscape Administrative Boundaries → Localities</p>
                            <p>
                                Official suburb and locality polygons for the “Enter a suburb” draw mode. Each one carries the same locality ID as
                                G-NAF, so every suburb matches exactly, including places that share a name (NSW has five Mayfields).
                            </p>
                            <p className="text-muted">
                                Recommended. Only the <code>LOCALITIES_*</code> folder is used. <A href={LINKS.localitiesDocs}>Localities guide</A>
                            </p>
                        </div>
                        <div className="space-y-2">
                            <p className="font-semibold">ABS Suburbs and Localities (SAL)</p>
                            <p>
                                The ABS’s statistical version of suburbs, built from census mesh blocks. Small localities get merged or dropped, there
                                is no G-NAF locality ID, and the ABS says it is for statistics only.
                            </p>
                            <p className="text-muted">
                                Fallback only, matched by name. <A href={LINKS.absSal}>About SAL</A> ·{' '}
                                <A href={LINKS.absBoundaries}>ABS boundary files (Edition 4)</A>
                            </p>
                        </div>
                    </CardBody>
                </Card>

                <div className="grid gap-6 xl:grid-cols-2 [&>*]:min-w-0">
                    <Card>
                        <CardHeader title="When to import" />
                        <CardBody className="text-sm">
                            <ul className="list-disc space-y-2 pl-5">
                                <li>
                                    <strong>Every quarter.</strong> Geoscape publishes new releases in February, May, August and November. Check this
                                    page after the middle of those months.
                                </li>
                                <li>
                                    <strong>G-NAF first, then boundaries, from the same release month.</strong> New and renamed localities appear in
                                    both at once, so mixing months leaves suburbs unmatched.
                                </li>
                                <li>
                                    <strong>When you add states.</strong> Re-run both steps with the new state list.
                                </li>
                                <li>
                                    <strong>Once, on databases imported before suburbs were keyed by locality ID:</strong> run{' '}
                                    <code>php artisan suburbs:rekey</code> before the boundary import.
                                </li>
                            </ul>
                        </CardBody>
                    </Card>
                    <Card>
                        <CardHeader title="What to watch for" />
                        <CardBody className="text-sm">
                            <ul className="list-disc space-y-2 pl-5">
                                <li>
                                    <strong>Always pick GDA2020</strong>, never GDA94. The app stores <A href={LINKS.epsg7844}>EPSG:7844</A>{' '}
                                    coordinates. GDA94 points sit about <A href={LINKS.gdaShift}>1.8 m south-west</A> of GDA2020 ones.{' '}
                                    <A href={LINKS.gda2020}>About GDA2020</A>
                                </li>
                                <li>
                                    <strong>G-NAF: the PSV download.</strong> Boundaries: the <em>ESRI Shapefile</em> download, not MapInfo TAB.
                                </li>
                                <li>
                                    <strong>Disk and time.</strong> The G-NAF zip is about 1.9 GB and the boundaries zip about 1.2 GB; unzipped needs
                                    several times that. A full NSW + VIC import takes tens of minutes.
                                </li>
                                <li>
                                    <strong>Unmatched features are normal.</strong> Localities with no addresses loaded (other states, national parks)
                                    are listed on the status page.
                                </li>
                                <li>
                                    <strong>Approximate leftovers are expected.</strong> Non-gazetted G-NAF localities have no official polygon
                                    anywhere and keep their approximate shape.
                                </li>
                                <li>
                                    <strong>Shapefile conversion</strong> uses <A href={LINKS.ogr2ogr}>GDAL ogr2ogr</A>, which must be installed.
                                </li>
                            </ul>
                        </CardBody>
                    </Card>
                </div>

                <Card>
                    <CardHeader title="How to import" description={`States: ${stateArg}`} />
                    <CardBody className="space-y-5 text-sm">
                        <div className="space-y-2">
                            <p className="font-semibold">1. G-NAF addresses</p>
                            <p>
                                Download the <em>GDA2020</em> G-NAF zip from <A href={LINKS.gnaf}>data.gov.au</A>, unzip it, and point{' '}
                                <code>.env</code> at the folder. Set the label to the release month.
                            </p>
                            <Code>{`# .env
GNAF_PATH=/data/g-naf_aug26_allstates_gda2020_psv_110
GNAF_RELEASE_LABEL="AUG 2026"

make gnaf STATES=${stateArg}          # php artisan gnaf:import --states=${stateArg}`}</Code>
                        </div>
                        <div className="space-y-2">
                            <p className="font-semibold">2. Official suburb boundaries</p>
                            <p>
                                Download the <em>ESRI Shapefile – GDA2020</em> zip from{' '}
                                <A href={LINKS.boundaries}>Geoscape Administrative Boundaries</A> and unzip it. You only need the{' '}
                                <code>LOCALITIES_*</code> folder.
                            </p>
                            <Code>{`unzip aug26_adminbounds_gda_2020_shp.zip 'LOCALITIES_*' -d /data/adminbounds-aug26

make boundaries STATES=${stateArg} BOUNDARIES_PATH=/data/adminbounds-aug26/LOCALITIES_202608_ALLSTATES_GDA2020_SHP_001-002-001
# = php artisan suburbs:import …/${lower[0] ?? 'nsw'}_localities.shp … --source="Geoscape AUG 2026"`}</Code>
                        </div>
                        <div className="space-y-2">
                            <p className="font-semibold">3. Check</p>
                            <p>
                                Open{' '}
                                <Link href="/admin/gnaf" className="font-medium text-primary-hover underline underline-offset-2">
                                    G-NAF data status
                                </Link>
                                . Each state should show most suburbs with official boundaries, and the last boundary import should be matched by
                                “Locality ID”.
                            </p>
                        </div>
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader title="Licence" />
                    <CardBody className="space-y-3 text-sm">
                        <p>
                            Both datasets are free under Creative Commons Attribution 4.0. G-NAF also carries the{' '}
                            <A href={LINKS.eula}>Open G-NAF EULA</A>, including a <A href={LINKS.useRestriction}>use restriction on mailing</A>: G-NAF
                            on its own must not be used to compile addresses for sending mail unless each address is verified as able to receive mail
                            from a second source (for example, someone physically observing the location).
                        </p>
                        <Alert tone="info" title="Confirm before launch">
                            We only use G-NAF to estimate letterbox counts inside an area; distributors see the actual letterboxes on the street.
                            Confirm with legal that this fits the use restriction, and keep the attribution below visible wherever counts are shown.
                        </Alert>
                        <GnafAttribution />
                    </CardBody>
                </Card>
            </div>
        </AdminLayout>
    );
}
