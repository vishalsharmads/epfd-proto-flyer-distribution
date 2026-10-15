import { api, ApiError } from '@/lib/utils';
import type { GeoJsonPolygon, LngLat } from '@/types';
import { useEffect, useRef, useState } from 'react';
import { ringTouchesRadius } from '../geometry/circle';
import { pointInPolygon, selfIntersects, toPolygon } from '../geometry/polygon';
import type { Coverage, EstimateResponse, Pickup } from '../types';

export interface EstimateOptions {
    includeHouses: boolean;
    includeUnits: boolean;
    coverage?: Coverage;
    material?: string;
    pickup?: Pickup | null;
}

export async function requestEstimate(geometry: GeoJsonPolygon, o: EstimateOptions, signal?: AbortSignal): Promise<EstimateResponse> {
    return api<EstimateResponse>('/api/v1/areas/estimate', {
        signal,
        json: {
            geometry,
            include_houses: o.includeHouses,
            include_units: o.includeUnits,
            ...(o.coverage ? { coverage: o.coverage } : {}),
            ...(o.material ? { material: o.material } : {}),
            ...(o.pickup ? { pickup: { lng: o.pickup.lng, lat: o.pickup.lat } } : {}),
        },
    });
}

/** Client-side checks mirroring the server, so feedback is instant. Returns an error message or null. */
export function validateRing(points: LngLat[], pickup: Pickup | null | undefined, radiusKm: number): string | null {
    if (points.length < 3) return null;
    if (selfIntersects(points)) return 'The area outline crosses itself. Move the pins so the edges do not cross.';
    if (pickup && !ringTouchesRadius(points, [pickup.lng, pickup.lat], radiusKm, pointInPolygon)) {
        return `This area is too far away. Every area must be at least partly within ${radiusKm} km of the pickup location.`;
    }
    return null;
}

export interface EstimateState {
    loading: boolean;
    data: EstimateResponse | null;
    error: string | null;
    code: string | null;
}

/** Debounced (300 ms) live estimate for the polygon being edited. */
export function useLiveEstimate(points: LngLat[] | null, opts: EstimateOptions, radiusKm: number): EstimateState {
    const [state, setState] = useState<EstimateState>({ loading: false, data: null, error: null, code: null });
    const key = points && points.length >= 3 ? JSON.stringify([points, opts.includeHouses, opts.includeUnits, opts.coverage, opts.material]) : '';
    const optsRef = useRef(opts);
    optsRef.current = opts;

    useEffect(() => {
        if (!key || !points) {
            setState({ loading: false, data: null, error: null, code: null });
            return;
        }
        const clientError = validateRing(points, optsRef.current.pickup, radiusKm);
        if (clientError) {
            setState({ loading: false, data: null, error: clientError, code: 'client_validation' });
            return;
        }
        setState((s) => ({ ...s, loading: true, error: null, code: null }));
        const ctrl = new AbortController();
        const t = setTimeout(() => {
            requestEstimate(toPolygon(points), optsRef.current, ctrl.signal)
                .then((data) => setState({ loading: false, data, error: null, code: null }))
                .catch((e: unknown) => {
                    if (ctrl.signal.aborted) return;
                    if (e instanceof ApiError) setState({ loading: false, data: null, error: e.problem?.detail ?? e.message, code: e.problem?.code ?? null });
                    else setState({ loading: false, data: null, error: 'Could not estimate this area. Check your connection and try again.', code: 'network' });
                });
        }, 300);
        return () => {
            clearTimeout(t);
            ctrl.abort();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key, radiusKm]);

    return state;
}
