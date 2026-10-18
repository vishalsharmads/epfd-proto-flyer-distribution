<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class PrintProduct extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['gsm_options' => 'array', 'sides_options' => 'array', 'finish_options' => 'array', 'quantity_options' => 'array', 'distributable' => 'boolean', 'is_active' => 'boolean'];
    }

    public function options(): HasMany
    {
        return $this->hasMany(PrintOption::class);
    }

    public function printCategory(): BelongsTo
    {
        return $this->belongsTo(PrintCategory::class, 'category', 'slug');
    }

    /** Active products in active categories, in category order then product order, with their category loaded. */
    public function scopeForSale(Builder $query): void
    {
        $query->with('printCategory')->where('print_products.is_active', true)
            ->whereHas('printCategory', fn (Builder $c) => $c->where('is_active', true))
            ->orderBy(PrintCategory::select('sort')->whereColumn('print_categories.slug', 'print_products.category'))
            ->orderBy('print_products.sort')->orderBy('print_products.name');
    }

    /** @return list<int> */
    public function sides(): array
    {
        return array_values(array_map('intval', $this->sides_options ?: [1, 2]));
    }

    /** @return list<string> finishes offered for a paper weight */
    public function finishesFor(int $gsm): array
    {
        return array_values((array) ($this->finish_options[(string) $gsm] ?? []));
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}
