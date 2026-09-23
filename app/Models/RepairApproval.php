<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "yes" from the customer.
 *
 * ⚠️ There is usually more than one. See the migration for the PS4 that was
 * agreed at 8,000 across the counter and again at 43,000 over the telephone.
 */
#[Fillable(['total', 'channel', 'note', 'approved_at'])]
class RepairApproval extends Model
{
    /** Agreed face to face, with the device on the counter. */
    public const CHANNEL_COUNTER = 'counter';

    /**
     * Agreed on the telephone, mid-repair.
     *
     * ⚠️ The one that matters in an argument: the customer is at home holding a
     * ticket with the old figure on it, and this row is the shop's record that
     * they were told before the work went ahead.
     */
    public const CHANNEL_PHONE = 'phone';

    public const CHANNELS = [self::CHANNEL_COUNTER, self::CHANNEL_PHONE];

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function repair(): BelongsTo
    {
        return $this->belongsTo(Repair::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
