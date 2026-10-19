<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin-entered credentials for one payment gateway driver. Credentials are encrypted at rest and
 * override the env defaults in config/epfd.php (see App\Domain\Payments\PaymentGatewayManager).
 */
class PaymentGatewayConfig extends Model
{
    protected $table = 'payment_gateways';

    protected $primaryKey = 'driver';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array'];
    }
}
