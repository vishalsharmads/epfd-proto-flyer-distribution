<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A file on a design order: the customer's brief uploads, a versioned proof, or a final deliverable. */
class DesignAsset extends Model
{
    use HasFactory;

    public const KINDS = ['brief_upload', 'proof', 'final'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'size_bytes' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }

    public function designOrder(): BelongsTo
    {
        return $this->belongsTo(DesignOrder::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return in_array($this->mime, ['image/png', 'image/jpeg'], true);
    }

    /** Can this file become print artwork (Artwork accepts PDF/PNG/JPG)? */
    public function isPrintable(): bool
    {
        return in_array($this->mime, ['application/pdf', 'image/png', 'image/jpeg'], true);
    }
}
