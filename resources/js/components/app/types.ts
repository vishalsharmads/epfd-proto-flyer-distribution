import type { GeoJsonPolygon, LngLat, Quote } from '@/types';

export interface CampaignArea {
    id: number;
    name: string;
    position: number;
    draw_mode: string;
    suburb_id: number | null;
    geometry: GeoJsonPolygon | null;
    houses: number;
    units: number;
    total: number;
    distance_km: number;
    fee_cents: number;
    estimated: true;
}

export interface CampaignT {
    id: number;
    type: 'campaign';
    reference: string;
    name: string;
    status: string;
    step: number;
    include_houses: boolean;
    include_units: boolean;
    coverage: string;
    material: string;
    pickup_address: string | null;
    pickup: LngLat | null;
    available_from: string | null;
    notes: string | null;
    promo_code: string | null;
    houses: number;
    units: number;
    letterboxes: number;
    distance_km: number;
    subtotal_cents: number;
    gst_cents: number;
    total_cents: number;
    pricing: Quote | null;
    print_order_id: number | null;
    rating: number | null;
    rating_comment: string | null;
    areas: CampaignArea[];
    created_at: string | null;
    updated_at: string | null;
    submitted_at: string | null;
    paid_at: string | null;
    completed_at: string | null;
}

export interface CampaignCard {
    id: number;
    type: 'campaign';
    reference: string;
    name: string;
    status: string;
    step: number;
    letterboxes: number;
    houses: number;
    units: number;
    total_cents: number;
    area_count: number;
    polygons: GeoJsonPolygon[];
    updated_at: string | null;
    created_at: string | null;
    submitted_at: string | null;
}

export interface DistributionOptions {
    materials: { value: string; label: string; add_cents: number }[];
    coverages: { value: string; label: string; per_item_incl_gst_cents: number; letterboxes: number }[];
    minimum_fee_cents: number;
}

export interface ProductT {
    id: number;
    slug: string;
    name: string;
    category: string;
    size_label: string;
    width_mm: number;
    height_mm: number;
    bleed_mm: number;
    from_price_cents: number;
    gsm_options: number[];
    finish_options: Record<string, string[]>;
    quantity_options: number[];
    distributable: boolean;
    distribution_material: string | null;
    turnaround_days: number;
}

export interface ArtworkT {
    id: number;
    side: 'front' | 'back';
    original_name: string;
    mime: string;
    size_bytes: number;
    width_px: number | null;
    height_px: number | null;
    status: string;
    review_note: string | null;
    url: string;
}

export interface PrintOrderT {
    id: number;
    type: 'print';
    reference: string;
    name: string;
    status: string;
    step: number;
    product: ProductT | null;
    gsm: number | null;
    finish: string | null;
    sides: number;
    orientation: 'portrait' | 'landscape';
    quantity: number | null;
    addons: string[];
    subtotal_cents: number;
    gst_cents: number;
    total_cents: number;
    pricing: (Quote & { meta: { earliest_delivery?: string } }) | null;
    promo_code: string | null;
    delivery_name: string | null;
    delivery_address: string | null;
    delivery_phone: string | null;
    earliest_delivery: string | null;
    also_distribute: boolean;
    campaign_id: number | null;
    right_to_print: boolean;
    tracking_number: string | null;
    rating: number | null;
    rating_comment: string | null;
    artworks: ArtworkT[];
    created_at: string | null;
    updated_at: string | null;
    submitted_at: string | null;
    paid_at: string | null;
    completed_at: string | null;
}

export interface AddonT {
    value: string;
    label: string;
    pct: number | null;
    fixed_cents: number | null;
}

export interface InvoiceT {
    id: number;
    number: string;
    status: string;
    subtotal_cents: number;
    gst_cents: number;
    total_cents: number;
    credits_applied_cents: number;
    issued_at: string | null;
    pdf_url: string;
    reference?: string | null;
}

export interface MessageT {
    id: number;
    body: string;
    from_staff: boolean;
    author: string;
    created_at: string | null;
}

export interface ThreadT {
    id: number;
    subject: string;
    status: string;
    channel: string;
    last_message_at: string | null;
    created_at: string | null;
    messages: MessageT[];
}

export interface RunT {
    id: number;
    reference: string;
    name: string;
    status: string;
    flyers: number;
    houses: number;
    units: number;
    deadline: string | null;
    coverage_pct: number | null;
    geometry: GeoJsonPolygon | null;
    started_at: string | null;
    completed_at: string | null;
    campaign: { id: number; name: string; reference: string } | null;
    gps_count: number | null;
    track?: LngLat[];
}

export type DesignType = 'flyer' | 'brochure' | 'business_card' | 'presentation_folder' | 'stationery';
export type DesignPackage = 'basic' | 'standard' | 'premium';

export interface DesignAssetT {
    id: number;
    kind: 'brief_upload' | 'proof' | 'final';
    version: number;
    original_name: string;
    mime: string;
    size_bytes: number;
    width: number | null;
    height: number | null;
    note: string | null;
    is_image: boolean;
    url: string;
    download_url: string;
    created_at: string | null;
}

export interface DesignOrderT {
    id: number;
    type: 'design';
    reference: string;
    name: string;
    type_label: string;
    status: string;
    step: number;
    design_type: DesignType;
    package: DesignPackage;
    size: string | null;
    print_product_id: number | null;
    product: ProductT | null;
    sides: number;
    headline: string | null;
    key_message: string | null;
    call_to_action: string | null;
    contact_details: string | null;
    brand_colours: string | null;
    style_notes: string | null;
    target_audience: string | null;
    due_date: string | null;
    revisions_included: number;
    revisions_used: number;
    revisions_unlimited: boolean;
    extra_revisions: number;
    addons: string[];
    subtotal_cents: number;
    gst_cents: number;
    total_cents: number;
    pricing: (Quote & { meta: { turnaround_days?: number; revisions_included?: number; estimated_first_proof?: string; extra_revision_cents?: number } }) | null;
    promo_code: string | null;
    designer: { id: number; name: string } | null;
    print_order_id: number | null;
    brief_files: DesignAssetT[];
    proofs: DesignAssetT[];
    finals: DesignAssetT[];
    created_at: string | null;
    updated_at: string | null;
    submitted_at: string | null;
    paid_at: string | null;
    approved_at: string | null;
    completed_at: string | null;
}

export interface DesignTypeOption {
    value: DesignType;
    label: string;
    description: string;
    sides_option: boolean;
    prices: Record<DesignPackage, number>;
    from_cents: number;
}

export interface DesignPackageOption {
    value: DesignPackage;
    label: string;
    description: string;
    revisions: number;
    unlimited: boolean;
    turnaround_days: number;
}

export interface DesignCatalogue {
    types: DesignTypeOption[];
    packages: DesignPackageOption[];
    addons: AddonT[];
    double_sided_pct: number;
    extra_revision_cents: number;
    gst_rate: number;
}
