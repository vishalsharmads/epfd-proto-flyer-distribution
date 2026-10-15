<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasGeometry;
use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    use HasGeometry;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_primary_with_units' => 'boolean', 'is_commercial' => 'boolean'];
    }
}
