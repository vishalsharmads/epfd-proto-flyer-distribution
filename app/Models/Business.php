<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasGeometry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    use HasFactory, HasGeometry;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const SUSPENDED = 'suspended';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'gateway_customers' => 'array'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function printOrders(): HasMany
    {
        return $this->hasMany(PrintOrder::class);
    }

    public function designOrders(): HasMany
    {
        return $this->hasMany(DesignOrder::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function savedCards(): HasMany
    {
        return $this->hasMany(SavedCard::class);
    }

    public function savedAreas(): HasMany
    {
        return $this->hasMany(SavedArea::class);
    }

    public function creditEntries(): HasMany
    {
        return $this->hasMany(CreditLedger::class);
    }

    public function supportThreads(): HasMany
    {
        return $this->hasMany(SupportThread::class);
    }

    public function creditBalanceCents(): int
    {
        return (int) ($this->creditEntries()->latest('id')->value('balance_after_cents') ?? 0);
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    public function canPay(): bool
    {
        return $this->isApproved() || ! config('epfd.require_approval_for_payment');
    }
}
