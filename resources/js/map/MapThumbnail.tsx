import { areaColor, tokens } from '@/lib/tokens';
import { cn } from '@/lib/utils';
import type { GeoJsonPolygon, LngLat, SharedProps } from '@/types';
import { usePage } from '@inertiajs/react';
import { useId } from 'react';
import { fromPolygon } from './geometry/polygon';

const TILE = 256;
const MAX_ZOOM = 18;
/** Supersampling: tiles are fetched one zoom higher and drawn at half size so thumbnails stay crisp when stretched or on high-DPI screens. */
const K = 2;

/** Web Mercator: lon/lat → world pixels at zoom z. */
function project(lng: number, lat: number, z: number): [number, number] {
    const scale = TILE * 2 ** z;
    const s = Math.sin((Math.max(-85.05, Math.min(85.05, lat)) * Math.PI) / 180);
    return [((lng + 180) / 360) * scale, (0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI)) * scale];
}

function tileUrl(template: string, z: number, x: number, y: number): string {
    return template.replace('{z}', String(z)).replace('{x}', String(x)).replace('{y}', String(y)).replace('{s}', 'a');
}

function useTileTemplate(): string | undefined {
    try {
        return usePage<SharedProps>().props.map?.base_tile_url;
    } catch {
        return undefined; // rendered outside an Inertia page
    }
}

export interface MapThumbnailProps {
    polygons: (GeoJsonPolygon | null | undefined)[];
    /** Square size in px (used when width/height are not given). */
    size?: number;
    width?: number;
    height?: number;
    className?: string;
    colorIndex?: number;
    label?: string;
    /** Draw base-map tiles behind the polygons. Default: on when the thumbnail is ≥ 64 px. */
    tiles?: boolean;
    /** GPS tracks ([lng, lat] lists); the latest point is drawn as a dot. */
    tracks?: LngLat[][];
    pickup?: LngLat | null;
    /** Pulse the latest GPS point (respects prefers-reduced-motion via CSS). */
    animate?: boolean;
}

/**
 * Static map thumbnail for lists, summary panels and run cards: the same base-map raster tiles as the live
 * map (Web Mercator, zoom chosen to fit, toned to the calm grey look; inverted in dark mode) with polygons,
 * optional GPS tracks and pickup on top. Small list icons (< 64 px) stay tile-free.
 */
export function MapThumbnail({ polygons, size = 40, width, height, className, colorIndex, label, tiles, tracks = [], pickup, animate = false }: MapThumbnailProps) {
    const clipId = useId();
    const template = useTileTemplate();
    const W = width ?? size;
    const H = height ?? size;

    const rings = polygons.map(fromPolygon).filter((r) => r.length >= 3);
    const fitPts: LngLat[] = [...rings.flat(), ...tracks.flat(), ...(pickup ? [pickup] : [])];
    const showTiles = (tiles ?? Math.min(W, H) >= 64) && !!template && fitPts.length > 0;
    const pad = Math.max(4, Math.round(Math.min(W, H) * 0.08));

    let tileEls: React.ReactNode = null;
    let overlay: React.ReactNode = null;

    if (fitPts.length) {
        const xs = fitPts.map((p) => p[0]);
        const ys = fitPts.map((p) => p[1]);
        const [minX, maxX, minY, maxY] = [Math.min(...xs), Math.max(...xs), Math.min(...ys), Math.max(...ys)];
        // Largest zoom at which everything fits inside the padded frame.
        let z = MAX_ZOOM;
        for (; z > 1; z--) {
            const [x0, y0] = project(minX, maxY, z);
            const [x1, y1] = project(maxX, minY, z);
            if ((x1 - x0) / K <= W - pad * 2 && (y1 - y0) / K <= H - pad * 2) break;
        }
        const [ax, ay] = project(minX, maxY, z);
        const [bx, by] = project(maxX, minY, z);
        // World pixels at zoom z, divided by K = SVG units.
        const left = (ax + bx) / 2 / K - W / 2;
        const top = (ay + by) / 2 / K - H / 2;
        const T = TILE / K;
        const pt = ([lng, lat]: LngLat): [number, number] => {
            const [px, py] = project(lng, lat, z);
            return [px / K - left, py / K - top];
        };
        const pts = (r: LngLat[]) => r.map((p) => pt(p).map((v) => v.toFixed(1)).join(',')).join(' ');

        if (showTiles && template) {
            const n = 2 ** z;
            const imgs: React.ReactNode[] = [];
            for (let tx = Math.floor(left / T); tx <= Math.floor((left + W) / T); tx++) {
                for (let ty = Math.floor(top / T); ty <= Math.floor((top + H) / T); ty++) {
                    if (ty < 0 || ty >= n) continue;
                    const wx = ((tx % n) + n) % n;
                    imgs.push(<image key={`${tx}-${ty}`} href={tileUrl(template, z, wx, ty)} x={tx * T - left} y={ty * T - top} width={T} height={T} preserveAspectRatio="none" />);
                }
            }
            tileEls = (
                <g className="[filter:grayscale(0.85)_brightness(1.04)_contrast(0.92)] dark:[filter:grayscale(0.9)_invert(0.92)_hue-rotate(180deg)_brightness(0.95)]" clipPath={`url(#${clipId})`}>
                    {imgs}
                </g>
            );
        }

        overlay = (
            <>
                {rings.map((r, i) => (
                    <polygon
                        key={`a${i}`}
                        points={pts(r)}
                        fill={areaColor(colorIndex ?? i)}
                        fillOpacity={showTiles ? 0.3 : 0.22}
                        stroke={tokens.outline}
                        strokeWidth={Math.min(W, H) >= 64 ? 1.5 : 1.25}
                        strokeDasharray={showTiles ? '4 3' : undefined}
                        strokeLinejoin="round"
                    />
                ))}
                {tracks.map((t, i) => {
                    if (t.length < 2) return null;
                    const [lx, ly] = pt(t[t.length - 1]);
                    return (
                        <g key={`t${i}`}>
                            <polyline points={pts(t)} fill="none" stroke={tokens.pickup} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" />
                            <circle cx={lx} cy={ly} r={4.5} fill={tokens.pickup} stroke="white" strokeWidth={1.5} className={animate ? 'origin-center animate-pulse motion-reduce:animate-none' : undefined} />
                        </g>
                    );
                })}
                {pickup && (() => {
                    const [px, py] = pt(pickup);
                    return <circle cx={px} cy={py} r={5} fill={tokens.pickup} stroke="white" strokeWidth={2} />;
                })()}
            </>
        );
    }

    return (
        <svg
            width={W}
            height={H}
            viewBox={`0 0 ${W} ${H}`}
            preserveAspectRatio="xMidYMid slice"
            className={cn('shrink-0 rounded-[10px] border border-border bg-surface-2', className)}
            role={label ? 'img' : undefined}
            aria-label={label}
            aria-hidden={label ? undefined : true}
        >
            <defs>
                <clipPath id={clipId}>
                    <rect width={W} height={H} />
                </clipPath>
            </defs>
            {tileEls}
            {overlay}
        </svg>
    );
}

export default MapThumbnail;
