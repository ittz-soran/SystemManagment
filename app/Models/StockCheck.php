<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One pass over the shelves — PROJECT_DOC Section 9, Stock check. Soran,
 * 2026-10-30: *"stock check manually … Confirm … Skip … Wrong"*.
 *
 * Numbered CHK-00001 like every document. Its lines are the products it
 * walks, each with the answer given for it.
 */
#[Fillable(['document_no', 'scope', 'category_id', 'room_id', 'with_empty', 'blind', 'started_at', 'finished_at', 'user_id'])]
class StockCheck extends Model
{
    public const SCOPE_ALL = 'all';

    public const SCOPE_CATEGORY = 'category';

    public const SCOPE_ROOM = 'room';

    protected function casts(): array
    {
        return [
            'with_empty' => 'boolean',
            'blind' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCheckLine::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(StockRoom::class, 'room_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinished(): bool
    {
        return ($this->attributes['finished_at'] ?? null) !== null;
    }

    /** What the round counts, in the reader's language. */
    public function scopeName(): string
    {
        return match ($this->scope) {
            self::SCOPE_CATEGORY => $this->category?->name ?? __('A category since deleted'),
            self::SCOPE_ROOM => $this->room?->name ?? __('A room since deleted'),
            default => __('Every product'),
        };
    }

    /**
     * How far it has got: every status counted, and the total.
     *
     * @return array{total: int, pending: int, confirmed: int, wrong: int, skipped: int, done: int}
     */
    public function progress(): array
    {
        $counts = $this->lines()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $of = fn (string $status) => (int) ($counts[$status] ?? 0);

        return [
            'total' => (int) $counts->sum(),
            'pending' => $of(StockCheckLine::PENDING),
            'confirmed' => $of(StockCheckLine::CONFIRMED),
            'wrong' => $of(StockCheckLine::WRONG),
            'skipped' => $of(StockCheckLine::SKIPPED),
            'done' => $of(StockCheckLine::CONFIRMED) + $of(StockCheckLine::WRONG),
        ];
    }
}
