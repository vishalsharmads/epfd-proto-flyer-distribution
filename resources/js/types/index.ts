import type { PageProps } from '@inertiajs/core';

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    email_verified: boolean;
    roles: string[];
    permissions: string[];
    is_staff: boolean;
}

export interface AuthBusiness {
    id: number;
    name: string;
    status: 'pending' | 'approved' | 'suspended';
    can_pay: boolean;
    state: string | null;
}

export interface SharedProps extends PageProps {
    auth: { user: AuthUser | null; business: AuthBusiness | null };
    brand: {
        name: string;
        short_name: string;
        tagline: string;
        cities: string[];
        phone: string;
        email: string;
        address: string;
        logo: string;
    };
    map: {
        provider: 'maplibre' | 'google';
        google_key: string | null;
        base_tile_url: string;
        satellite_tile_url: string;
        attribution: string;
        pickup_radius_km: number;
        viewport_min_zoom: number;
    };
    gnaf: { attribution: string; licence_url: string; disclaimer: string; release: string | null };
    flash: { success: string | null; error: string | null };
    errors: Record<string, string>;
    [key: string]: unknown;
}

export type GeoJsonPolygon = { type: 'Polygon'; coordinates: [number, number][][] };
export type LngLat = [number, number];

export interface Estimate {
    houses: number;
    units: number;
    total: number;
    distance_km: number;
    source: string;
    estimated: true;
    no_letterboxes: boolean;
}

export interface QuoteLine {
    code: string;
    label: string;
    quantity: number;
    unit_cents: number;
    amount_cents: number;
}

export interface Quote {
    service: 'distribution' | 'print';
    currency: 'AUD';
    lines: QuoteLine[];
    discount_cents: number;
    subtotal_cents: number;
    gst_cents: number;
    total_cents: number;
    per_item_incl_gst_cents: number;
    gst_inclusive: true;
    meta: Record<string, unknown>;
}

export interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
    from: number | null;
    to: number | null;
}
