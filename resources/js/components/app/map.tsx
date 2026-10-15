import { LazyAudienceBuilder, MapThumbnail, type AudienceState, type BuilderArea, type Coverage } from '@/map';
import { Skeleton } from '@/components/ui/surface';
import { cn } from '@/lib/utils';
import type { GeoJsonPolygon, LngLat } from '@/types';
import { Component, Suspense, type ReactNode } from 'react';

/**
 * Bridge to the map workstream's AudienceBuilder (resources/js/map). Lazy (MapLibre is its own chunk),
 * wrapped in an error boundary that falls back to a static SVG sketch.
 */

export interface AreaLike {
    id?: number | string;
    name?: string;
    draw_mode?: string;
    suburb_id?: number | null;
    geometry: GeoJsonPolygon | null;
    houses?: number;
    units?: number;
}

export type { AudienceState };

/** Server coverage values → map coverage values. */
export const mapCoverage = (c: string): Coverage => (c === 'houses_only' ? 'houses' : c === 'units_only' ? 'units' : 'houses_units');

class Boundary extends Component<{ fallback: ReactNode; children: ReactNode }, { failed: boolean }> {
    state = { failed: false };
    static getDerivedStateFromError() {
        return { failed: true };
    }
    render() {
        return this.state.failed ? this.props.fallback : this.props.children;
    }
}

export function AudienceMap({
    areas,
    pickup,
    includeHouses = true,
    includeUnits = true,
    coverage = 'houses_units',
    material,
    onChange,
    readOnly,
    tracks,
    className,
}: {
    areas: AreaLike[];
    pickup?: LngLat | null;
    pickupLabel?: string | null;
    includeHouses?: boolean;
    includeUnits?: boolean;
    coverage?: string;
    material?: string;
    onChange?: (state: AudienceState) => void;
    readOnly?: boolean;
    tracks?: LngLat[][];
    className?: string;
}) {
    const builderAreas: BuilderArea[] = areas
        .filter((a) => a.geometry)
        .map((a) => ({ id: a.id, name: a.name ?? 'Area', draw_mode: (a.draw_mode as BuilderArea['draw_mode']) ?? 'pins', suburb_id: a.suburb_id ?? null, geometry: a.geometry as GeoJsonPolygon }));
    const fallback = <PolygonSketch areas={areas} tracks={tracks} pickup={pickup} className="size-full min-h-64 rounded-none" />;
    return (
        <div className={cn('relative', className)}>
            <Boundary fallback={fallback}>
                <Suspense fallback={<Skeleton className="absolute inset-0 size-full rounded-none" />}>
                    <LazyAudienceBuilder
                        className="size-full"
                        areas={builderAreas}
                        pickup={pickup ? { lng: pickup[0], lat: pickup[1] } : null}
                        includeHouses={includeHouses}
                        includeUnits={includeUnits}
                        coverage={mapCoverage(coverage)}
                        material={material}
                        onChange={onChange}
                        readOnly={readOnly}
                        tracks={tracks?.map((points) => ({ points }))}
                    />
                </Suspense>
            </Boundary>
        </div>
    );
}

export function MapThumb({ polygons, className, label }: { polygons: (GeoJsonPolygon | null)[]; className?: string; label?: string }) {
    return <MapThumbnail polygons={polygons} size={160} className={cn('size-full rounded-[12px] border-0', className)} label={label ?? `Map of ${polygons.filter(Boolean).length} area(s)`} />;
}

/** Lightweight SVG drawing of polygons (+ optional tracks/pickup). Used as thumbnail and fallback. */
export function PolygonSketch({ polygons, areas, tracks = [], pickup, className, animate = false }: { polygons?: GeoJsonPolygon[]; areas?: AreaLike[]; tracks?: LngLat[][]; pickup?: LngLat | null; className?: string; animate?: boolean }) {
    const polys = polygons ?? (areas ?? []).map((a) => a.geometry).filter((g): g is GeoJsonPolygon => !!g);
    const pts: LngLat[] = [...polys.flatMap((p) => p.coordinates[0] ?? []), ...tracks.flat(), ...(pickup ? [pickup] : [])];
    if (pts.length < 2) {
        return (
            <div className={cn('grid place-items-center rounded-[12px] bg-surface-2 text-xs text-subtle', className)} aria-hidden>
                <svg viewBox="0 0 40 40" className="size-10 opacity-40">
                    <path d="M5 30 L15 10 L25 22 L35 8" fill="none" stroke="currentColor" strokeWidth="2" />
                </svg>
            </div>
        );
    }
    const xs = pts.map((p) => p[0]);
    const ys = pts.map((p) => p[1]);
    const [minX, maxX, minY, maxY] = [Math.min(...xs), Math.max(...xs), Math.min(...ys), Math.max(...ys)];
    const kx = Math.cos((((minY + maxY) / 2) * Math.PI) / 180);
    const W = 320;
    const H = 200;
    const pad = 14;
    const s = Math.min((W - 2 * pad) / Math.max((maxX - minX) * kx, 1e-5), (H - 2 * pad) / Math.max(maxY - minY, 1e-5));
    const ox = (W - (maxX - minX) * kx * s) / 2;
    const oy = (H - (maxY - minY) * s) / 2;
    const proj = (p: LngLat) => `${(ox + (p[0] - minX) * kx * s).toFixed(1)},${(H - oy - (p[1] - minY) * s).toFixed(1)}`;
    return (
        <svg viewBox={`0 0 ${W} ${H}`} className={cn('rounded-[12px] bg-surface-2', className)} role="img" aria-label={`Map sketch of ${polys.length} area${polys.length === 1 ? '' : 's'}`} preserveAspectRatio="xMidYMid meet">
            <defs>
                <pattern id="grid" width="16" height="16" patternUnits="userSpaceOnUse">
                    <path d="M16 0H0V16" fill="none" stroke="currentColor" strokeOpacity="0.06" />
                </pattern>
            </defs>
            <rect width={W} height={H} fill="url(#grid)" className="text-fg" />
            {polys.map((p, i) => (
                <polygon key={i} points={(p.coordinates[0] ?? []).map(proj).join(' ')} className="fill-primary/15 stroke-fg" strokeWidth={1.5} strokeLinejoin="round" />
            ))}
            {tracks.map((t, i) =>
                t.length > 1 ? (
                    <g key={i}>
                        <polyline points={t.map(proj).join(' ')} fill="none" stroke="var(--map-pickup, #2563eb)" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" />
                        <circle r={4.5} fill="var(--map-pickup, #2563eb)" stroke="white" strokeWidth={1.5} transform={`translate(${proj(t[t.length - 1]).replace(',', ' ')})`}>
                            {animate && <animate attributeName="r" values="4;6;4" dur="1.6s" repeatCount="indefinite" />}
                        </circle>
                    </g>
                ) : null,
            )}
            {pickup && <circle r={5} fill="var(--map-pickup, #2563eb)" stroke="white" strokeWidth={2} transform={`translate(${proj(pickup).replace(',', ' ')})`} />}
        </svg>
    );
}

