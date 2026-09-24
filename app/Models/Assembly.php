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

    /**
     * Whether this can be undone, and what to say when it cannot.
     *
     * ⚠️ **What came out has to still be there.** Undoing puts the pieces back
     * into the batches they were made from and takes them off the shelf, which
     * cannot happen once somebody has sold one. Section 8: computed live so the
     * screen can disable the button and print the reason, and asked again
     * inside the transaction because the answer changes while a page is open.
     *
     * @param  string  $permission  the key being exercised — deleting asks for
     *                              `assemblies.delete`, editing for
     *                              `assemblies.create`, and both undo the same
     *                              document in the same way
     * @return array{allowed: bool, reason: ?string}
     */
    public function canBeDeleted(?User $user = null, string $permission = 'assemblies.delete'): array
    {
        $deny = fn (string $reason) => ['allowed' => false, 'reason' => $reason];

        if (books_closed_on($this->assembled_at)) {
            return $deny(__('Locked: this date is in a closed period.'));
        }

        if ($user && ! $user->hasPermission($permission)) {
            return $deny(__('You do not have permission to change this.'));
        }

        /*
         * Every batch this document created, and whether it still holds what it
         * was given. A piece sold, written off or taken apart again is a piece
         * there is nothing left to take back.
         */
        $needed = StockMovement::where('reference_type', StockMovement::REF_ASSEMBLY)
            ->where('reference_id', $this->id)
            ->where('quantity', '>', 0)
            ->selectRaw('stock_batch_id, SUM(quantity) as needed')
            ->groupBy('stock_batch_id')
            ->pluck('needed', 'stock_batch_id');

        $short = 0;

        foreach ($needed as $batchId => $quantity) {
            $remaining = (int) StockBatch::whereKey($batchId)->value('quantity_remaining');
            $short += max(0, (int) $quantity - $remaining);
        }

        if ($short > 0) {
            return $deny(trans_choice(
                '{1}:count piece has since been sold or used, so this can no longer be undone.'
                .'|[2,*]:count pieces have since been sold or used, so this can no longer be undone.',
                $short,
                ['count' => $short],
            ));
        }

        return ['allowed' => true, 'reason' => null];
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
