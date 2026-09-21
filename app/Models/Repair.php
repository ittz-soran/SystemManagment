<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One phone, in broken and out mended — Soran, 2026-09-20.
 *
 * ⚠️ **A job holds its parts; it does not own any stock.** Nothing here moves a
 * unit or a dinar. Collecting the job makes an ordinary `Sale` from these lines,
 * and that sale is what consumes FIFO and takes the money — see the migration
 * for why a second stock path was refused.
 */
#[Fillable([
    'customer_id', 'device', 'identifier', 'fault', 'condition_note',
    'received_at', 'promised_for', 'estimate', 'status', 'note', 'technician_id',
])]
class Repair extends Model
{
    use SoftDeletes;

    /** Waiting to be looked at. */
    public const STATUS_RECEIVED = 'received';

    /**
     * Parts chosen and priced, waiting for the customer to say yes.
     *
     * ⚠️ A real state, not a formality — Soran: *"this part shop decided which
     * needed → after customer accept about parts and cost"*. Nothing is
     * promised and no ticket exists until they accept.
     */
    public const STATUS_QUOTED = 'quoted';

    /** Accepted, ticket printed, somebody is working on it. */
    public const STATUS_WORKING = 'working';

    /** Mended, waiting on the shelf for its owner. */
    public const STATUS_READY = 'ready';

    /** Gone home, and paid for. */
    public const STATUS_COLLECTED = 'collected';

    /**
     * Handed back unmended.
     *
     * A real outcome rather than a failure to record one: a phone that cannot
     * be saved, or whose owner would not pay what it would cost, still leaves
     * the shop and still has to stop appearing on the bench.
     */
    public const STATUS_RETURNED = 'returned';

    /** The statuses a job may be in, in the order it moves through them. */
    public const STATUSES = [
        self::STATUS_RECEIVED,
        self::STATUS_QUOTED,
        self::STATUS_WORKING,
        self::STATUS_READY,
        self::STATUS_COLLECTED,
        self::STATUS_RETURNED,
    ];

    /** The ones still on the bench — what the list opens on. */
    public const OPEN_STATUSES = [
        self::STATUS_RECEIVED,
        self::STATUS_QUOTED,
        self::STATUS_WORKING,
        self::STATUS_READY,
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'accepted_at' => 'datetime',
            'accepted_total' => 'integer',
            'promised_for' => 'date',
            'estimate' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RepairItem::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Still on the bench. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    /** What the customer will be charged, from the lines rather than the estimate. */
    public function total(): int
    {
        return (int) $this->items->sum(fn (RepairItem $item) => $item->quantity * $item->unit_price);
    }

    /**
     * ⚠️ Locked once collected, for the reason Section 8 locks a sale.
     *
     * A collected job owns a sale, and that sale has moved stock, charged a
     * cost and taken money. Editing the job behind it would leave the two
     * describing different work.
     */
    public function canBeModified(): bool
    {
        return $this->status !== self::STATUS_COLLECTED;
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * ⚠️ What the customer's printed ticket says, against what it now costs.
     *
     * Soran: *"prices may changeable while customer and person are do this
     * repair both accepted on job"*. The live total is allowed to move; the
     * agreed one is not. Zero when they match, which is most of the time —
     * and the number to show them when they do not.
     */
    public function priceDrift(): int
    {
        return $this->accepted_total === null ? 0 : $this->total() - $this->accepted_total;
    }

    /**
     * The day a line's warranty runs out.
     *
     * ⚠️ Counted from COLLECTION, never from when the work finished: the phone
     * is in the shop until its owner takes it, and a warranty that expired on
     * the bench would be worth nothing.
     */
    public function warrantyEndsOn(RepairItem $item): ?Carbon
    {
        if ($item->warranty_days === null || $this->status !== self::STATUS_COLLECTED) {
            return null;
        }

        return ($this->sale?->sale_date ?? $this->updated_at)->copy()->addDays($item->warranty_days);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** Overdue only means promised and not yet done — a collected job is never late. */
    public function isOverdue(): bool
    {
        return $this->promised_for !== null
            && $this->isOpen()
            && $this->promised_for->isBefore(now()->startOfDay());
    }
}
