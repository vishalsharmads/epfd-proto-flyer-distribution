<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintOption extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(PrintProduct::class, 'print_product_id');
    }
}
