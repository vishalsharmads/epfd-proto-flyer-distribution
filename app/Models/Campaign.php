<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasGeometry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use HasFactory, HasGeometry, SoftDeletes;

    /** @var list<string> */
    protected array $geometryColumns = ['pickup_geom'];

    /** Distribution status timeline, in order. */
    public const STATUSES = ['draft', 'submitted', 'runs_published', 'allocated', 'collected', 'in_delivery', 'completed', 'cancelled'];

    public const TIMELINE = ['submitted', 'runs_published', 'allocated', 'collected', 'in_delivery', 'completed'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'include_houses' => 'boolean', 'include_units' => 'boolean', 'available_from' => 'date', 'pricing' => 'array',
            'distance_km' => 'float', 'submitted_at' => 'datetime', 'paid_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function areas(): HasMany
    {
        return $this->hasMany(CampaignArea::class)->orderBy('position');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    public function printOrder(): BelongsTo
    {
        return $this->belongsTo(PrintOrder::class);
    }

    public function pickupPoint(): BelongsTo
    {
        return $this->belongsTo(PickupPoint::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function invoice(): MorphOne
    {
        return $this->morphOne(Invoice::class, 'invoiceable');
    }

    public function thread(): MorphOne
    {
        return $this->morphOne(SupportThread::class, 'related');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
