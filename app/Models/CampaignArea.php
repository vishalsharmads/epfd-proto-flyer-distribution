<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasGeometry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignArea extends Model
{
    use HasFactory, HasGeometry;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['distance_km' => 'float'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
