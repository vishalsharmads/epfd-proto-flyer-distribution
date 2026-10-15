<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuburbStat extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['computed_at' => 'datetime'];
    }

    public function suburb(): BelongsTo
    {
        return $this->belongsTo(Suburb::class);
    }
}
