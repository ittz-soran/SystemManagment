<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One device that has agreed to be buzzed — Soran, 2026-09-17.
 *
 * ⚠️ A DEVICE, not a person. A shopkeeper with a phone and a counter PC has two
 * of these, and each expires on its own schedule when the browser decides to
 * rotate it.
 */
#[Fillable(['user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'device', 'last_sent_at'])]
class PushSubscription extends Model
{
    protected function casts(): array
    {
        return ['last_sent_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A device is recognised by its endpoint, which is too long to index whole.
     *
     * Hashed here so there is one place that decides how, and the unique key on
     * the hash is what stops the same phone subscribing twice.
     */
    public static function hashFor(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
