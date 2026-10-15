<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class PrintOrder extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUSES = ['draft', 'submitted', 'awaiting_artwork_approval', 'in_printing', 'shipped', 'completed', 'cancelled'];

    public const TIMELINE = ['submitted', 'awaiting_artwork_approval', 'in_printing', 'shipped', 'completed'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'pricing' => 'array', 'earliest_delivery' => 'date', 'also_distribute' => 'boolean', 'right_to_print' => 'boolean',
            'submitted_at' => 'datetime', 'paid_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(PrintProduct::class, 'print_product_id');
    }

    public function artworks(): HasMany
    {
        return $this->hasMany(Artwork::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function invoice(): MorphOne
    {
        return $this->morphOne(Invoice::class, 'invoiceable');
    }

    public function thread(): MorphOne
    {
        return $this->morphOne(SupportThread::class, 'related');
    }
}
