<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrintProduct extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['gsm_options' => 'array', 'finish_options' => 'array', 'quantity_options' => 'array', 'distributable' => 'boolean', 'is_active' => 'boolean'];
    }

    public function options(): HasMany
    {
        return $this->hasMany(PrintOption::class);
    }
}
