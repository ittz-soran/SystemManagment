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
     * The parts of one sale the layers are written from, before the shop's
     * invoice options — the figures in 123, the unit as typed, the currency
     * apart from the total. present() writes the layers from these; the
     * designer does the same in the browser as the options change.
     *
     * @return array{customer: string, date: string, address: ?string, rows: list<array{price: string, qty: string, unit: string, name: string, amount: string}>, words: string, total: string, mark: string, number: string}
     */
    public static function fill(Sale $sale): array
    {
        $sale->loadMissing('customer', 'items.product');

        // ONE currency on a receipt — Section 2b. The rate comes off the document.
        $writtenIn = $sale->writtenIn();
        $say = fn (int $base) => $writtenIn ? $sale->asWritten($base) : money($base, false);

        return [
            'customer' => $sale->soldTo(),
            'date' => $sale->sale_date->format(setting('date_format', 'Y-m-d')),
            'address' => $sale->customer->address,
            'rows' => $sale->items->map(fn ($item) => [
                'price' => $say($item->unit_price),
                // As qty() writes it, the unit apart.
                'qty' => number_format((float) $item->quantity),
                'unit' => trim((string) $item->product->unit),
                'name' => $item->product->name,
                'amount' => $say($item->lineTotal()),
            ])->values()->all(),
            'words' => self::words($sale->total_amount),
            'total' => $say($sale->total_amount),
            'mark' => $writtenIn ? $writtenIn->mark() : Money::base()->mark(),
            'number' => $sale->document_no,
        ];
    }

    /**
     * Each layer's text, the shop's invoice options applied — PROJECT_DOC
     * Section 9. ⚠️ resources/js/invoice/layers.js present() is this again for
     * the designer's sample; a change here is a change there, and the browser
     * check holds the two to the same words.
     *
     * @param  array<string, mixed>  $fill  from fill() or sample()
     * @param  array<string, mixed>  $options  InvoiceOptions::current()
     * @return array<string, mixed>
     */
    public static function present(array $fill, array $options): array
    {
        $figure = fn (string $value) => Digits::in($value, $options['digits']);
        $orDash = fn (?string $value) => trim((string) $value) === ''
            ? ($options['empty'] === 'dash' ? '-' : '')
            : (string) $value;

        $number = $options['number'] === 'number' && preg_match('/(\d+)$/', $fill['number'], $m)
            ? $m[1]
            : $fill['number'];

        return [
            'customer' => $orDash($fill['customer']),
            'date' => $orDash($figure($fill['date'])),
            'address' => $orDash($fill['address']),
            'rows' => array_map(function (array $row) use ($figure, $options) {
                $word = $options['units'][$row['unit']] ?? $row['unit'];

                return [
                    'price' => $figure($row['price']),
                    'qty' => $figure($row['qty']).($options['unit'] && $word !== '' ? ' '.$word : ''),
                    'name' => $row['name'],
                    'amount' => $figure($row['amount']),
                ];
            }, $fill['rows']),
            'words' => $orDash($fill['words']),
            'total' => $figure($fill['total']).($options['currency'] ? ' '.$fill['mark'] : ''),
            'number' => $orDash($figure($number)),
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
                ['price' => $say(250_000), 'qty' => '1', 'unit' => Units::ALWAYS, 'name' => 'HP LaserJet Pro M404dn printer, duplex, network, with a spare toner cartridge', 'amount' => $say(250_000)],
                ['price' => $say(15_000), 'qty' => '2', 'unit' => Units::ALWAYS, 'name' => 'کیبۆردی لۆجیتێک K120', 'amount' => $say(30_000)],
                ['price' => $say(35_000), 'qty' => '1', 'unit' => Units::ALWAYS, 'name' => 'Toner HP 85A', 'amount' => $say(35_000)],
            ],
            'words' => self::words(315_000),
            'total' => $say(315_000),
            'mark' => Money::base()->mark(),
            'number' => 'INV-00001',
        ];
    }
}
