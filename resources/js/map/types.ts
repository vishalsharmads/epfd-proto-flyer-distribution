import type { Estimate, GeoJsonPolygon, LngLat, Quote } from '@/types';

export type DrawMode = 'pins' | 'trace' | 'suburb';
export type Coverage = 'houses' | 'units' | 'houses_units';

/** One audience area (a run). `key` is a stable client id; `id` is the server id once persisted. */
export interface BuilderArea {
    key?: string;
    id?: number | string;
    name: string;
    draw_mode: DrawMode;
    suburb_id?: number | null;
    geometry: GeoJsonPolygon;
    estimate?: Estimate | null;
    quote?: Quote | null;
    approximate?: boolean;
}

export interface Pickup {
    lng: number;
    lat: number;
    label?: string;
}

export interface GpsTrack {
    color?: string;
    points: LngLat[];
}

/** Payload passed to `onChange` whenever an area is saved/renamed/deleted or a toggle changes. */
export interface AudienceState {
    areas: (BuilderArea & { key: string })[];
    include_houses: boolean;
    include_units: boolean;
    totals: {
        houses: number;
        units: number;
        letterboxes: number;
        distance_km: number;
        /** Sum of per-area quotes (GST incl.), null in admin mode or when any fee is unavailable. */
        fee_cents: number | null;
    };
}

/**
 * Props for <AudienceBuilder/>.
 *
 * - `areas`        initial areas (uncontrolled after mount; the builder owns editing state).
 * - `pickup`       pickup point; draws the blue marker + dashed radius circle and enforces the radius rule.
 * - `includeHouses`/`includeUnits` initial audience toggles (at least one stays on).
 * - `coverage`/`material` forwarded to POST /areas/estimate for pricing.
 * - `onChange`     called with the full AudienceState after every committed change (wizard autosaves).
 * - `mode`         'business' (default, priced) or 'admin' (split into runs: no pricing, "Run N" labels,
 *                  optional dashed `boundary` reference, radius rule skipped when no pickup).
 * - `readOnly`     show areas (and optional `tracks`) only — no drawing UI.
 * - `className`    applied to the root (which fills its parent: give the parent a height).
 */
export interface AudienceBuilderProps {
    areas?: BuilderArea[];
    pickup?: Pickup | null;
    includeHouses?: boolean;
    includeUnits?: boolean;
    coverage?: Coverage;
    material?: string;
    onChange?: (state: AudienceState) => void;
    mode?: 'business' | 'admin';
    boundary?: GeoJsonPolygon | null;
    readOnly?: boolean;
    tracks?: GpsTrack[];
    className?: string;
    /** Override the configured map engine (POC / comparison pages). */
    engine?: 'maplibre' | 'google';
    /** 'overlay' (default): our SVG drawing. 'terra': Terra Draw inside the map engine (POC). */
    drawEngine?: 'overlay' | 'terra';
}

export interface EstimateResponse {
    estimate: Estimate;
    quote: Quote | null;
    fee_available: boolean;
    message: string | null;
}
