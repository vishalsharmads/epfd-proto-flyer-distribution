<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Campaigns\CampaignService;
use App\Domain\Design\DesignService;
use App\Domain\Payments\CheckoutService;
use App\Domain\Runs\RunService;
use App\Exceptions\DomainProblem;
use App\Http\Controllers\App\Support\PrintPricer;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\CampaignArea;
use App\Models\DesignOrder;
use App\Models\Message;
use App\Models\Payment;
use App\Models\PrintOrder;
use App\Models\PrintProduct;
use App\Models\Run;
use App\Models\SavedArea;
use App\Models\SupportThread;
use App\Models\User;
use App\Support\Audit;
use App\Support\Geo;
use App\Support\Reference;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Demo world (spec §10) built through the real domain services so counts, fees, payments, invoices,
 * runs, GPS tracks and audit logs are genuine. Requires `php artisan gnaf:import --sample`.
 *
 * Idempotent: users/businesses are upserted; the transactional data (campaigns, print orders, payments,
 * threads…) is only created when the VIC/QLD demo businesses have no campaigns yet. Design Studio orders
 * have their own guard (only created when the demo businesses have no design orders), so an existing
 * demo database gains them on the next seed without duplicating anything else.
 * Time travel (Carbon::setTestNow) spreads the activity over the last 12 months.
 */
class DemoDataSeeder extends Seeder
{
    private const CARD = ['number' => '4242424242424242', 'exp_month' => 12, 'exp_year' => 2029, 'cvc' => '123'];

    /** @var array<string, Business> */
    private array $biz = [];

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, User> */
    private array $staff = [];

    /** @var list<User> */
    private array $drivers = [];

    /** @var array<int, int> business id => saved card id */
    private array $savedCard = [];

