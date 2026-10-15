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

/** Design Studio order: a paid graphic-design job (brief → proofs → approval → final files → optional print). */
class DesignOrder extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPES = ['flyer', 'brochure', 'business_card', 'presentation_folder', 'stationery'];

    public const PACKAGES = ['basic', 'standard', 'premium'];

    public const STATUSES = ['draft', 'submitted', 'in_design', 'proof_ready', 'changes_requested', 'approved', 'completed', 'cancelled'];

    /** Happy-path timeline shown to the business ("changes_requested" is a loop back to in_design). */
    public const TIMELINE = ['submitted', 'in_design', 'proof_ready', 'approved', 'completed'];

    /** "Unlimited" revisions are stored as this number. */
    public const UNLIMITED_REVISIONS = 99;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'pricing' => 'array', 'due_date' => 'date', 'sides' => 'integer',
            'revisions_included' => 'integer', 'revisions_used' => 'integer',
            'submitted_at' => 'datetime', 'paid_at' => 'datetime', 'approved_at' => 'datetime', 'completed_at' => 'datetime',
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

    public function designer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'designer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(PrintProduct::class, 'print_product_id');
    }

    public function printOrder(): BelongsTo
    {
        return $this->belongsTo(PrintOrder::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(DesignAsset::class)->orderBy('kind')->orderBy('version')->orderBy('id');
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

    public function latestProofVersion(): int
    {
        return (int) $this->assets()->where('kind', 'proof')->max('version');
    }

    /** Revisions requested beyond the package allowance (each billed as an extra revision). */
    public function extraRevisions(): int
    {
        return $this->revisions_included >= self::UNLIMITED_REVISIONS ? 0 : max(0, $this->revisions_used - $this->revisions_included);
    }

    public function typeLabel(): string
    {
        return match ($this->design_type) {
            'business_card' => 'Business card',
            'presentation_folder' => 'Presentation folder',
            'stationery' => 'Stationery set',
            default => ucfirst((string) $this->design_type),
        };
    }

    public function displayName(): string
    {
        return $this->typeLabel().' design · '.ucfirst((string) $this->package);
    }
}
