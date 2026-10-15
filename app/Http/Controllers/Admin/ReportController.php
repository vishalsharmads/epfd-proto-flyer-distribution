<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends AdminController
{
    /** report key => [title, columns (key => label), money columns] */
    public const REPORTS = [
        'revenue' => ['Revenue by month & service', ['month' => 'Month', 'service' => 'Service', 'payments' => 'Payments', 'gross_cents' => 'Gross incl GST', 'refunded_cents' => 'Refunded', 'net_cents' => 'Net incl GST', 'gst_cents' => 'GST component']],
        'campaigns' => ['Campaigns by status', ['status' => 'Status', 'campaigns' => 'Campaigns', 'letterboxes' => 'Letterboxes', 'total_cents' => 'Value incl GST']],
        'letterboxes' => ['Letterboxes distributed by suburb / state', ['state' => 'State', 'suburb' => 'Suburb', 'runs' => 'Completed runs', 'letterboxes' => 'Letterboxes', 'avg_coverage' => 'Avg GPS coverage %']],
        'print' => ['Print orders by product', ['product' => 'Product', 'orders' => 'Orders', 'pieces' => 'Pieces', 'total_cents' => 'Value incl GST']],
        'businesses' => ['Top businesses', ['business' => 'Business', 'state' => 'State', 'campaigns' => 'Campaigns', 'print_orders' => 'Print orders', 'spend_cents' => 'Net spend incl GST']],
    ];

    /** @return array{0:string,1:string} UTC bounds */
    private function bounds(Request $request): array
    {
        $tz = config('epfd.timezone');
        [$from, $to] = $this->range($request->input('from'), $request->input('to'));
        $f = $from ? Carbon::parse($from, $tz)->startOfDay() : Carbon::now($tz)->startOfMonth()->subMonths(11);
        $t = $to ? Carbon::parse($to, $tz)->endOfDay() : Carbon::now($tz)->endOfDay();

        return [$f->utc()->toDateTimeString(), $t->utc()->toDateTimeString()];
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $report, Request $request): array
    {
        [$from, $to] = $this->bounds($request);
        $tz = config('epfd.timezone');
        $gst = 1 + (float) config('epfd.gst_rate', 0.1);
        $paid = "('succeeded','partially_refunded','refunded')";

        $sql = match ($report) {
            'revenue' => ["
                SELECT to_char(paid_at AT TIME ZONE 'UTC' AT TIME ZONE ?, 'YYYY-MM') AS month,
                       CASE WHEN payable_type = 'campaign' THEN 'Distribution' ELSE 'Print' END AS service,
                       count(*) AS payments, sum(amount_cents + credits_applied_cents) AS gross_cents, sum(refunded_cents) AS refunded_cents,
                       sum(amount_cents + credits_applied_cents - refunded_cents) AS net_cents,
                       round(sum(amount_cents + credits_applied_cents - refunded_cents) - sum(amount_cents + credits_applied_cents - refunded_cents) / ?::numeric) AS gst_cents
                FROM payments WHERE status IN {$paid} AND paid_at BETWEEN ? AND ?
                GROUP BY 1, 2 ORDER BY 1, 2", [$tz, $gst, $from, $to]],
            'campaigns' => ['
                SELECT status, count(*) AS campaigns, sum(letterboxes) AS letterboxes, sum(total_cents) AS total_cents
                FROM campaigns WHERE deleted_at IS NULL AND status <> \'draft\' AND coalesce(submitted_at, created_at) BETWEEN ? AND ?
                GROUP BY status ORDER BY count(*) DESC', [$from, $to]],
            'letterboxes' => ['
                SELECT coalesce(s.state, \'—\') AS state, coalesce(s.name, \'(outside known suburbs)\') AS suburb, count(*) AS runs,
                       sum(r.flyers) AS letterboxes, round(avg(r.coverage_pct), 1) AS avg_coverage
                FROM runs r
                LEFT JOIN LATERAL (SELECT name, state FROM suburbs WHERE ST_Intersects(boundary, ST_PointOnSurface(r.geom)) LIMIT 1) s ON true
                WHERE r.status = \'completed\' AND r.completed_at BETWEEN ? AND ?
                GROUP BY 1, 2 ORDER BY 1, sum(r.flyers) DESC', [$from, $to]],
            'print' => ['
                SELECT coalesce(p.name, \'(deleted product)\') AS product, count(*) AS orders, sum(o.quantity) AS pieces, sum(o.total_cents) AS total_cents
                FROM print_orders o LEFT JOIN print_products p ON p.id = o.print_product_id
                WHERE o.deleted_at IS NULL AND o.status <> \'draft\' AND coalesce(o.submitted_at, o.created_at) BETWEEN ? AND ?
                GROUP BY 1 ORDER BY sum(o.total_cents) DESC', [$from, $to]],
            'businesses' => ["
                SELECT b.name AS business, b.state,
                       (SELECT count(*) FROM campaigns c WHERE c.business_id = b.id AND c.status <> 'draft' AND c.deleted_at IS NULL) AS campaigns,
                       (SELECT count(*) FROM print_orders o WHERE o.business_id = b.id AND o.status <> 'draft' AND o.deleted_at IS NULL) AS print_orders,
                       sum(p.amount_cents + p.credits_applied_cents - p.refunded_cents) AS spend_cents
                FROM payments p JOIN businesses b ON b.id = p.business_id
                WHERE p.status IN {$paid} AND p.paid_at BETWEEN ? AND ?
                GROUP BY b.id ORDER BY spend_cents DESC LIMIT 50", [$from, $to]],
        };

        return array_map(fn ($r) => (array) $r, DB::select($sql[0], $sql[1]));
    }

    public function index(Request $request): Response
    {
        $report = array_key_exists((string) $request->input('report'), self::REPORTS) ? (string) $request->input('report') : 'revenue';

        return Inertia::render('admin/reports/index', [
            'report' => $report,
            'reports' => collect(self::REPORTS)->map(fn ($r, $k) => ['key' => $k, 'title' => $r[0], 'columns' => $r[1]])->values(),
            'rows' => $this->rows($report, $request),
            'filters' => $request->only(['from', 'to']),
        ]);
    }

    public function export(Request $request, string $report): StreamedResponse
    {
        abort_unless(array_key_exists($report, self::REPORTS), 404);
        $cols = self::REPORTS[$report][1];
        $rows = (function () use ($report, $request, $cols) {
            foreach ($this->rows($report, $request) as $r) {
                yield array_map(fn ($k) => str_ends_with($k, '_cents') ? Csv::money((int) $r[$k]) : $r[$k], array_keys($cols));
            }
        })();

        return Csv::stream("epfd-report-{$report}-".now()->format('Ymd').'.csv', array_map(fn ($l, $k) => str_ends_with($k, '_cents') ? "{$l} (AUD)" : $l, $cols, array_keys($cols)), $rows);
    }
}
