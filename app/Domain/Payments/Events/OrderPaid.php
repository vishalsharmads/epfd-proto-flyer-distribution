<?php

declare(strict_types=1);

namespace App\Domain\Payments\Events;

use App\Models\Campaign;
use App\Models\DesignOrder;
use App\Models\Payment;
use App\Models\PrintOrder;
use Illuminate\Foundation\Events\Dispatchable;

final class OrderPaid
{
    use Dispatchable;

    public function __construct(public readonly Campaign|PrintOrder|DesignOrder $payable, public readonly Payment $payment) {}
}
