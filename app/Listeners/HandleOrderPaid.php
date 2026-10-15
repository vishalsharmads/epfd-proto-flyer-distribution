<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Campaigns\CampaignService;
use App\Domain\Payments\Events\OrderPaid;
use App\Mail\InvoiceMail;
use App\Models\DesignOrder;
use App\Models\PrintOrder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * After payment: email the tax invoice, and for a print order with "Also distribute these flyers"
 * create a linked draft distribution campaign (material from the product).
 */
class HandleOrderPaid
{
    public function __construct(private readonly CampaignService $campaigns) {}

    public function handle(OrderPaid $event): void
    {
        $payable = $event->payable;

        if ($payable instanceof PrintOrder && $payable->also_distribute && ! $payable->campaign_id) {
            $payable->loadMissing('product', 'business', 'user');
            $campaign = $this->campaigns->createDraft($payable->business, $payable->user ?? $payable->business->users()->firstOrFail(), [
                'name' => $this->campaigns->uniqueName($payable->business, 'Distribute '.$payable->reference),
                'material' => $payable->product?->distribution_material ?: 'standard_dl',
                'print_order_id' => $payable->id,
            ]);
            $payable->forceFill(['campaign_id' => $campaign->id])->saveQuietly();
        }

        try {
            $invoice = $payable->invoice()->latest('id')->first();
            $user = $payable->user ?? $payable->business->users()->first();
            if ($invoice && $user) {
                [$url, $jobName] = match (true) {
                    $payable instanceof PrintOrder => [route('app.jobs.print', $payable), 'print order '.$payable->reference],
                    $payable instanceof DesignOrder => [route('app.jobs.design', $payable), 'design order '.$payable->reference],
                    default => [route('app.jobs.campaign', $payable), $payable->name],
                };
                Mail::to($user->email)->send(new InvoiceMail($invoice, $user->name, $jobName, $url));
            }
        } catch (Throwable $e) {
            // Never fail a successful payment because email is down. No PII in the log line.
            Log::warning('Invoice email failed', ['payable' => $payable->reference, 'error' => $e->getMessage()]);
        }
    }
}
