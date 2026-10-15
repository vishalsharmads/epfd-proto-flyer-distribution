import { useEffect, useState } from 'react';
import type { MapProvider } from '../providers';

/** Re-render on every map move/zoom (rAF-throttled) so overlay geometry can be re-projected. */
export function useMapTick(provider: MapProvider | null): number {
    const [tick, setTick] = useState(0);
    useEffect(() => {
        if (!provider) return;
        let raf = 0;
        const bump = () => {
            if (raf) return;
            raf = requestAnimationFrame(() => {
                raf = 0;
                setTick((t) => t + 1);
            });
        };
        const offs = [provider.on('move', bump), provider.on('zoom', bump), provider.on('moveend', bump)];
        bump();
        return () => {
            offs.forEach((o) => o());
            cancelAnimationFrame(raf);
        };
    }, [provider]);
    return tick;
}

export function isTypingTarget(t: EventTarget | null): boolean {
    if (!(t instanceof HTMLElement)) return false;
    return t.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName);
}

export function prefersReducedMotion(): boolean {
    return typeof window !== 'undefined' && !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
}

export function storageGet(k: string): string | null {
    try {
        return localStorage.getItem(k);
    } catch {
        return null;
    }
}

export function storageSet(k: string, v: string): void {
    try {
        localStorage.setItem(k, v);
    } catch {
        /* storage unavailable */
    }
}
