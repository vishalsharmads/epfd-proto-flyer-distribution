import { selfIntersects } from './polygon';

export interface Pt {
    x: number;
    y: number;
}

function perpDistSq(p: Pt, a: Pt, b: Pt): number {
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    const len = dx * dx + dy * dy;
    if (len === 0) return (p.x - a.x) ** 2 + (p.y - a.y) ** 2;
    const t = Math.max(0, Math.min(1, ((p.x - a.x) * dx + (p.y - a.y) * dy) / len));
    return (p.x - (a.x + t * dx)) ** 2 + (p.y - (a.y + t * dy)) ** 2;
}

/** Iterative Douglas–Peucker simplification (tolerance in the same units as the points, e.g. screen px). */
export function douglasPeucker(points: Pt[], tolerance: number): Pt[] {
    if (points.length <= 2) return points.slice();
    const keep = new Uint8Array(points.length);
    keep[0] = keep[points.length - 1] = 1;
    const tolSq = tolerance * tolerance;
    const stack: [number, number][] = [[0, points.length - 1]];
    while (stack.length) {
        const [first, last] = stack.pop()!;
        let maxD = 0;
        let idx = -1;
        for (let i = first + 1; i < last; i++) {
            const d = perpDistSq(points[i], points[first], points[last]);
            if (d > maxD) {
                maxD = d;
                idx = i;
            }
        }
        if (idx !== -1 && maxD > tolSq) {
            keep[idx] = 1;
            stack.push([first, idx], [idx, last]);
        }
    }
    return points.filter((_, i) => keep[i]);
}

/**
 * Simplify a freehand trace into a polygon ring: drop near-duplicate samples, run DP with
 * `tolerance` px and keep increasing it until there are at most `maxVertices` vertices.
 */
export function simplifyTrace(points: Pt[], tolerance = 5, maxVertices = 150): Pt[] {
    const dedup: Pt[] = [];
    for (const p of points) {
        const last = dedup[dedup.length - 1];
        if (!last || Math.hypot(p.x - last.x, p.y - last.y) >= 1.5) dedup.push(p);
    }
    let tol = tolerance;
    let out = douglasPeucker(dedup, tol);
    while (out.length > maxVertices && tol < 500) {
        tol *= 1.5;
        out = douglasPeucker(dedup, tol);
    }
    // Drop the closing duplicate if the trace ended on its start.
    if (out.length > 3) {
        const a = out[0];
        const b = out[out.length - 1];
        if (Math.hypot(a.x - b.x, a.y - b.y) < tolerance) out = out.slice(0, -1);
    }
    return out;
}

/**
 * Freehand traces usually overshoot or stop short of their start, so the closing edge crosses the
 * outline. Trim points from the tail (then the head) until the ring no longer self-intersects,
 * giving up after a quarter of the points (the user then sees the self-intersection error).
 */
export function untangleTrace(points: Pt[]): Pt[] {
    const ring = (pts: Pt[]) => pts.map((q) => [q.x, q.y] as [number, number]);
    if (!selfIntersects(ring(points))) return points;
    const limit = Math.max(1, Math.floor(points.length / 4));
    for (let head = 0; head <= limit; head++) {
        for (let tail = 0; tail <= limit; tail++) {
            const cand = points.slice(head, points.length - tail);
            if (cand.length >= 3 && !selfIntersects(ring(cand))) return cand;
        }
    }
    return points;
}
