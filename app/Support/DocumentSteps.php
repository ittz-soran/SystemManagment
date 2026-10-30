<?php

namespace App\Support;

use App\Models\Assembly;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Repair;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockAdjustment;
use App\Models\StockCheck;
use App\Models\StockTransfer;
use App\Models\Swap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The documents either side of this one — PROJECT_DOC Section 9b, Document
 * detail page. Soran, 2026-10-30: *"i am in INV-00110 and i want go to after
 * this or before this, if tab next go INV-00111 if have and back to
 * INV-00109"*.
 *
 * ⚠️ By number, not by row or by date. The numbers are zero-padded to five and
 * run past it (Section 7b), so they are ordered by length and then by text:
 * text alone puts INV-100000 before INV-99999.
 *
 * A deleted document keeps its number but its page does not open, so it is
 * stepped over — the model's own query leaves trashed rows out.
 */
final class DocumentSteps
{
    /** Each numbered document and the page it opens on. */
    private const PAGES = [
        Sale::class => 'sales.show',
        Purchase::class => 'purchases.show',
        SaleReturn::class => 'sale-returns.show',
        PurchaseReturn::class => 'purchase-returns.show',
        Payment::class => 'payments.show',
        Expense::class => 'expenses.show',
        StockAdjustment::class => 'stock-adjustments.show',
        StockTransfer::class => 'stock-transfers.show',
        Repair::class => 'repairs.show',
        Swap::class => 'swaps.show',
        Assembly::class => 'assemblies.show',
        StockCheck::class => 'stock-checks.show',
    ];

    /**
     * @return array{previous: ?array{number: string, url: string}, next: ?array{number: string, url: string}}
     */
    public static function around(Model $document): array
    {
        $route = self::PAGES[$document::class] ?? null;
        $number = (string) ($document->getAttributes()['document_no'] ?? '');

        if ($route === null || $number === '') {
            return ['previous' => null, 'next' => null];
        }

        $length = strlen($number);

        // The same kind of number only, should a table ever hold another.
        $same = fn (): Builder => $document->newQuery()
            ->whereKeyNot($document->getKey())
            ->where('document_no', 'like', Str::before($number, '-').'-%');

        $next = $same()
            ->where(fn (Builder $q) => $q
                ->whereRaw('LENGTH(document_no) > ?', [$length])
                ->orWhere(fn (Builder $q) => $q->whereRaw('LENGTH(document_no) = ?', [$length])->where('document_no', '>', $number)))
            ->orderByRaw('LENGTH(document_no)')
            ->orderBy('document_no')
            ->first(['id', 'document_no']);

        $previous = $same()
            ->where(fn (Builder $q) => $q
                ->whereRaw('LENGTH(document_no) < ?', [$length])
                ->orWhere(fn (Builder $q) => $q->whereRaw('LENGTH(document_no) = ?', [$length])->where('document_no', '<', $number)))
            ->orderByRaw('LENGTH(document_no) DESC')
            ->orderByDesc('document_no')
            ->first(['id', 'document_no']);

        $step = fn (?Model $to) => $to === null ? null : [
            'number' => (string) $to->getAttributes()['document_no'],
            'url' => route($route, $to),
        ];

        return ['previous' => $step($previous), 'next' => $step($next)];
    }
}
