<?php

declare(strict_types=1);

namespace App\Docs;

/**
 * Curated request/response examples keyed by "METHOD /path" (path relative to /api/v1).
 * Operations without an entry get examples generated from their schema (see OpenApiDocument).
 */
final class Examples
{
    private const POLYGON = ['type' => 'Polygon', 'coordinates' => [[[151.025, -33.815], [151.045, -33.815], [151.045, -33.800], [151.025, -33.800], [151.025, -33.815]]]];

    private const CARD = ['number' => '4242424242424242', 'exp_month' => 12, 'exp_year' => 2030, 'cvc' => '123', 'name' => 'Jane Citizen'];

    /** @return array<string, array<string, mixed>> */
    public static function requests(): array
    {
        $pay = ['card' => self::CARD, 'billing' => ['company' => 'Demo Plumbing Pty Ltd', 'abn' => '51824753556', 'address' => '257 Victoria Road, Rydalmere NSW 2116'], 'use_credits' => false, 'save_card' => false, 'accept_terms' => true];

        return [
            'POST /auth/login' => ['email' => 'demo@business.test', 'password' => 'password', 'device_name' => 'postman'],
            'POST /auth/register' => ['business_name' => 'Acme Lawn Care', 'contact_name' => 'Sam Smith', 'email' => 'sam@acme.test', 'mobile' => '0412345678', 'abn' => '51824753556', 'industry' => 'Home services', 'address' => '1 George St, Sydney NSW 2000', 'password' => 'correct-horse-battery', 'device_name' => 'postman', 'accept_terms' => true],
            'POST /auth/otp/verify' => ['code' => '123456'],
            'POST /auth/password/forgot' => ['email' => 'demo@business.test'],
            'PATCH /business' => ['name' => 'Demo Plumbing Pty Ltd', 'mobile' => '0412345678', 'abn' => '51 824 753 556', 'industry' => 'Trades', 'address' => '257 Victoria Road, Rydalmere NSW 2116', 'lng' => 151.0365, 'lat' => -33.8126],
            'POST /business/areas' => ['name' => 'Rydalmere north', 'geometry' => self::POLYGON],
            'PATCH /business/areas/{area}' => ['name' => 'Rydalmere north (v2)'],
            'POST /areas/estimate' => ['geometry' => self::POLYGON, 'include_houses' => true, 'include_units' => true, 'coverage' => 'houses_units', 'material' => 'standard_dl', 'pickup' => ['lng' => 151.0365, 'lat' => -33.8126]],
            'POST /pricing/distribution/quote' => ['houses' => 941, 'units' => 1152, 'coverage' => 'houses_units', 'material' => 'standard_dl', 'promo_code' => null],
            'POST /pricing/print/quote' => ['product_id' => 1, 'gsm' => 150, 'sides' => 2, 'quantity' => 5000, 'finish' => 'gloss', 'addons' => ['express']],
            'POST /campaigns' => ['name' => 'Spring letterbox drop', 'include_houses' => true, 'include_units' => true, 'coverage' => 'houses_units', 'material' => 'standard_dl', 'available_from' => '2026-10-20'],
            'PATCH /campaigns/{campaign}' => ['coverage' => 'houses_only', 'promo_code' => 'WELCOME10', 'notes' => 'Avoid "No junk mail" letterboxes.'],
            'PUT /campaigns/{campaign}/areas' => ['areas' => [['name' => 'Rydalmere north', 'draw_mode' => 'pins', 'geometry' => self::POLYGON]]],
            'POST /campaigns/{campaign}/pay' => $pay,
            'POST /print/orders' => ['print_product_id' => 1, 'gsm' => 150, 'finish' => 'gloss', 'sides' => 2, 'orientation' => 'portrait', 'quantity' => 5000, 'addons' => [], 'also_distribute' => true],
            'PATCH /print/orders/{order}' => ['quantity' => 10000, 'right_to_print' => true],
            'POST /print/orders/{order}/pay' => $pay,
            'POST /support/threads' => ['subject' => 'Can you deliver before Friday?', 'body' => 'Hi team, is a Friday delivery possible for CMP-3CVBS8?', 'campaign_id' => 4],
            'POST /support/threads/{thread}/messages' => ['body' => 'Thanks — that works for us.'],
            'POST /runs/{run}/gps' => ['points' => [
                ['lng' => 151.0260, 'lat' => -33.8075, 'accuracy_m' => 5, 'speed_mps' => 1.2, 'recorded_at' => '2026-10-21T08:00:00+11:00'],
                ['lng' => 151.0440, 'lat' => -33.8075, 'accuracy_m' => 6, 'speed_mps' => 1.3, 'recorded_at' => '2026-10-21T08:20:00+11:00'],
            ]],
            'POST /webhooks/{gateway}' => ['id' => 'evt_01HZXK3', 'type' => 'payment.refunded', 'data' => ['gateway_ref' => 'mock_ch_YrsF2FmR3jxTJW5N', 'amount_cents' => 1000]],
            'POST /admin/businesses/{business}/approve' => ['note' => 'ABN verified on ABR.'],
            'POST /admin/businesses/{business}/suspend' => ['reason' => 'Chargeback under investigation.'],
            'POST /admin/businesses/{business}/credits' => ['amount_cents' => 5000, 'reason' => 'Goodwill credit for late delivery'],
            'POST /admin/campaigns/{campaign}/runs' => ['from_areas' => true, 'deadline' => '2026-10-28'],
            'POST /admin/runs/{run}/assign' => ['distributor_id' => 6],
            'PATCH /admin/runs/{run}/status' => ['status' => 'collected'],
            'PATCH /admin/print-orders/{order}/status' => ['status' => 'shipped', 'vendor' => 'PrintCo Sydney', 'tracking_number' => 'AP123456789AU'],
            'POST /admin/artworks/{artwork}/approve' => ['note' => 'Bleed OK.'],
            'POST /admin/artworks/{artwork}/reject' => ['note' => 'Images are 72 dpi — please supply 300 dpi.'],
            'POST /admin/print-orders/{order}/refund' => ['reason' => 'Printer error', 'as_credit' => false],
            'POST /admin/payments/{payment}/refund' => ['amount_cents' => 10000, 'reason' => 'Partial delivery', 'as_credit' => true],
            'PUT /admin/rate-card' => ['name' => 'FY27 rates', 'data' => ['distribution' => ['base_per_letterbox' => 0.081, 'minimum_order' => 150], 'print' => ['minimum_order' => 60, 'turnaround_days' => 3]]],
            'POST /admin/promo-codes' => ['code' => 'SPRING15', 'description' => '15% off spring drops', 'type' => 'percent', 'value' => 15, 'service' => 'distribution', 'min_subtotal_cents' => 20000, 'ends_at' => '2026-12-01T00:00:00+11:00', 'max_uses' => 100, 'is_active' => true],
            'PATCH /admin/promo-codes/{promo}' => ['is_active' => false],
            'PATCH /admin/suburbs/{suburb}' => ['is_serviced' => true],
            'POST /admin/support/threads/{thread}/reply' => ['body' => 'Yes — booked for Friday.', 'status' => 'resolved'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public static function responses(): array
    {
        $user = ['id' => 5, 'name' => 'Demo Owner', 'email' => 'demo@business.test', 'mobile' => '0412345678', 'email_verified' => true, 'is_distributor' => false, 'roles' => [], 'business' => self::business(), 'last_login_at' => '2026-10-15T07:47:41+00:00'];

        return [
            'POST /auth/login' => [200 => ['token' => '1|7NddXr0cQ0yYk2qvZ8m1yHhPp3aW1sDgS5Lr3V2f', 'user' => $user]],
            'POST /auth/register' => [201 => ['token' => '2|kV1gqP0Qx...', 'user' => [...$user, 'email_verified' => false, 'business' => [...self::business(), 'status' => 'pending', 'can_pay' => false]], 'otp_required' => true]],
            'GET /auth/me' => [200 => ['data' => $user]],
            'POST /auth/logout' => [200 => ['message' => 'Logged out.']],
            'POST /auth/otp/verify' => [200 => ['message' => 'E-mail verified.', 'verified' => true]],
            'POST /auth/otp/resend' => [200 => ['message' => 'If verification is pending, a new code has been sent.']],
            'POST /auth/password/forgot' => [200 => ['message' => 'If that e-mail is registered, a reset link has been sent.']],
            'GET /business' => [200 => ['data' => self::business()]],
            'PATCH /business' => [200 => ['data' => self::business()]],
            'POST /campaigns' => [201 => ['data' => self::campaign('draft', false)]],
            'GET /campaigns/{campaign}' => [200 => ['data' => self::campaign('draft', true)]],
            'PATCH /campaigns/{campaign}' => [200 => ['data' => self::campaign('draft', true)]],
            'PUT /campaigns/{campaign}/areas' => [200 => ['data' => self::campaign('draft', true)]],
            'POST /campaigns/{campaign}/submit' => [200 => ['data' => ['ready' => true, 'can_pay' => true, 'total_cents' => 186486, 'letterboxes' => 2093]]],
            'POST /campaigns/{campaign}/pay' => [201 => ['data' => ['payment' => self::payment(), 'campaign' => self::campaign('submitted', false), 'invoice_id' => 3]]],
            'GET /campaigns/{campaign}/tracking' => [200 => ['data' => [
                'campaign_id' => 4, 'status' => 'in_delivery', 'timeline' => ['submitted', 'runs_published', 'allocated', 'collected', 'in_delivery', 'completed'],
                'runs' => [[
                    'id' => 1, 'reference' => 'RUN-V5RSCR', 'name' => 'Rydalmere north', 'status' => 'in_delivery', 'coverage_pct' => null,
                    'latest_point' => ['lng' => 151.044, 'lat' => -33.812, 'accuracy_m' => 5.0, 'recorded_at' => '2026-10-21 08:30:00'],
                    'area' => self::POLYGON,
                    'track' => ['type' => 'Feature', 'geometry' => ['type' => 'LineString', 'coordinates' => [[151.026, -33.8075], [151.044, -33.8075], [151.044, -33.812]]], 'properties' => ['run_id' => 1, 'points' => 3]],
                ]],
            ]]],
            'POST /runs/{run}/gps' => [202 => ['data' => ['accepted' => 2, 'run_status' => 'in_delivery']]],
            'POST /runs/{run}/submit' => [200 => ['data' => [...self::run('completed'), 'coverage_pct' => 87.4, 'coverage' => ['letterboxes' => 2093, 'covered' => 1829, 'coverage_pct' => 87.4, 'gps_points' => 1412]]]],
            'POST /runs/{run}/claim' => [200 => ['data' => self::run('allocated')]],
            'POST /webhooks/{gateway}' => [200 => ['status' => 'processed', 'event_id' => 'evt_01HZXK3', 'payment_id' => 3]],
            'POST /admin/campaigns/{campaign}/runs' => [201 => ['data' => [self::run('draft')]]],
            'POST /admin/runs/{run}/publish' => [200 => ['data' => self::run('published')]],
            'POST /admin/runs/{run}/assign' => [200 => ['data' => self::run('allocated')]],
            'PATCH /admin/runs/{run}/status' => [200 => ['data' => self::run('collected')]],
            'POST /admin/payments/{payment}/refund' => [200 => ['data' => [...self::payment(), 'refunded_cents' => 10000, 'status' => 'partially_refunded']]],
        ];
    }

    /** @return array<string, mixed> */
    public static function problem(int $status, string $code, string $detail, string $instance = '/api/v1/campaigns/4', array $extra = []): array
    {
        $titles = [400 => 'Bad request', 401 => 'Unauthenticated', 402 => 'Payment declined', 403 => 'Forbidden', 404 => 'Not found', 409 => 'Conflict', 422 => 'Validation failed', 429 => 'Too many requests', 503 => 'Service unavailable'];

        return ['type' => '/docs#errors-'.$code, 'title' => $titles[$status] ?? 'Error', 'status' => $status, 'detail' => $detail, 'instance' => $instance, 'code' => $code, ...$extra];
    }

    /** @return array<string, mixed> */
    private static function business(): array
    {
        return ['id' => 1, 'name' => 'Demo Plumbing Pty Ltd', 'contact_name' => 'Demo Owner', 'email' => 'demo@business.test', 'mobile' => '0412345678', 'abn' => '51824753556', 'industry' => 'Trades', 'address' => '257 Victoria Road, Rydalmere NSW 2116', 'state' => 'NSW', 'status' => 'approved', 'can_pay' => true, 'approved_at' => '2026-10-15T07:00:00+00:00', 'credit_balance_cents' => 5000, 'created_at' => '2026-10-15T07:00:00+00:00'];
    }

    /** @return array<string, mixed> */
    private static function campaign(string $status, bool $withAreas): array
    {
        $c = [
            'id' => 4, 'reference' => 'CMP-3CVBS8', 'name' => 'Spring letterbox drop', 'status' => $status, 'step' => 1, 'business_id' => 1,
            'include_houses' => true, 'include_units' => true, 'coverage' => 'houses_units', 'material' => 'standard_dl',
            'pickup_address' => '257 Victoria Road, Rydalmere NSW 2116', 'pickup' => ['type' => 'Point', 'coordinates' => [151.0365, -33.8126]],
            'available_from' => '2026-10-20', 'notes' => null, 'promo_code' => null,
            'houses' => 941, 'units' => 1152, 'letterboxes' => 2093, 'distance_km' => 29.99,
            'subtotal_cents' => 169533, 'gst_cents' => 16953, 'total_cents' => 186486,
            'pricing' => ['service' => 'distribution', 'lines' => [['code' => 'distribution', 'label' => 'Letterbox distribution — 2,093 letterboxes', 'quantity' => 2093, 'unit_cents' => 81, 'amount_cents' => 169533]], 'subtotal_cents' => 169533, 'gst_cents' => 16953, 'total_cents' => 186486],
            'submitted_at' => $status === 'draft' ? null : '2026-10-15T07:47:42+00:00', 'paid_at' => $status === 'draft' ? null : '2026-10-15T07:47:42+00:00', 'completed_at' => null,
            'created_at' => '2026-10-15T07:47:41+00:00', 'updated_at' => '2026-10-15T07:47:42+00:00',
        ];
        if ($withAreas) {
            $c['areas'] = [['id' => 3, 'name' => 'Rydalmere north', 'position' => 1, 'draw_mode' => 'pins', 'suburb_id' => null, 'geometry' => self::POLYGON, 'houses' => 941, 'units' => 1152, 'distance_km' => 29.99, 'fee_cents' => 186486]];
        }

        return $c;
    }

    /** @return array<string, mixed> */
    private static function payment(): array
    {
        return ['id' => 3, 'business_id' => 1, 'payable_type' => 'campaign', 'payable_id' => 4, 'gateway' => 'mock', 'gateway_ref' => 'mock_ch_YrsF2FmR3jxTJW5N', 'amount_cents' => 186486, 'credits_applied_cents' => 0, 'refunded_cents' => 0, 'currency' => 'AUD', 'status' => 'succeeded', 'card_brand' => 'visa', 'card_last4' => '4242', 'failure_reason' => null, 'billing' => ['company' => 'Demo Plumbing Pty Ltd', 'abn' => '51824753556', 'address' => '257 Victoria Road, Rydalmere NSW 2116'], 'paid_at' => '2026-10-15T07:47:42+00:00', 'created_at' => '2026-10-15T07:47:42+00:00'];
    }

    /** @return array<string, mixed> */
    private static function run(string $status): array
    {
        return ['id' => 1, 'reference' => 'RUN-V5RSCR', 'campaign_id' => 4, 'campaign_area_id' => 3, 'name' => 'Rydalmere north', 'status' => $status, 'geometry' => self::POLYGON, 'flyers' => 2093, 'houses' => 941, 'units' => 1152, 'distance_km' => 29.99, 'pay_cents' => 16744, 'deadline' => '2026-10-28', 'distributor_id' => in_array($status, ['draft', 'published'], true) ? null : 6, 'coverage_pct' => null, 'published_at' => $status === 'draft' ? null : '2026-10-16T01:00:00+00:00', 'allocated_at' => null, 'started_at' => null, 'completed_at' => null];
    }
}
