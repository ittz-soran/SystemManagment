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
 * walks, each with the answer given for it. `user_id` started it — or sent
 * it, when `assigned_to` names the one person who counts it.
 */
#[Fillable(['document_no', 'scope', 'category_id', 'room_id', 'with_empty', 'blind', 'started_at', 'finished_at', 'user_id', 'assigned_to'])]
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

    /** The one person it was sent to, when it was sent to someone. */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function isSent(): bool
    {
        return ($this->attributes['assigned_to'] ?? null) !== null;
    }

    public function isSentTo(User $user): bool
    {
        return $this->isSent() && (int) $this->attributes['assigned_to'] === (int) $user->getKey();
    }

    /**
     * Its page and report: whoever may see stock checks, and the person it was
     * sent to — who needs only `stock_checks.count`.
     */
    public function visibleTo(User $user): bool
    {
        return $user->hasPermission('stock_checks.view') || $this->isSentTo($user);
    }

    /**
     * ⚠️ Who may answer in it. Sent to someone: that person only — not the
     * sender, not the admin, whose count would read as theirs. Sent to nobody:
     * anyone who may see and count, as before.
     */
    public function answerableBy(User $user): bool
    {
        if ($this->isSent()) {
            return $this->isSentTo($user) && $user->hasPermission('stock_checks.count');
        }

        return $user->hasPermission('stock_checks.view') && $user->hasPermission('stock_checks.count');
    }

    /** Whoever may answer, and for a round sent to someone, its sender and anyone who may send. */
    public function finishableBy(User $user): bool
    {
        return $this->answerableBy($user)
            || ($this->isSent() && ((int) $this->user_id === (int) $user->getKey() || $user->hasPermission('stock_checks.assign')));
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
