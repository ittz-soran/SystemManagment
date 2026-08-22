<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * One place that knows how to turn a polymorphic reference into something a
 * reader can click.
 *
 * Three tables store a document as a type/id pair rather than a foreign key —
 * stock_batches.source, stock_movements.reference and
 * account_transactions.reference — and every screen that shows one was printing
 * the raw pair ("Sale #12"), which is a dead end: the reader has to type the id
 * into the URL bar to see what actually happened.
 *
 * The rules live here rather than in the views because all three tables share
 * them, and because two of them matter:
 *
 *   - Not every document has a page. Payments, expenses and adjustments have no
 *     detail screen yet, so their number is shown as text, not offered as a
 *     link into a 404.
 *   - A link the reader cannot follow is worse than no link. Every URL is
 *     checked against the permission its route is guarded by, so a user without
 *     sales.view reads "Sale · INV-00012" as plain text instead of walking into
 *     a 403.
 */
final class DocumentLink
{
    /**
     * Morph alias => [route name, the permission that route is guarded by].
     *
     * An alias absent from this list has no page of its own; its number is
     * still worth showing, so it degrades to text rather than disappearing.
     * Nothing is absent today, but a new kind of document will be, and should
     * appear as text on every screen the day it is added rather than 404.
     */
    private const PAGES = [
        'sale' => ['sales.show', 'sales.view'],
        'purchase' => ['purchases.show', 'purchases.view'],
        'sale_return' => ['sale-returns.show', 'sale_returns.view'],
        'purchase_return' => ['purchase-returns.show', 'purchase_returns.view'],
        'customer' => ['customers.show', 'customers.view'],
        'supplier' => ['suppliers.show', 'suppliers.view'],
        'product' => ['products.show', 'products.view'],
        'payment' => ['payments.show', 'payments.view'],
        'expense' => ['expenses.show', 'expenses.view'],
        'adjustment' => ['stock-adjustments.show', 'stock_adjustments.view'],
    ];

    /**
     * The word for a reference type, in the reader's language.
     *
     * Str::headline() would produce "Sale Return" without a translation, and
     * these words appear on the FIFO trail and the ledger, which are exactly
     * the screens a shopkeeper reads in their own language.
     */
    public static function kind(?string $type): string
    {
        return match ($type) {
            'sale' => __('Sale'),
            'purchase' => __('Purchase'),
            'sale_return' => __('Sale return'),
            'purchase_return' => __('Purchase return'),
            'adjustment' => __('Adjustment'),
            'payment' => __('Payment'),
            'expense' => __('Expense'),
            'customer' => __('Customer'),
            'supplier' => __('Supplier'),
            'product' => __('Product'),
            'opening_balance' => __('Opening balance'),
            default => __('Document'),
        };
    }

    /**
     * The URL of the document's own page, or null when there is none to offer:
     * the document was deleted, its type has no detail screen, or this reader
     * lacks the permission that screen is guarded by.
     */
    public static function url(?Model $document, ?User $viewer): ?string
    {
        if ($document === null) {
            return null;
        }

        // Read the alias out of the map rather than asking the model for it:
        // getMorphClass() throws for a class the enforced map does not name, and
        // a reference this class cannot place must degrade to text, not to a
        // 500 on the page that happens to show it.
        $alias = array_search($document::class, Relation::morphMap(), true);

        [$route, $permission] = self::PAGES[$alias] ?? [null, null];

        if ($route === null || ! $viewer?->hasPermission($permission)) {
            return null;
        }

        return route($route, $document);
    }

    /**
     * What to print for the reference.
     *
     * A document number when the record is there to supply one, and the type
     * and id when it is not — a reference whose document has been deleted still
     * says something, and blanking it would hide the fact that the row exists.
     */
    public static function label(?Model $document, ?string $type, int|string|null $id): string
    {
        if ($document === null) {
            return $id === null ? '—' : '#'.$id;
        }

        // Read the raw attributes rather than the accessors: a Customer has no
        // document_no column at all, and preventAccessingMissingAttributes
        // turns asking for one into an exception rather than a null.
        $attributes = $document->getAttributes();

        if (filled($attributes['document_no'] ?? null)) {
            return (string) $attributes['document_no'];
        }

        if (method_exists($document, 'displayName')) {
            return $document->displayName();
        }

        return filled($attributes['name'] ?? null)
            ? (string) $attributes['name']
            : '#'.$document->getKey();
    }
}
