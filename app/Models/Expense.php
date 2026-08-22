<?php

namespace App\Models;

use App\Models\Concerns\HidesArchivedPeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'document_no', 'title', 'expense_category_id', 'amount',
    'expense_date', 'user_id', 'notes',
])]
class Expense extends Model
{
    use HidesArchivedPeriod, SoftDeletes;

    /** Section 8c: the column an archived period is decided by. */
    public function archivePeriodColumn(): string
    {
        return 'expense_date';
    }

    protected function casts(): array
    {
        return ['amount' => 'integer', 'expense_date' => 'date'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
