<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of an assembly: a thing that went in, or a thing that came out. */
#[Fillable(['assembly_id', 'product_id', 'role', 'quantity', 'unit_cost', 'sequence'])]
class AssemblyItem extends Model
{
    /** It went in and was consumed. */
    public const SOURCE = 'source';

    /** It came out and is on the shelf. */
    public const RESULT = 'result';

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'integer',
            'sequence' => 'integer',
        ];
    }

    public function assembly(): BelongsTo
    {
        return $this->belongsTo(Assembly::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lineTotal(): int
    {
        return $this->quantity * $this->unit_cost;
    }
}
