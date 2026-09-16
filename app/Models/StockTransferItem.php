<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One product on a transfer, and how many of it moved. */
#[Fillable(['stock_transfer_id', 'product_id', 'quantity', 'sequence'])]
class StockTransferItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'sequence' => 'integer',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
