import { tokens } from '@/lib/tokens';
import { api } from '@/lib/utils';
import type { LngLat } from '@/types';
import { useEffect, useRef, useState } from 'react';
import { bounds, pointInPolygon } from '../geometry/polygon';
import { useMapTick } from '../hooks/useMapTick';
import type { MapProvider } from '../providers';

interface Feature {
    geometry: { coordinates: LngLat };
    properties: { kind: 'house' | 'unit' };
}
interface Collection {
    features: Feature[];
    capped?: boolean;
}

export interface AddressStatus {
    zoomedOut: boolean;
    loading: boolean;
    count: number;
    capped: boolean;
    error: boolean;
}

/**
 * G-NAF address points drawn on a canvas: houses = dots, units = squares.
 * Without `clip`: the current viewport (zoom ≥ minZoom). With `clip` (the selected area, or all areas): only addresses
 * inside those polygons. When they fit the API's bbox limit they are fetched whole at any zoom; otherwise per viewport.
 */
export function AddressLayer({ provider, minZoom, onStatus, clip = null }: { provider: MapProvider; minZoom: number; onStatus: (s: AddressStatus) => void; clip?: LngLat[][] | null }) {
    const tick = useMapTick(provider);
    const canvas = useRef<HTMLCanvasElement>(null);
    const [data, setData] = useState<Feature[]>([]);
    const statusRef = useRef(onStatus);
    statusRef.current = onStatus;
    const rings = clip?.filter((r) => r.length >= 3) ?? [];
    const clipKey = rings.map((r) => r.map((p) => p.join(',')).join(';')).join('|');

    useEffect(() => {
        let ctrl: AbortController | null = null;
        let timer: ReturnType<typeof setTimeout> | undefined;
        const load = () => {
            ctrl?.abort();
            const area = rings.length ? bounds(rings.flat()) : null;
            const areaFits = !!area && area[2] - area[0] <= 0.049 && area[3] - area[1] <= 0.049;
            if (!areaFits && provider.getZoom() < minZoom) {
                setData([]);
                statusRef.current({ zoomedOut: true, loading: false, count: 0, capped: false, error: false });
                return;
            }
            let [w, s, e, n] = areaFits ? area! : provider.getBounds();
            // API rejects bboxes wider than 0.05°: clamp around the centre.
            const cx = (w + e) / 2,
                cy = (s + n) / 2;
            if (e - w > 0.049) [w, e] = [cx - 0.0245, cx + 0.0245];
            if (n - s > 0.049) [s, n] = [cy - 0.0245, cy + 0.0245];
            const c = new AbortController();
            ctrl = c;
            statusRef.current({ zoomedOut: false, loading: true, count: 0, capped: false, error: false });
            api<Collection>(`/api/v1/addresses/viewport?bbox=${[w, s, e, n].map((v) => v.toFixed(6)).join(',')}&kind=all`, { signal: c.signal })
                .then((fc) => {
                    const inside = rings.length ? fc.features.filter((f) => rings.some((r) => pointInPolygon(f.geometry.coordinates, r))) : fc.features;
                    setData(inside);
                    statusRef.current({ zoomedOut: false, loading: false, count: inside.length, capped: !!fc.capped, error: false });
                })
                .catch(() => {
                    if (!c.signal.aborted) statusRef.current({ zoomedOut: false, loading: false, count: 0, capped: false, error: true });
                });
        };
        const debounced = () => {
            clearTimeout(timer);
            timer = setTimeout(load, 250);
        };
        // A fitted area is fetched once per shape; otherwise follow the viewport.
        const off = provider.on('moveend', debounced);
        if (rings.length) debounced();
        else load();
        return () => {
            off();
            clearTimeout(timer);
            ctrl?.abort();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [provider, minZoom, clipKey]);

    useEffect(() => {
        const cv = canvas.current;
        if (!cv) return;
        const dpr = window.devicePixelRatio || 1;
        const { width, height } = cv.getBoundingClientRect();
        if (cv.width !== Math.round(width * dpr)) cv.width = Math.round(width * dpr);
        if (cv.height !== Math.round(height * dpr)) cv.height = Math.round(height * dpr);
        const ctx = cv.getContext('2d');
        if (!ctx) return;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, width, height);
        const house = tokens.house,
            unit = tokens.unit;
        for (const f of data) {
            const q = provider.project(f.geometry.coordinates);
            if (q.x < -5 || q.y < -5 || q.x > width + 5 || q.y > height + 5) continue;
            if (f.properties.kind === 'unit') {
                ctx.fillStyle = unit;
                ctx.fillRect(q.x - 2.5, q.y - 2.5, 5, 5);
            } else {
                ctx.fillStyle = house;
                ctx.beginPath();
                ctx.arc(q.x, q.y, 2.75, 0, Math.PI * 2);
                ctx.fill();
            }
        }
    }, [data, tick, provider]);

    return <canvas ref={canvas} className="pointer-events-none absolute inset-0 z-[4] size-full" aria-hidden />;
}
