<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasGeometry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedArea extends Model
{
    use HasFactory, HasGeometry;

    protected $guarded = ['id'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
