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
    return <MapThumbnail polygons={polygons} size={240} className={cn('size-full rounded-[12px] border-0', className)} label={label ?? `Map of ${polygons.filter(Boolean).length} area(s)`} />;
}

/** Lightweight SVG drawing of polygons (+ optional tracks/pickup). Used as thumbnail and fallback. */
export function PolygonSketch({ polygons, areas, tracks = [], pickup, className, animate = false }: { polygons?: GeoJsonPolygon[]; areas?: AreaLike[]; tracks?: LngLat[][]; pickup?: LngLat | null; className?: string; animate?: boolean }) {
    const polys = polygons ?? (areas ?? []).map((a) => a.geometry).filter((g): g is GeoJsonPolygon => !!g);
    if (polys.length + tracks.flat().length + (pickup ? 1 : 0) < 2 && !polys.length) {
        return (
            <div className={cn('grid place-items-center rounded-[12px] bg-surface-2 text-xs text-subtle', className)} aria-hidden>
                <svg viewBox="0 0 40 40" className="size-10 opacity-40">
                    <path d="M5 30 L15 10 L25 22 L35 8" fill="none" stroke="currentColor" strokeWidth="2" />
                </svg>
            </div>
        );
    }
    // Same tiled renderer as every other map thumbnail (base-map tiles + areas + GPS tracks).
    return (
        <MapThumbnail
            polygons={polys}
            tracks={tracks}
            pickup={pickup}
            animate={animate}
            width={320}
            height={200}
            className={cn('size-full rounded-[12px] border-0', className)}
            label={`Map of ${polys.length} area${polys.length === 1 ? '' : 's'}${tracks.length ? ' with live GPS track' : ''}`}
        />
    );
}

