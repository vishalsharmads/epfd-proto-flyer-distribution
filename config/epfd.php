<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Brand
    |--------------------------------------------------------------------------
    | Visual tokens live in resources/css/tokens.css. These values are used
    | where CSS is not available (emails, PDFs).
    */
    'brand' => [
        'name' => 'Easy Peasy Flyer Distribution',
        'short_name' => 'EPFD',
        'tagline' => 'Smart Distribution for Smarter Marketing',
        'cities' => ['Sydney', 'Melbourne', 'Wollongong', 'Newcastle'],
        'phone' => '0493 243 666',
        'email' => 'Operations@epfd.com.au',
        'address' => 'Y-03, 257 Victoria Road, Rydalmere NSW 2116',
        // EPFD's own ABN for tax invoices — NEEDS HUMAN INPUT (not published on epfd.com.au).
        'abn' => env('EPFD_ABN'),
        'logo' => 'brand/logo.png',
        'primary' => '#E00B33',
        'ink' => '#141414',
        'accent' => '#FFC446',
    ],

    'timezone' => 'Australia/Sydney',
    'currency' => 'AUD',
    'gst_rate' => 0.10,

    /*
    | When true, a pending/suspended business can build drafts but cannot pay.
    */
    'require_approval_for_payment' => (bool) env('EPFD_REQUIRE_APPROVAL_FOR_PAYMENT', true),

    /*
    |--------------------------------------------------------------------------
    | G-NAF
    |--------------------------------------------------------------------------
    */
    'gnaf' => [
        'path' => env('GNAF_PATH'),
        'release_label' => env('GNAF_RELEASE_LABEL', 'AUG 2026'),
        'default_states' => ['NSW', 'VIC'],
        'all_states' => ['ACT', 'NSW', 'NT', 'OT', 'QLD', 'SA', 'TAS', 'VIC', 'WA'],
        'srid' => 7844,
        'crs_label' => 'GDA2020 (EPSG:7844)',
        'sample_path' => database_path('gnaf-sample'),

        /*
        | Classification rules — see App\Domain\Gnaf\AddressClassifier.
        | Every rule is a flag with a documented default.
        */
        'rules' => [
            // Rule 1: only principal addresses (ALIAS_PRINCIPAL = 'P') count.
            'principal_only' => true,
            // Rule 1: retired records (DATE_RETIRED set) are skipped.
            'skip_retired' => true,
            // Rule 2: a SECONDARY address (PRIMARY_SECONDARY = 'S') is a unit even without a flat component.
            'secondary_is_unit' => true,
            // Rule 3: a primary that has unit/secondary children is excluded from letterbox counts.
            'exclude_primary_with_units' => true,
            // Rule 3 alternative: count a unit building as ONE letterbox instead of one per unit.
            'include_unit_buildings_as_single_letterbox' => false,
            // Rule 5: exclude commercial flat types (SHOP, OFFC, WHSE...) from counts.
            'exclude_commercial_when_available' => true,
            // Flat types treated as non-residential when the above is on.
            'commercial_flat_types' => [
                'SHOP', 'OFFC', 'WHSE', 'FCTY', 'KSK', 'SHRM', 'WKSH', 'STOR', 'STLL', 'ATM',
                'SIGN', 'SUBS', 'BBQ', 'CAGE', 'CARP', 'CARS', 'GRGE', 'LBBY', 'MBTH', 'BTSD',
                'SHED', 'TWR', 'ANT', 'HALL', 'CLUB', 'LSE', 'RESV', 'COOL', 'VLT',
            ],
            // Rule 4: rows without a default geocode are skipped (and reported).
            'require_geocode' => true,
        ],
    ],

    'suburbs' => [
        // ST_ConcaveHull target percent (0..1, lower = tighter).
        'concave_target' => 0.35,
        'buffer_m' => 60,
        'simplify_m' => 25,
        'min_points' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Area / estimator
    |--------------------------------------------------------------------------
    */
    'areas' => [
        'pickup_radius_km' => 20,
        'max_vertices' => 500,
        'max_area_km2' => 60,
        'min_area_m2' => 500,
        // Australia (incl. Tas) in lon/lat.
        'australia_bbox' => [112.0, -44.5, 154.5, -9.0],
    ],

    'estimator' => [
        // 'gnaf' or 'geoscape_buildings' (stub, paid-data upgrade).
        'source' => env('LETTERBOX_SOURCE', 'gnaf'),
        'cache_ttl' => 3600,
        // Cap for the gap between two consecutive streets in the distance heuristic.
        'connector_cap_m' => 250,
        // Gap above which consecutive points on the same street are treated as separate segments.
        'max_segment_gap_m' => 400,
    ],

    'viewport' => [
        'min_zoom' => 16,
        'max_points' => 3000,
    ],

    'map' => [
        'google_key' => env('GOOGLE_MAPS_KEY'),
        'satellite_tile_url' => env('MAP_SATELLITE_TILE_URL', 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'),
        'base_tile_url' => env('MAP_BASE_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'attribution' => '© OpenStreetMap contributors',
    ],

    'payments' => [
        'gateway' => env('PAYMENT_GATEWAY', 'mock'),
        // Mock gateway: this card number always declines.
        'mock_decline_card' => '4000000000000002',
        'stripe_secret' => env('STRIPE_SECRET'),
        'stripe_webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'mock_webhook_secret' => env('MOCK_WEBHOOK_SECRET', 'mock-webhook-secret'),
    ],

    'licence' => [
        'gnaf_attribution' => 'Incorporates or developed using G-NAF © Geoscape Australia licensed by the Commonwealth of Australia under the Open Geo-coded National Address File (G-NAF) End User Licence Agreement.',
        'gnaf_licence_url' => 'https://data.gov.au/dataset/geocoded-national-address-file-g-naf',
        'gnaf_disclaimer' => 'Letterbox counts are estimates derived from G-NAF address points and may differ from what is physically present.',
    ],
];
