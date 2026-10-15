<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasGeometry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Suburb extends Model
{
    use HasFactory, HasGeometry;

    /** @var list<string> */
    protected array $geometryColumns = ['boundary', 'centroid'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_serviced' => 'boolean'];
    }

    public function stats(): HasOne
    {
        return $this->hasOne(SuburbStat::class);
    }
}
