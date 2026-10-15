import type { GeoJsonPolygon, LngLat } from '@/types';

/** Open ring (no repeated closing vertex) → closed GeoJSON polygon. */
export function toPolygon(points: LngLat[]): GeoJsonPolygon {
    const ring = points.map((p) => [round(p[0]), round(p[1])] as LngLat);
    if (ring.length) ring.push([ring[0][0], ring[0][1]]);
    return { type: 'Polygon', coordinates: [ring] };
}

/** GeoJSON polygon outer ring → open ring. */
export function fromPolygon(geometry: GeoJsonPolygon | null | undefined): LngLat[] {
    const ring = geometry?.coordinates?.[0] ?? [];
    if (ring.length > 1) {
        const a = ring[0];
        const b = ring[ring.length - 1];
        if (a[0] === b[0] && a[1] === b[1]) return ring.slice(0, -1).map((p) => [p[0], p[1]]);
    }
    return ring.map((p) => [p[0], p[1]]);
}

function round(v: number): number {
    return Math.round(v * 1e7) / 1e7;
}

export type Bounds = [number, number, number, number];

export function bounds(points: LngLat[]): Bounds {
    let minX = Infinity,
        minY = Infinity,
        maxX = -Infinity,
        maxY = -Infinity;
    for (const [x, y] of points) {
        if (x < minX) minX = x;
        if (y < minY) minY = y;
        if (x > maxX) maxX = x;
        if (y > maxY) maxY = y;
    }
    return [minX, minY, maxX, maxY];
}

export function pointInPolygon(pt: LngLat, ring: LngLat[]): boolean {
    let inside = false;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        const [xi, yi] = ring[i];
        const [xj, yj] = ring[j];
        if (yi > pt[1] !== yj > pt[1] && pt[0] < ((xj - xi) * (pt[1] - yi)) / (yj - yi) + xi) inside = !inside;
    }
    return inside;
}

function orient(a: LngLat, b: LngLat, c: LngLat): number {
    const v = (b[0] - a[0]) * (c[1] - a[1]) - (b[1] - a[1]) * (c[0] - a[0]);
    return Math.abs(v) < 1e-18 ? 0 : v > 0 ? 1 : -1;
}

function onSeg(a: LngLat, b: LngLat, p: LngLat): boolean {
    return Math.min(a[0], b[0]) <= p[0] && p[0] <= Math.max(a[0], b[0]) && Math.min(a[1], b[1]) <= p[1] && p[1] <= Math.max(a[1], b[1]);
}

export function segmentsIntersect(p1: LngLat, p2: LngLat, p3: LngLat, p4: LngLat): boolean {
    const d1 = orient(p3, p4, p1),
        d2 = orient(p3, p4, p2),
        d3 = orient(p1, p2, p3),
        d4 = orient(p1, p2, p4);
    if (d1 !== d2 && d3 !== d4) return true;
    if (d1 === 0 && onSeg(p3, p4, p1)) return true;
    if (d2 === 0 && onSeg(p3, p4, p2)) return true;
    if (d3 === 0 && onSeg(p1, p2, p3)) return true;
    if (d4 === 0 && onSeg(p1, p2, p4)) return true;
    return false;
}

/** True when any two non-adjacent edges of the (implicitly closed) ring cross. O(n²), fine for ≤150 vertices. */
export function selfIntersects(ring: LngLat[]): boolean {
    const n = ring.length;
    if (n < 4) return false;
    for (let i = 0; i < n; i++) {
        const a1 = ring[i],
            a2 = ring[(i + 1) % n];
        for (let j = i + 1; j < n; j++) {
            if (j === i || (j + 1) % n === i || (i + 1) % n === j) continue; // adjacent edges share a vertex
            if (segmentsIntersect(a1, a2, ring[j], ring[(j + 1) % n])) return true;
        }
    }
    return false;
}

/** Signed planar area (deg²); used for centroid. */
function signedArea(ring: LngLat[]): number {
    let a = 0;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) a += ring[j][0] * ring[i][1] - ring[i][0] * ring[j][1];
    return a / 2;
}

export function centroid(ring: LngLat[]): LngLat {
    const a = signedArea(ring);
    if (Math.abs(a) < 1e-14) {
        const s = ring.reduce((acc, p) => [acc[0] + p[0], acc[1] + p[1]], [0, 0]);
        return [s[0] / ring.length, s[1] / ring.length];
    }
    let cx = 0,
        cy = 0;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        const f = ring[j][0] * ring[i][1] - ring[i][0] * ring[j][1];
        cx += (ring[j][0] + ring[i][0]) * f;
        cy += (ring[j][1] + ring[i][1]) * f;
    }
    return [cx / (6 * a), cy / (6 * a)];
}

/**
 * A point guaranteed inside the polygon (for the area chip): the centroid when it is inside,
 * otherwise the middle of the widest interior span on the horizontal line through the centroid.
 */
export function labelPoint(ring: LngLat[]): LngLat {
    if (ring.length < 3) return ring[0] ?? [0, 0];
    const c = centroid(ring);
    if (pointInPolygon(c, ring)) return c;
    const y = c[1];
    const xs: number[] = [];
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        const [x1, y1] = ring[j];
        const [x2, y2] = ring[i];
        if (y1 > y !== y2 > y) xs.push(x1 + ((y - y1) * (x2 - x1)) / (y2 - y1));
    }
    xs.sort((a, b) => a - b);
    let best: LngLat = ring[0];
    let width = -1;
    for (let k = 0; k + 1 < xs.length; k += 2) {
        if (xs[k + 1] - xs[k] > width) {
            width = xs[k + 1] - xs[k];
            best = [(xs[k] + xs[k + 1]) / 2, y];
        }
    }
    return best;
}

/** Index of the vertex with the greatest latitude (top-most on screen). */
export function topMost(ring: LngLat[]): LngLat | null {
    if (!ring.length) return null;
    return ring.reduce((a, b) => (b[1] > a[1] ? b : a));
}
