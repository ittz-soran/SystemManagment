<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * One thing taken apart, or several put together — Soran, 2026-09-24.
 *
 * ⚠️ **The money does not move.** What comes out is worth exactly what went
 * in; the shop has neither earned nor lost anything by opening a box. That is
 * the whole invariant, and `AssemblyService` refuses any document that breaks
 * it.
 */
#[Fillable(['direction', 'note', 'assembled_at'])]
class Assembly extends Model
{
    use SoftDeletes;

    /** One thing in, several out: a PS5 bundle becomes a console and two pads. */
    public const APART = 'apart';

    /** Several in, one out: a pile of parts becomes a gaming PC. */
    public const TOGETHER = 'together';

    public const DIRECTIONS = [self::APART, self::TOGETHER];

    protected function casts(): array
    {
        return [
            'total_cost' => 'integer',
            'assembled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(AssemblyItem::class)->orderBy('sequence')->orderBy('id');
    }

    /** What went in. One line when taking apart, several when putting together. */
    public function sources(): HasMany
    {
        return $this->items()->where('role', AssemblyItem::SOURCE);
    }

    /** And what came out, the other way round. */
    public function results(): HasMany
    {
        return $this->items()->where('role', AssemblyItem::RESULT);
    }

    /**
     * The same two sides, read off the lines already in hand.
     *
     * ⚠️ Not `$this->sources`, which is a relation of its own and loads a
     * second query the moment it is touched — under `preventLazyLoading` that
     * is a 500 on a page whose controller eager-loaded `items.product` and had
     * every right to think it was done. `loadMissing` is explicit, so it is
     * allowed, and does nothing at all when the controller has already loaded.
     *
     * @return Collection<int, AssemblyItem>
     */
    public function sourceLines()
    {
        return $this->side(AssemblyItem::SOURCE);
    }

    /** @return Collection<int, AssemblyItem> */
    public function resultLines()
    {
        return $this->side(AssemblyItem::RESULT);
    }

    /** @return Collection<int, AssemblyItem> */
    private function side(string $role)
    {
        $this->loadMissing('items.product');

        return $this->items->where('role', $role)->values();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isApart(): bool
    {
        return $this->direction === self::APART;
    }

    /** The single line on whichever side has only one. */
    public function whole(): ?AssemblyItem
    {
        return ($this->isApart() ? $this->sourceLines() : $this->resultLines())->first();
    }

    /** And the several on the other side. @return \Illuminate\Support\Collection<int, AssemblyItem> */
    public function pieces()
    {
        return $this->isApart() ? $this->resultLines() : $this->sourceLines();
    }
}
