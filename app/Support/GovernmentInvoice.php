<?php

namespace App\Support;

use App\Models\Sale;

/**
 * What the A5 government invoice says — PROJECT_DOC Section 9.
 *
 * One place for the print on the shop's paper and for the invoice designer,
 * which shows the layers filled with the last government sale, so that what is
 * placed in the designer is what prints.
 */
final class GovernmentInvoice
{
    /**
     * The total in words for the total band: in Kurdish after تەنها, as Soran
     * writes it on the book — "تەنها بیست و پێنج هەزار دینار" — whatever the
     * screen is in. The locale is switched for this one call so the words come
     * from the vocabulary the rest of the system uses, and switched back
     * whatever happens, or the rest of the page would follow.
     */
    public static function words(int $amount): string
    {
        $screen = app()->getLocale();
        app()->setLocale('ckb');

        try {
            return 'تەنها '.AmountInWords::for($amount);
        } finally {
            app()->setLocale($screen);
        }
    }

    /**
     * Each layer's text for one sale.
     *
     * @return array{customer: string, date: string, address: ?string, rows: list<array{price: string, qty: string, name: string, amount: string}>, words: string, total: string, number: string}
     */
    public static function fill(Sale $sale): array
    {
        $sale->loadMissing('customer', 'items.product');

        // ONE currency on a receipt — Section 2b. The rate comes off the document.
        $writtenIn = $sale->writtenIn();
        $say = fn (int $base) => $writtenIn ? $sale->asWritten($base) : money($base, false);
        $mark = $writtenIn ? $writtenIn->mark() : Money::base()->mark();

        return [
            'customer' => $sale->soldTo(),
            'date' => $sale->sale_date->format(setting('date_format', 'Y-m-d')),
            'address' => $sale->customer->address,
            'rows' => $sale->items->map(fn ($item) => [
                'price' => $say($item->unit_price),
                'qty' => qty($item->quantity, $item->product->unit),
                'name' => $item->product->name,
                'amount' => $say($item->lineTotal()),
            ])->values()->all(),
            'words' => self::words($sale->total_amount),
            'total' => $say($sale->total_amount).' '.$mark,
            'number' => $sale->document_no,
        ];
    }

    /**
     * What the designer shows before the shop has sold to a government
     * customer: a name in Kurdish, one in English, and one long enough to wrap.
     *
     * @return array<string, mixed>
     */
    public static function sample(): array
    {
        $say = fn (int $amount) => money($amount, false);

        return [
            'customer' => 'بەڕێوەبەرایەتی پۆلیسی پاریزگای هەڵەبجە',
            'date' => now()->format(setting('date_format', 'Y-m-d')),
            'address' => 'هەڵەبجە',
            'rows' => [
                ['price' => $say(250_000), 'qty' => '1 pcs', 'name' => 'HP LaserJet Pro M404dn printer, duplex, network, with a spare toner cartridge', 'amount' => $say(250_000)],
                ['price' => $say(15_000), 'qty' => '2 pcs', 'name' => 'کیبۆردی لۆجیتێک K120', 'amount' => $say(30_000)],
                ['price' => $say(35_000), 'qty' => '1 pcs', 'name' => 'Toner HP 85A', 'amount' => $say(35_000)],
            ],
            'words' => self::words(315_000),
            'total' => $say(315_000).' '.Money::base()->mark(),
            'number' => 'INV-00001',
        ];
    }
}
