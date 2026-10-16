import { token } from '@/lib/tokens';
import type { LngLat } from '@/types';
import { useEffect, useRef } from 'react';
import { useMapTick } from '../hooks/useMapTick';
import type { MapProvider } from '../providers';

/**
 * Fades the map outside the selected area so only its own streets read clearly.
 * A canvas between the base map and the address/drawing layers, filled everywhere except the polygon (even-odd).
 * Works on every provider because it only needs `project`.
 */
export function AreaFocusMask({ provider, points }: { provider: MapProvider; points: LngLat[] }) {
    const tick = useMapTick(provider);
    const canvas = useRef<HTMLCanvasElement>(null);

    useEffect(() => {
        const cv = canvas.current;
        if (!cv || points.length < 3) return;
        const dpr = window.devicePixelRatio || 1;
        const { width, height } = cv.getBoundingClientRect();
        if (cv.width !== Math.round(width * dpr)) cv.width = Math.round(width * dpr);
        if (cv.height !== Math.round(height * dpr)) cv.height = Math.round(height * dpr);
        const ctx = cv.getContext('2d');
        if (!ctx) return;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, width, height);
        ctx.beginPath();
        ctx.rect(0, 0, width, height);
        points.forEach((p, i) => {
            const q = provider.project(p);
            if (i === 0) ctx.moveTo(q.x, q.y);
            else ctx.lineTo(q.x, q.y);
        });
        ctx.closePath();
        ctx.globalAlpha = 0.82;
        ctx.fillStyle = token('--color-surface-2');
        ctx.fill('evenodd');
    }, [points, tick, provider]);

    return <canvas ref={canvas} className="pointer-events-none absolute inset-0 z-[3] size-full" aria-hidden data-testid="area-focus-mask" />;
}
