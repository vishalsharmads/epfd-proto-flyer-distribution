import { areaColor, tokens } from '@/lib/tokens';
import { cn } from '@/lib/utils';
import type { GeoJsonPolygon } from '@/types';
import { bounds, fromPolygon } from './geometry/polygon';

/**
 * Static SVG of one or more polygons (no map tiles) for lists and summary panels.
 * Colours come from tokens: pass `colorIndex` per polygon or rely on list order.
 */
export function MapThumbnail({ polygons, size = 40, className, colorIndex, label }: { polygons: (GeoJsonPolygon | null | undefined)[]; size?: number; className?: string; colorIndex?: number; label?: string }) {
    const rings = polygons.map(fromPolygon).filter((r) => r.length >= 3);
    const all = rings.flat();
    const pad = 4;
    let body = null;
    if (all.length) {
        const [minX, minY, maxX, maxY] = bounds(all);
        const midLat = ((minY + maxY) / 2) * (Math.PI / 180);
        const kx = Math.cos(midLat);
        const w = Math.max((maxX - minX) * kx, 1e-9);
        const h = Math.max(maxY - minY, 1e-9);
        const s = (size - pad * 2) / Math.max(w, h);
        const ox = (size - w * s) / 2,
            oy = (size - h * s) / 2;
        body = rings.map((r, i) => {
            const d = r.map(([x, y], j) => `${j ? 'L' : 'M'}${(ox + (x - minX) * kx * s).toFixed(1)},${(oy + (maxY - y) * s).toFixed(1)}`).join('') + 'Z';
            const c = areaColor(colorIndex ?? i);
            return <path key={i} d={d} fill={c} fillOpacity={0.22} stroke={tokens.outline} strokeWidth={1.25} strokeLinejoin="round" />;
        });
    }
    return (
        <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} className={cn('shrink-0 rounded-[10px] border border-border bg-surface-2', className)} role={label ? 'img' : undefined} aria-label={label} aria-hidden={label ? undefined : true}>
            {body}
        </svg>
    );
}

export default MapThumbnail;
