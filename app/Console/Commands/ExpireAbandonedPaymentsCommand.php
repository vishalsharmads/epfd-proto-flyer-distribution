<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Support\Audit;
use Illuminate\Console\Command;

/**
 * Close payments still waiting on 3-D Secure after a day: the customer never finished authenticating.
 * Their job stays a draft, so they can simply pay again. If they do authenticate later, the gateway's success
 * webhook still completes the payment.
 */
class ExpireAbandonedPaymentsCommand extends Command
{
    protected $signature = 'payments:expire-abandoned {--hours=24 : Age after which a requires_action payment is closed}';

    protected $description = 'Mark payments that never completed 3-D Secure as failed';

    public function handle(): int
    {
        $count = 0;
        Payment::where('status', 'requires_action')
            ->where('created_at', '<', now()->subHours((int) $this->option('hours')))
            ->each(function (Payment $payment) use (&$count) {
                $payment->forceFill(['status' => 'failed', 'failure_reason' => 'Authentication was not completed.'])->save();
                Audit::log('payment.abandoned', $payment);
                $count++;
            });

        $this->info("Closed {$count} abandoned payment(s).");

        return self::SUCCESS;
    }
}
