<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Somebody who mends things for the shop — Soran, 2026-09-21.
 *
 * ⚠️ **Not a user.** *"some times have some person are repairing with name and
 * phone"*: a man who fixes boards for the shop on Thursdays is not a member of
 * staff, has no reason to log in, and must not need an account before his name
 * can go on a ticket. Name and phone are the whole of it.
 */
#[Fillable(['name', 'phone', 'note', 'is_active'])]
class Technician extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(Repair::class);
    }

    /** ⚠️ Deactivated rather than deleted: old tickets still carry their name. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
