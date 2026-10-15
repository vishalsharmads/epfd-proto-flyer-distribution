<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasGeometry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Run extends Model
{
    use HasFactory, HasGeometry;

    public const STATUSES = ['draft', 'published', 'allocated', 'collected', 'in_delivery', 'completed', 'failed'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'deadline' => 'date', 'distance_km' => 'float', 'coverage_pct' => 'float', 'published_at' => 'datetime', 'allocated_at' => 'datetime',
            'collected_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(CampaignArea::class, 'campaign_area_id');
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distributor_id');
    }

    public function pickupPoint(): BelongsTo
    {
        return $this->belongsTo(PickupPoint::class);
    }

    public function gpsPoints(): HasMany
    {
        return $this->hasMany(RunGpsPoint::class)->orderBy('recorded_at');
    }
}
