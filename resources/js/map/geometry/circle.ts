import type { LngLat } from '@/types';

const R = 6371.0088; // mean Earth radius, km
const rad = (d: number) => (d * Math.PI) / 180;
const deg = (r: number) => (r * 180) / Math.PI;

/** Great-circle distance in km. */
export function haversineKm(a: LngLat, b: LngLat): number {
    const dLat = rad(b[1] - a[1]);
    const dLng = rad(b[0] - a[0]);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a[1])) * Math.cos(rad(b[1])) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.min(1, Math.sqrt(h)));
}

/** Point reached by travelling `km` from `origin` on initial bearing `bearingDeg`. */
export function destination(origin: LngLat, km: number, bearingDeg: number): LngLat {
    const d = km / R;
    const b = rad(bearingDeg);
    const lat1 = rad(origin[1]);
    const lng1 = rad(origin[0]);
    const lat2 = Math.asin(Math.sin(lat1) * Math.cos(d) + Math.cos(lat1) * Math.sin(d) * Math.cos(b));
    const lng2 = lng1 + Math.atan2(Math.sin(b) * Math.sin(d) * Math.cos(lat1), Math.cos(d) - Math.sin(lat1) * Math.sin(lat2));
    return [deg(lng2), deg(lat2)];
}

/** Geodesic circle as an open ring of `steps` vertices. */
export function geodesicCircle(center: LngLat, km: number, steps = 64): LngLat[] {
    return Array.from({ length: steps }, (_, i) => destination(center, km, (360 / steps) * i));
}

/** Distance (km) from `p` to segment a–b using a local equirectangular projection around `p`. */
function pointSegmentKm(p: LngLat, a: LngLat, b: LngLat): number {
    const kx = Math.cos(rad(p[1])) * 111.32;
    const ky = 110.574;
    const ax = (a[0] - p[0]) * kx,
        ay = (a[1] - p[1]) * ky;
    const bx = (b[0] - p[0]) * kx,
        by = (b[1] - p[1]) * ky;
    const dx = bx - ax,
        dy = by - ay;
    const len = dx * dx + dy * dy;
    const t = len === 0 ? 0 : Math.max(0, Math.min(1, -(ax * dx + ay * dy) / len));
    return Math.hypot(ax + t * dx, ay + t * dy);
}

/**
 * Client-side mirror of the server's "area must be partly within N km of pickup" rule:
 * true when any edge comes within `km` of the centre, or the centre lies inside the ring.
 */
export function ringTouchesRadius(ring: LngLat[], center: LngLat, km: number, pointInside: (p: LngLat, r: LngLat[]) => boolean): boolean {
    if (ring.length === 0) return false;
    if (ring.length >= 3 && pointInside(center, ring)) return true;
    for (let i = 0; i < ring.length; i++) {
        if (pointSegmentKm(center, ring[i], ring[(i + 1) % ring.length]) <= km) return true;
    }
    return false;
}

/** Polyline length in km (open path). */
export function pathKm(points: LngLat[]): number {
    let d = 0;
    for (let i = 1; i < points.length; i++) d += haversineKm(points[i - 1], points[i]);
    return d;
}
