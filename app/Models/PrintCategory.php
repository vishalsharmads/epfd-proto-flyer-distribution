<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Admin-managed print category (Flyers, Brochures, …); products reference it by slug. */
class PrintCategory extends Model
{
    use HasFactory;

    /** Lucide icon names an admin can pick (rendered by print-bits.tsx). */
    public const ICONS = ['file-text', 'book-open', 'magnet', 'credit-card', 'image', 'flag', 'newspaper', 'printer', 'sticky-note', 'tag', 'mail', 'package', 'ticket', 'calendar', 'shirt', 'gift'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(PrintProduct::class, 'category', 'slug');
    }
}
