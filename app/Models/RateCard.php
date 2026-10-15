<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RateCard extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'is_active' => 'boolean'];
    }

    public static function active(): self
    {
        return static::where('is_active', true)->latest('id')->firstOrFail();
    }
}
