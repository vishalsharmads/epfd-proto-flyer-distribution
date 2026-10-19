<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\DesignAsset;
use App\Models\DesignOrder;
use Illuminate\Contracts\Queue\ShouldQueue;

/** "Your design proof is ready" — sent when staff upload a new proof version. */
final class DesignProofReadyMail extends BrandedMail implements ShouldQueue
{
    /** Sent by a queue worker once the surrounding transaction has committed, so the order it describes exists. */
    public $afterCommit = true;

    public function __construct(public readonly DesignOrder $order, public readonly DesignAsset $proof, public readonly string $name, public readonly string $jobUrl) {}

    protected function subjectLine(): string
    {
        return "Proof v{$this->proof->version} ready for {$this->order->reference} — please review";
    }

    protected function viewName(): string
    {
        return 'emails.design-proof';
    }

    protected function viewData(): array
    {
        $order = $this->order;
        $left = $order->revisions_included >= DesignOrder::UNLIMITED_REVISIONS ? null : max(0, $order->revisions_included - $order->revisions_used);

        return ['order' => $order, 'proof' => $this->proof, 'name' => $this->name, 'jobUrl' => $this->jobUrl, 'revisionsLeft' => $left, 'title' => $order->displayName()];
    }
}
