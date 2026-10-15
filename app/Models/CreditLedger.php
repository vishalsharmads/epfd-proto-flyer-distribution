<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CreditLedger extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'credit_ledger';

    protected $guarded = ['id'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
