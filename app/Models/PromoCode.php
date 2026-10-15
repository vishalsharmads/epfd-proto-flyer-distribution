<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromoCode extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function isUsableFor(string $service, int $subtotalCents): bool
    {
        return $this->is_active
            && ($this->service === 'any' || $this->service === $service)
            && (! $this->starts_at || $this->starts_at->isPast())
            && (! $this->ends_at || $this->ends_at->isFuture())
            && ($this->max_uses === null || $this->uses < $this->max_uses)
            && $subtotalCents >= $this->min_subtotal_cents;
    }
}
