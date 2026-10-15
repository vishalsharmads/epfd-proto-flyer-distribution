<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GnafRelease extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'states' => 'array', 'row_counts' => 'array', 'skipped_counts' => 'array', 'kind_counts' => 'array',
            'discovery' => 'array', 'is_sample' => 'boolean', 'started_at' => 'datetime', 'imported_at' => 'datetime',
        ];
    }

    public static function current(): ?self
    {
        return static::where('status', 'completed')->latest('imported_at')->first();
    }
}
