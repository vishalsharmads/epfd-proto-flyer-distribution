<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Payments\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Readiness check for load balancers and uptime monitors: database, cache, storage, queue backlog and the
 * payment gateway. Answers 503 when something critical is down. `/up` stays the cheap liveness check.
 * Reports only ok/fail per check, never error details or configuration.
 */
class HealthController extends Controller
{
    /** Jobs waiting longer than this mean the queue worker is down or overwhelmed. */
    private const QUEUE_STALE_SECONDS = 600;

    public function __invoke(PaymentGatewayManager $gateways): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'cache' => $this->check(function () {
                $key = 'health:'.Str::random(8);
                Cache::put($key, 'ok', 10);
                throw_unless(Cache::pull($key) === 'ok', 'Cache read-back failed');
            }),
            'storage' => $this->check(function () {
                $path = 'health/'.Str::random(8);
                Storage::disk('local')->put($path, 'ok');
                Storage::disk('local')->delete($path);
            }),
            'queue' => $this->check(function () {
                if (config('queue.default') !== 'database') {
                    return;
                }
                $oldest = DB::table(config('queue.connections.database.table', 'jobs'))->whereNull('reserved_at')->min('available_at');
                throw_if($oldest && time() - (int) $oldest > self::QUEUE_STALE_SECONDS, 'Queue backlog is stale');
            }),
            'payments' => $this->check(fn () => $gateways->driver()),
        ];
        $healthy = ! in_array(false, $checks, true);

        return response()->json(['status' => $healthy ? 'ok' : 'fail', 'checks' => array_map(fn (bool $ok) => $ok ? 'ok' : 'fail', $checks)], $healthy ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    private function check(callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
