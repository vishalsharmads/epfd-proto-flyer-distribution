<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedCard extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['gateway_token'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'gateway_token' => 'encrypted'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
