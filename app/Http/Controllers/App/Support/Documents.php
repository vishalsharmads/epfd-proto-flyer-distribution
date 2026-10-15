<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Support;

use App\Models\Campaign;
use App\Models\Invoice;
use App\Models\PrintOrder;
use App\Models\Run;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Tax invoice + proof-of-distribution PDFs (dompdf). */
final class Documents
{
    public static function invoicePdf(Invoice $invoice): DomPdf
    {
        $invoice->loadMissing('business', 'invoiceable', 'payment');
        $job = $invoice->invoiceable;

        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'business' => $invoice->business,
            'job' => $job,
            'jobLabel' => $job instanceof Campaign ? 'Letterbox distribution — '.$job->name : ($job instanceof PrintOrder ? 'Print order' : 'Order'),
            ...self::common(),
        ])->setPaper('a4');
    }

    public static function proofPdf(Campaign $campaign): DomPdf
    {
        $runs = $campaign->runs()->withCount('gpsPoints')->orderBy('id')->get();
        $areas = $campaign->areas()->withGeoJson()->get();

        return Pdf::loadView('pdf.proof', [
            'campaign' => $campaign,
            'business' => $campaign->business,
            'runs' => $runs,
            'sketch' => self::sketch($areas->map(fn ($a) => $a->geoJson())->filter()->values()->all(), $runs),
            ...self::common(),
        ])->setPaper('a4');
    }

    /** @return array<string, mixed> */
    private static function common(): array
    {
        $logo = public_path((string) config('epfd.brand.logo'));

        return [
            'brand' => config('epfd.brand'),
            'logo' => is_file($logo) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logo)) : null,
            'tz' => config('epfd.timezone'),
        ];
    }

    /**
     * Inline SVG sketch of area polygons + GPS tracks (equirectangular projection), base64 for dompdf.
     *
     * @param  list<array<string, mixed>>  $polygons
     * @param  Collection<int, Run>  $runs
     */
    private static function sketch(array $polygons, $runs): ?string
    {
        $tracks = [];
        foreach ($runs as $r) {
            $tracks[] = array_map(fn ($p) => [(float) $p->lng, (float) $p->lat], DB::select('SELECT ST_X(geom) AS lng, ST_Y(geom) AS lat FROM run_gps_points WHERE run_id = ? ORDER BY recorded_at', [$r->id]));
        }
        $all = [];
        foreach ($polygons as $g) {
            foreach ($g['coordinates'][0] ?? [] as $pt) {
                $all[] = $pt;
            }
        }
        foreach ($tracks as $t) {
            array_push($all, ...$t);
        }
        if (count($all) < 3) {
            return null;
        }
        $xs = array_column($all, 0);
        $ys = array_column($all, 1);
        [$minX, $maxX, $minY, $maxY] = [min($xs), max($xs), min($ys), max($ys)];
        $kx = cos(deg2rad(($minY + $maxY) / 2));
        $w = 480;
        $h = 260;
        $pad = 12;
        $sx = ($maxX - $minX) * $kx ?: 0.0001;
        $sy = ($maxY - $minY) ?: 0.0001;
        $s = min(($w - 2 * $pad) / $sx, ($h - 2 * $pad) / $sy);
        $proj = fn ($p) => sprintf('%.1f,%.1f', $pad + ($p[0] - $minX) * $kx * $s, $h - $pad - ($p[1] - $minY) * $s);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'"><rect width="100%" height="100%" fill="#f5f5f4"/>';
        foreach ($polygons as $g) {
            $svg .= '<polygon points="'.implode(' ', array_map($proj, $g['coordinates'][0] ?? [])).'" fill="#E00B33" fill-opacity="0.12" stroke="#141414" stroke-width="1.5"/>';
        }
        foreach ($tracks as $t) {
            if (count($t) > 1) {
                $svg .= '<polyline points="'.implode(' ', array_map($proj, $t)).'" fill="none" stroke="#2563eb" stroke-width="1.5"/>';
            }
        }
        $svg .= '</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