    private Carbon $realNow;

    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly CheckoutService $checkout,
        private readonly RunService $runs,
        private readonly DesignService $design,
    ) {}

    public function run(): void
    {
        config(['mail.default' => 'array', 'queue.default' => 'sync']);
        // Every demo payment renders a tax-invoice PDF attachment; dompdf holds ~4 MB per render, which
        // exceeds PHP's default 128 MB across ~30 demo payments.
        if ((int) ini_get('memory_limit') > 0 && (int) ini_get('memory_limit') < 512) {
            ini_set('memory_limit', '512M');
        }

        $this->accounts();

        if (! DB::table('suburbs')->exists()) {
            $this->command?->warn('DemoDataSeeder: no suburbs — run `php artisan gnaf:import --sample` first. Skipping demo jobs.');

            return;
        }
        $demo = [$this->biz['nsw']->id, $this->biz['vic']->id, $this->biz['qld']->id];
        if (Campaign::withTrashed()->whereIn('business_id', [$this->biz['vic']->id, $this->biz['qld']->id])->exists()) {
            $this->command?->info('DemoDataSeeder: demo jobs already present — skipping.');
        } else {
            $this->realNow = Carbon::now();
            try {
                $this->world();
            } finally {
                Carbon::setTestNow();
                Auth::guard('web')->forgetUser();
            }
        }

        if (DesignOrder::withTrashed()->whereIn('business_id', $demo)->exists()) {
            $this->command?->info('DemoDataSeeder: demo design orders already present — skipping.');

            return;
        }
        $this->realNow = Carbon::now();
        try {
            $this->designOrders();
        } finally {
            Carbon::setTestNow();
            Auth::guard('web')->forgetUser();
        }
    }

    // ------------------------------------------------------------------ accounts

    private function accounts(): void
    {
        foreach (['admin' => 'admin@epfd.test', 'ops' => 'ops@epfd.test', 'finance' => 'finance@epfd.test', 'support' => 'support@epfd.test'] as $k => $email) {
            $this->staff[$k] = User::where('email', $email)->firstOrFail();
        }

        $this->biz['nsw'] = Business::where('email', 'owner@harbourcoffee.test')->firstOrFail();
        $this->users['nsw'] = User::where('email', 'demo@business.test')->firstOrFail();

        $defs = [
            'vic' => ['vic@business.test', 'Swan St Physio', 'Marcus Nguyen', '0423 111 222', '33102417032', 'Health', '220 Swan Street, Richmond VIC 3121', 'VIC', 145.0003, -37.8250, 'approved'],
            'qld' => ['qld@business.test', 'Brunswick Bakery', 'Priya Shah', '0434 555 666', '74172177893', 'Hospitality', '650 Brunswick Street, New Farm QLD 4005', 'QLD', 153.0440, -27.4665, 'approved'],
            'pending' => ['pending@business.test', 'Crown St Barbers', 'Liam O\'Brien', '0445 777 888', '53004085616', 'Personal services', '150 Crown Street, Wollongong NSW 2500', 'NSW', 150.8930, -34.4260, 'pending'],
        ];
        foreach ($defs as $k => [$email, $name, $contact, $mobile, $abn, $industry, $address, $state, $lng, $lat, $status]) {
            $this->biz[$k] = Business::updateOrCreate(['email' => $email], [
                'name' => $name, 'contact_name' => $contact, 'mobile' => $mobile, 'abn' => $abn, 'industry' => $industry,
                'address' => $address, 'state' => $state, 'geom' => Geo::point($lng, $lat), 'status' => $status,
                'approved_at' => $status === 'approved' ? now()->subMonths(11) : null,
                'approved_by' => $status === 'approved' ? $this->staff['ops']->id : null,
            ]);
            $this->users[$k] = User::updateOrCreate(['email' => $email], [
                'name' => $contact, 'business_id' => $this->biz[$k]->id, 'mobile' => $mobile, 'password' => 'password', 'email_verified_at' => now(),
            ]);
        }

        foreach ([['driver@epfd.test', 'Dylan Driver', '0400 100 200'], ['driver2@epfd.test', 'Dana Walker', '0400 300 400']] as [$email, $name, $mobile]) {
            $this->drivers[] = User::updateOrCreate(['email' => $email], [
                'name' => $name, 'mobile' => $mobile, 'password' => 'password', 'email_verified_at' => now(), 'is_distributor' => true,
            ]);
        }
    }

    // ------------------------------------------------------------------ world

    private function world(): void
    {
        // Rectangles: [lng, lat, half-width°, half-height°] inside the sample suburbs.
        $rect = fn (float $lng, float $lat, float $dx = 0.0025, float $dy = 0.0018) => Geo::polygon([
            [$lng - $dx, $lat - $dy], [$lng + $dx, $lat - $dy], [$lng + $dx, $lat + $dy], [$lng - $dx, $lat + $dy],
        ]);

        // Credit top-up for VIC (later partly applied at checkout).
        $this->at(330, function () {
            $this->as($this->staff['finance']);
            $this->checkout->adjustCredits($this->biz['vic'], 5000, 'Credit top-up: loyalty bonus', null, $this->staff['finance']);
        });

        // [business, name, areas, status, days ago paid, options]
        $specs = [
            ['nsw', 'Rydalmere autumn drop', [['Rydalmere north', $rect(151.0400, -33.8090)]], 'completed', 340, ['rating' => 5, 'comment' => 'Great job, lots of new faces in store.']],
            ['nsw', 'Ermington winter menu', [['Ermington', $rect(151.0600, -33.8100, 0.004, 0.0022)]], 'completed', 285, ['split' => true, 'fail' => true, 'rating' => 3, 'comment' => 'One street was missed.']],
            ['vic', 'Richmond clinic launch', [['Swan St', $rect(145.0000, -37.8230)]], 'completed', 250, ['rating' => 4]],
            ['nsw', 'Ermington loyalty card', [['Ermington south', $rect(151.0590, -33.8180)]], 'cancelled', 215, ['refund' => 'full']],
            ['qld', 'New Farm sourdough week', [['New Farm', $rect(153.0460, -27.4660)]], 'completed', 190, ['rating' => 5, 'comment' => 'Sold out by Saturday!']],
            ['vic', 'Abbotsford winter physio', [['Abbotsford', $rect(145.0000, -37.8040)]], 'completed', 160, []],
            ['nsw', 'Dundas Valley spring', [['Dundas Valley', $rect(151.0560, -33.7920)], ['Dundas Valley east', $rect(151.0620, -33.7900, 0.002, 0.0015)]], 'completed', 120, ['rating' => 4, 'refund' => 'credit']],
            ['qld', 'New Farm Christmas orders', [['New Farm west', $rect(153.0420, -27.4690)]], 'completed', 70, ['rating' => 5]],
            ['vic', 'Richmond pilates classes', [['Richmond east', $rect(145.0080, -37.8200)]], 'in_delivery', 6, []],
            ['nsw', 'Parramatta coffee cart', [['Parramatta', $rect(151.0050, -33.8150)]], 'collected', 5, []],
            ['qld', 'New Farm brunch menu', [['New Farm park', $rect(153.0500, -27.4700)]], 'allocated', 4, []],
            ['vic', 'Richmond open day', [['Richmond south', $rect(145.0000, -37.8290)]], 'runs_published', 3, ['credits' => true]],
            ['nsw', 'Rydalmere new opening hours', [['Rydalmere south', $rect(151.0380, -33.8170)]], 'submitted', 1, ['promo' => 'WELCOME10']],
            ['nsw', 'Ermington summer specials', [['Ermington east', $rect(151.0660, -33.8050)]], 'draft', 0, ['decline' => true]],
            ['vic', 'Abbotsford flyer idea', [], 'draft', 0, []],
            ['qld', 'New Farm catering', [['New Farm north', $rect(153.0440, -27.4600)]], 'draft', 0, []],
        ];

        $made = [];
        foreach ($specs as $i => [$key, $name, $areas, $status, $ago, $opt]) {
            $made[$name] = $this->campaign($key, $name, $areas, $status, $ago, $opt, $i);
        }

        $this->printOrders($rect);
        $this->savedAreas();
        $this->threads($made);
    }

    /** @param list<array{0:string,1:array<string,mixed>}> $areas */
    private function campaign(string $key, string $name, array $areas, string $status, int $ago, array $opt, int $i): Campaign
    {
        $biz = $this->biz[$key];
        $user = $this->users[$key];
        $paidAt = $this->realNow->copy()->subDays($ago)->setTime(9 + $i % 6, 15 + $i);

        $this->travel($paidAt->copy()->subDays($ago > 2 ? 2 : 0)->subHours(3));
        $this->as($user);
        $campaign = $this->campaigns->createDraft($biz, $user, ['name' => $name, 'available_from' => $paidAt->copy()->addDay()->toDateString()]);
        if ($areas !== []) {
            $campaign = $this->campaigns->syncAreas($campaign, array_map(fn ($a) => ['name' => $a[0], 'draw_mode' => 'pins', 'geometry' => $a[1]], $areas));
        }
        if (! empty($opt['promo'])) {
            $campaign = $this->campaigns->update($campaign, ['promo_code' => $opt['promo']]);
        }
        $campaign->forceFill(['step' => $areas === [] ? 1 : 3])->save();

        if (! empty($opt['decline'])) {
            try {
                $this->checkout->pay($campaign, $user, ['card' => [...self::CARD, 'number' => '4000000000000002', 'name' => $user->name], 'billing' => $this->billing($biz)]);
            } catch (DomainProblem) {
                // Expected: leaves a failed payment for the admin payments list.
            }
        }
        if ($status === 'draft') {
            return $campaign;
        }

        $this->travel($paidAt);
        $payment = $this->pay($campaign, $key, ! empty($opt['credits']));
        $campaign->refresh();

        if ($status === 'cancelled') {
            $this->travel($paidAt->copy()->addDay());
            $this->as($this->staff['finance']);
            $campaign->forceFill(['status' => 'cancelled'])->save();
            Audit::log('campaign.status_changed', $campaign, ['from' => 'submitted', 'to' => 'cancelled', 'reason' => 'Customer request']);
            $this->checkout->refund($payment, $payment->amount_cents, $this->staff['finance'], 'Customer cancelled before runs were created');

            return $campaign;
        }
        if ($status === 'submitted') {
            return $campaign;
        }

        // Runs.
        $this->travel($paidAt->copy()->addHours(20));
        $this->as($this->staff['ops']);
        if (! empty($opt['split'])) {
            $area = $campaign->areas()->withGeoJson()->first();
            $ring = $area->geoJson()['coordinates'][0];
            $xs = array_column($ring, 0);
            $ys = array_column($ring, 1);
            $mid = (min($xs) + max($xs)) / 2;
            $this->runs->split($campaign, [
                ['name' => $area->name.' west', 'geometry' => Geo::polygon([[min($xs), min($ys)], [$mid, min($ys)], [$mid, max($ys)], [min($xs), max($ys)]])],
                ['name' => $area->name.' east', 'geometry' => Geo::polygon([[$mid, min($ys)], [max($xs), min($ys)], [max($xs), max($ys)], [$mid, max($ys)]])],
            ]);
        } else {
            $this->runs->onePerArea($campaign);
        }
        $this->runs->publish($campaign);
        if ($status === 'runs_published') {
            return $campaign->refresh();
        }

        $order = ['allocated', 'collected', 'in_delivery', 'completed'];
        $target = array_search($status, $order, true);
        $runs = $campaign->runs()->orderBy('id')->get();
        foreach ($runs as $n => $run) {
            /** @var Run $run */
            $driver = $this->drivers[($i + $n) % 2];
            $this->travel($paidAt->copy()->addDay()->addHours($n));
            $this->runs->assign($run, $driver);
            if ($target >= 1) {
                $this->travel($paidAt->copy()->addDays(2)->setTime(7, 30 + $n));
                $this->runs->transition($run->refresh(), 'collected');
            }
            if ($target >= 2) {
                $this->travel($paidAt->copy()->addDays(2)->setTime(8 + $n, 0));
                $this->runs->transition($run->refresh(), 'in_delivery');
                $this->runs->simulateGps($run->refresh());
            }
            if ($target >= 3) {
                $this->travel($paidAt->copy()->addDays(2)->setTime(15 + $n, 30));
                $failed = ! empty($opt['fail']) && $n === count($runs) - 1;
                if ($failed) {
                    // Walker abandoned the run part-way: keep the first 35% of the track.
                    $keep = (int) ceil($run->gpsPoints()->count() * 0.35);
                    $cut = $run->gpsPoints()->orderBy('recorded_at')->skip($keep)->value('recorded_at');
                    if ($cut) {
                        $run->gpsPoints()->where('recorded_at', '>=', $cut)->delete();
                    }
                    $this->runs->transition($run->refresh(), 'failed');
                } else {
                    $this->runs->transition($run->refresh(), 'completed');
                }
                $this->runs->computeCoverage($run->refresh());
            }
        }
        $campaign->refresh();

        if ($status === 'completed' && isset($opt['rating'])) {
            $this->travel($paidAt->copy()->addDays(4));
            $campaign->forceFill(['rating' => $opt['rating'], 'rating_comment' => $opt['comment'] ?? null])->save();
        }
        if (($opt['refund'] ?? null) === 'credit') {
            $this->travel($paidAt->copy()->addDays(5));
            $this->as($this->staff['finance']);
            $this->checkout->refund($payment->refresh(), 2500, $this->staff['finance'], 'Goodwill for late delivery', true);
        }
        if ($campaign->status !== $status) {
            throw new RuntimeException("Demo campaign {$name} ended as {$campaign->status}, expected {$status}.");
        }

        return $campaign;
    }

    private function pay(Campaign|PrintOrder|DesignOrder $payable, string $key, bool $useCredits = false): Payment
    {
        $biz = $this->biz[$key];
        $user = $this->users[$key];
        $this->as($user);
        $input = ['billing' => $this->billing($biz), 'use_credits' => $useCredits];
        if (isset($this->savedCard[$biz->id])) {
            $input['saved_card_id'] = $this->savedCard[$biz->id];
        } else {
            $input['card'] = [...self::CARD, 'name' => $user->name];
            $input['save_card'] = true;
        }
        $payment = $this->checkout->pay($payable, $user, $input);
        $this->savedCard[$biz->id] ??= (int) $biz->savedCards()->value('id');

        return $payment;
    }

    /** @return array{company:string, abn:string|null, address:string} */
    private function billing(Business $biz): array
    {
        return ['company' => $biz->name, 'abn' => $biz->abn, 'address' => $biz->address];
    }

    // ------------------------------------------------------------------ print

    private function printOrders(callable $rect): void
    {
        // [business, product slug, gsm, sides, qty, status, days ago, options]
        $specs = [
            ['nsw', 'dl-flyers', 150, 1, 2500, 'completed', 300, ['rating' => 5]],
            ['vic', 'a5-flyers', 150, 2, 1000, 'completed', 200, ['also_distribute' => true, 'rating' => 4]],
            ['qld', 'business-cards', 250, 2, 500, 'cancelled', 150, []],
            ['nsw', 'a4-flyers', 170, 1, 1000, 'shipped', 12, ['vendor' => 'Sydney Print Co.', 'tracking' => 'AP123456789AU']],
            ['qld', 'a5-flyers', 150, 1, 2500, 'in_printing', 8, []],
            ['vic', 'magnets', 250, 1, 500, 'awaiting_artwork_approval', 4, []],
            ['nsw', 'a6-flyers', 115, 1, 1000, 'submitted', 2, ['promo' => 'WELCOME10']],
            ['nsw', 'a3-folded-brochures', 150, 2, 250, 'draft', 0, []],
        ];

        foreach ($specs as $i => [$key, $slug, $gsm, $sides, $qty, $status, $ago, $opt]) {
            $biz = $this->biz[$key];
            $user = $this->users[$key];
            $at = $this->realNow->copy()->subDays($ago)->setTime(10 + $i % 5, 5 * $i);
            $product = PrintProduct::where('slug', $slug)->firstOrFail();

            $this->travel($at->copy()->subHours(2));
            $this->as($user);
            $finishes = (array) ($product->finish_options[(string) $gsm] ?? ['matt']);
            $order = PrintOrder::create([
                'business_id' => $biz->id, 'user_id' => $user->id, 'reference' => Reference::make('PRT', 'print_orders'),
                'print_product_id' => $product->id, 'status' => 'draft', 'step' => 4, 'gsm' => $gsm, 'finish' => $finishes[0],
                'sides' => $sides, 'orientation' => $product->width_mm > $product->height_mm ? 'landscape' : 'portrait', 'quantity' => $qty,
                'promo_code' => $opt['promo'] ?? null, 'also_distribute' => ! empty($opt['also_distribute']),
                'delivery_name' => $biz->contact_name, 'delivery_address' => $biz->address, 'delivery_phone' => $biz->mobile,
            ]);
            PrintPricer::reprice($order, $i === 0 ? ['express'] : []);
            foreach ($sides === 2 ? ['front', 'back'] : ['front'] as $side) {
                $this->artwork($order, $side, $biz->name);
            }
            if ($status === 'draft') {
                continue;
            }

            $this->travel($at);
            $order->forceFill(['right_to_print' => true])->save();
            $payment = $this->pay($order->refresh(), $key);
            $order->refresh();

            $this->as($this->staff['ops']);
            $flow = ['submitted', 'awaiting_artwork_approval', 'in_printing', 'shipped', 'completed'];
            $target = $status === 'cancelled' ? 0 : array_search($status, $flow, true);
            for ($s = 1; $s <= $target; $s++) {
                $this->travel($at->copy()->addDays($s));
                $to = $flow[$s];
                $fill = ['status' => $to];
                if ($to === 'in_printing') {
                    $order->artworks()->update(['status' => 'approved', 'reviewed_by' => $this->staff['ops']->id, 'reviewed_at' => now(), 'review_note' => 'Looks good — print ready.']);
                }
                if ($to === 'shipped') {
                    $fill += ['vendor' => $opt['vendor'] ?? 'EPFD Print Partners', 'tracking_number' => $opt['tracking'] ?? 'AP'.random_int(100000000, 999999999).'AU'];
                }
                if ($to === 'completed') {
                    $fill += ['completed_at' => now(), 'rating' => $opt['rating'] ?? null];
                }
                Audit::log('print_order.status_changed', $order, ['from' => $order->status, 'to' => $to]);
                $order->forceFill($fill)->save();
            }

            if ($status === 'cancelled') {
                $this->travel($at->copy()->addDay());
                $this->as($this->staff['finance']);
                Audit::log('print_order.status_changed', $order, ['from' => $order->status, 'to' => 'cancelled']);
                $order->forceFill(['status' => 'cancelled'])->save();
                $this->checkout->refund($payment, $payment->amount_cents, $this->staff['finance'], 'Order cancelled — refunded as account credit', true);
            }

            // "Also distribute": the OrderPaid listener created a linked draft campaign — give it a real area.
            if (! empty($opt['also_distribute']) && ($campaign = $order->campaign)) {
                $this->travel($at->copy()->addDays(5));
                $this->as($user);
                $this->campaigns->syncAreas($campaign, [['name' => 'Abbotsford flyers', 'draw_mode' => 'pins', 'geometry' => $rect(144.9980, -37.8080)]]);
            }
        }
    }

    private function artwork(PrintOrder $order, string $side, string $label): void
    {
        $w = 620;
        $h = 874;
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagefilledrectangle($img, 0, 0, $w, 180, imagecolorallocate($img, 0xE0, 0x0B, 0x33));
        imagefilledrectangle($img, 0, $h - 80, $w, $h, imagecolorallocate($img, 0xFF, 0xC4, 0x46));
        $ink = imagecolorallocate($img, 0x14, 0x14, 0x14);
        imagestring($img, 5, 30, 80, strtoupper($label), imagecolorallocate($img, 255, 255, 255));
        imagestring($img, 5, 30, 240, $order->reference.' - '.$side, $ink);
        imagestring($img, 4, 30, 280, 'Demo artwork generated by DemoDataSeeder', $ink);
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        $path = "artworks/{$order->id}/demo-{$side}.png";
        Storage::disk('local')->put($path, $png);
        $order->artworks()->create([
            'side' => $side, 'path' => $path, 'original_name' => "{$side}-artwork.png", 'mime' => 'image/png',
            'size_bytes' => strlen($png), 'width_px' => $w, 'height_px' => $h, 'status' => 'pending',
        ]);
    }

    // ------------------------------------------------------------------ design studio

    private function designOrders(): void
    {
        $ops = $this->staff['ops'];
        // [business, type, package, sides, target status, days ago paid, headline, key message, options]
        $specs = [
            ['nsw', 'flyer', 'standard', 2, 'approved', 16, 'Now open Sundays', 'Harbour Coffee is now open 7 days — Sunday brunch from 8am with $5 flat whites.', ['changes' => 1, 'print' => true, 'product' => 'a5-flyers', 'logo' => true]],
            ['vic', 'flyer', 'basic', 1, 'completed', 45, 'Back pain? Book a free assessment', 'New patients get a free 20-minute assessment this month.', ['finals' => true]],
            ['vic', 'business_card', 'basic', 2, 'proof_ready', 4, 'Marcus Nguyen — Physiotherapist', 'Clean, clinical, Swan St Physio teal.', ['changes' => 1, 'proofs' => 2]],
            ['qld', 'brochure', 'premium', 1, 'changes_requested', 6, 'Brunswick Bakery catering', 'Platters, cakes and sourdough for offices and events across Brisbane.', ['changes' => 1, 'logo' => true]],
            ['nsw', 'stationery', 'standard', 1, 'in_design', 2, 'Harbour Coffee Co. stationery', 'Letterhead, compliments slip and envelope matching our new cups.', []],
            ['qld', 'presentation_folder', 'standard', 1, 'submitted', 1, 'Corporate catering folder', 'Pocket folder for our catering menu and price list.', []],
            ['nsw', 'flyer', 'premium', 1, 'draft', 0, 'Christmas trading hours', '', []],
        ];

        foreach ($specs as $i => [$key, $type, $package, $sides, $status, $ago, $headline, $message, $opt]) {
            $biz = $this->biz[$key];
            $user = $this->users[$key];
            $at = $this->realNow->copy()->subDays($ago)->setTime(9 + $i % 5, 10 + $i);

            $this->travel($at->copy()->subHours(3));
            $this->as($user);
            $productId = isset($opt['product']) ? PrintProduct::where('slug', $opt['product'])->value('id') : null;
            $order = $this->design->createDraft($biz, $user, array_filter([
                'design_type' => $type, 'package' => $package, 'sides' => $sides, 'print_product_id' => $productId,
                'headline' => $headline, 'key_message' => $message ?: null,
                'call_to_action' => 'Visit '.$biz->address, 'brand_colours' => $key === 'vic' ? 'Teal #0F766E, white' : 'Navy #1B2A4A, cream #F5EFE6',
                'style_notes' => 'Friendly and uncluttered. Logo top-left.', 'target_audience' => 'Local residents within 3 km',
                'due_date' => $ago > 0 ? null : $this->realNow->copy()->addDays(10)->toDateString(),
                'addons' => $i === 0 ? ['stock_imagery'] : [],
            ], fn ($v) => $v !== null && $v !== []));
            $order->forceFill(['step' => 4])->save();
            if (! empty($opt['logo'])) {
                $this->design->addBriefUpload($order, $this->png('logo', $biz->name, 'LOGO', 400, 400), $user);
            }
            if ($status === 'draft') {
                continue;
            }

            $this->travel($at);
            $this->pay($order->refresh(), $key);
            $order->refresh();

            $flow = ['submitted', 'in_design', 'proof_ready', 'approved', 'completed'];
            $target = $status === 'changes_requested' ? 2 : array_search($status, $flow, true);
            $this->as($ops);
            if ($target >= 1) {
                $this->travel($at->copy()->addHours(4));
                $this->design->assign($order, $ops, $ops);
                $this->design->transition($order->refresh(), 'in_design', 'Picked up by the studio.');
            }
            if ($target >= 2) {
                $version = 0;
                $rounds = (int) ($opt['changes'] ?? 0);
                for ($r = 0; $r <= $rounds; $r++) {
                    $this->travel($at->copy()->addDays(1 + $r * 2));
                    $this->as($ops);
                    $version++;
                    $this->design->uploadProof($order->refresh(), $this->png("proof-v{$version}", $headline, "PROOF v{$version}"), $ops, $version === 1 ? 'First concept — let us know what you think.' : 'Updated with your changes.');
                    $lastRound = $r === $rounds;
                    if ($lastRound && $status !== 'changes_requested') {
                        break;
                    }
                    $this->travel($at->copy()->addDays(2 + $r * 2));
                    $this->as($user);
                    $this->design->requestChanges($order->refresh(), $user, $r === 0 ? 'Can the headline be bigger and the logo a little smaller?' : 'Nearly there — please use the darker shade of our brand colour.');
                    if ($lastRound) {
                        break;
                    }
                }
            }
            if ($status === 'changes_requested') {
                $this->travel($at->copy()->addDays(3)->addHours(2));
                $this->design->postMessage($order->refresh(), $ops, 'Thanks — working on it now, new proof tomorrow.', true);
            }
            if ($target >= 3) {
                $this->travel($at->copy()->addDays(6));
                $this->as($user);
                $this->design->approve($order->refresh(), $user);
            }
            if (! empty($opt['print'])) {
                $this->travel($at->copy()->addDays(6)->addHour());
                $this->as($user);
                $print = $this->design->createPrintOrder($order->refresh(), $user);
                $print->forceFill(['right_to_print' => true])->save();
                $this->pay($print->refresh(), $key);
            }
            if ($target >= 4) {
                $this->travel($at->copy()->addDays(7));
                $this->as($ops);
                $this->design->uploadFinal($order->refresh(), $this->png('final', $headline, 'FINAL'), $ops, 'Print-ready PNG with 3 mm bleed.');
                $this->design->transition($order->refresh(), 'completed', 'Final files delivered.');
            }
            if ($order->refresh()->status !== $status) {
                throw new RuntimeException("Demo design order {$order->reference} ended as {$order->status}, expected {$status}.");
            }
        }
    }

    /** Generate a branded PNG (GD) and wrap it as an UploadedFile so it goes through the real upload path. */
    private function png(string $name, string $headline, string $stamp, int $w = 620, int $h = 874): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        $ink = imagecolorallocate($img, 0x14, 0x14, 0x14);
        $brand = imagecolorallocate($img, 0xE0, 0x0B, 0x33);
        $accent = imagecolorallocate($img, 0xFF, 0xC4, 0x46);
        if ($w === $h) {
            imagefilledellipse($img, intdiv($w, 2), intdiv($h, 2), $w - 40, $h - 40, $brand);
            imagestring($img, 5, intdiv($w, 2) - 40, intdiv($h, 2) - 8, $stamp, imagecolorallocate($img, 255, 255, 255));
        } else {
            imagefilledrectangle($img, 0, 0, $w, intdiv($h, 3), $brand);
            imagefilledrectangle($img, 0, $h - 90, $w, $h, $accent);
            imagestring($img, 5, 28, 60, mb_strimwidth(strtoupper($headline), 0, 60, '...'), imagecolorallocate($img, 255, 255, 255));
            imagestring($img, 4, 28, intdiv($h, 3) + 40, 'Designed by the EPFD Design Studio', $ink);
            imagestring($img, 5, $w - 150, $h - 55, $stamp, $ink);
        }
        $base = (string) tempnam(sys_get_temp_dir(), 'epfd-design-');
        @unlink($base);
        $path = $base.'.png';
        imagepng($img, $path);
        imagedestroy($img);

        return new UploadedFile($path, "{$name}.png", 'image/png', null, true);
    }

    // ------------------------------------------------------------------ saved areas + support

    private function savedAreas(): void
    {
        $this->travel($this->realNow->copy()->subDays(20));
        foreach (['nsw', 'vic', 'qld'] as $key) {
            CampaignArea::query()->whereHas('campaign', fn ($q) => $q->where('business_id', $this->biz[$key]->id))
                ->withGeoJson()->orderBy('id')->limit(2)->get()
                ->each(fn (CampaignArea $a) => SavedArea::create([
                    'business_id' => $this->biz[$key]->id, 'name' => $a->name, 'geom' => Geo::fromGeoJson($a->geoJson()),
                    'houses' => $a->houses, 'units' => $a->units,
                ]));
        }
    }

    /** @param array<string, Campaign> $campaigns */
    private function threads(array $campaigns): void
    {
        $support = $this->staff['support'];
        $defs = [
            // [business, subject, status, channel, related, days ago, messages [from_staff, body]]
            ['nsw', 'Can I change the pickup address?', 'open', 'support', null, 2, [
                [false, 'Hi team, we are moving our flyers to the Parramatta shop. Can the pickup address be changed for future campaigns?'],
            ]],
            ['vic', 'Invoice needs our ABN', 'pending', 'support', null, 9, [
                [false, 'Our last invoice is missing our ABN — can you reissue it?'],
                [true, 'Thanks Marcus — you can add your ABN under Account › Billing and download the invoice again. Could you confirm once done?'],
            ]],
            ['qld', 'Flyers for the brunch campaign', 'resolved', 'support', null, 30, [
                [false, 'How many flyers should we drop off for the New Farm campaign?'],
                [true, 'Please drop off the letterbox count shown on the job page plus 5% spare.'],
                [false, 'Perfect, thank you!'],
            ]],
            ['vic', 'Question about Richmond pilates classes', 'open', 'job', $campaigns['Richmond pilates classes'] ?? null, 1, [
                [false, 'Can the walker avoid Church Street this time? We did that one last month.'],
                [true, 'Noted — we have passed this on to your distributor.'],
                [false, 'Thanks!'],
            ]],
            ['nsw', 'Missed street on Ermington winter menu', 'resolved', 'job', $campaigns['Ermington winter menu'] ?? null, 270, [
                [false, 'The east side of the area did not seem to get flyers.'],
                [true, 'Sorry about that — one run was not completed. We have marked it failed and added account credit.'],
            ]],
        ];

        foreach ($defs as [$key, $subject, $status, $channel, $related, $ago, $msgs]) {
            $start = $this->realNow->copy()->subDays($ago)->setTime(11, 0);
            $this->travel($start);
            $thread = SupportThread::create([
                'business_id' => $this->biz[$key]->id, 'user_id' => $this->users[$key]->id, 'subject' => $subject, 'status' => $status,
                'priority' => $channel === 'job' ? 'high' : 'normal', 'channel' => $channel,
                'related_type' => $related?->getMorphClass(), 'related_id' => $related?->getKey(),
                'assigned_to' => $support->id, 'last_message_at' => $start,
            ]);
            foreach ($msgs as $n => [$fromStaff, $body]) {
                $t = $start->copy()->addHours($n * 3);
                $this->travel($t);
                Message::create([
                    'support_thread_id' => $thread->id, 'user_id' => $fromStaff ? $support->id : $this->users[$key]->id,
                    'from_staff' => $fromStaff, 'body' => $body, 'read_at' => $n < count($msgs) - 1 || $status === 'resolved' ? $t->copy()->addHour() : null,
                ]);
                $thread->forceFill(['last_message_at' => $t])->save();
            }
        }
    }

    // ------------------------------------------------------------------ helpers

    private function at(int $daysAgo, callable $fn): void
    {
        $this->travel($this->realNow->copy()->subDays($daysAgo));
        $fn();
    }

    /** Move the clock (never past the real "now"). */
    private function travel(Carbon $t): void
    {
        Carbon::setTestNow($t->greaterThan($this->realNow) ? $this->realNow : $t);
    }

    /** Act as this user so Audit::log() attributes entries. */
    private function as(User $user): void
    {
        Auth::guard('web')->setUser($user);
    }
}
