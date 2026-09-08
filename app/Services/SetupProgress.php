<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Supplier;

/**
 * How far a brand-new shop has got, and what it should do next.
 *
 * Every shop this is sold to starts empty, and the first hour is where they are
 * lost. Not because the screens are hard, but because of one thing the system
 * never says out loud: **stock only exists after a purchase is recorded**. A
 * shopkeeper adds a product, goes to sell it, is told there is none, and
 * concludes the system is broken. It is not — it simply has no batch to take
 * the item from, and therefore no idea what the item cost, which is the whole
 * of Section 5.
 *
 * So the order below is the teaching, not decoration. Each step is a thing the
 * next step needs:
 *
 *   1. The shop's own name and phone, or every invoice printed from here is
 *      anonymous — and the first invoice is usually printed before anybody
 *      thinks to look at Settings.
 *   2. A category, then a product. A product must belong to a category, so a
 *      shop with no categories cannot add one at all.
 *   3. A supplier, then a purchase from them. Same shape: the purchase form
 *      needs somebody to have bought from.
 *   4. A sale, which is now possible because there is stock to sell.
 *   5. The invoice, printed — the thing the shopkeeper actually hands over,
 *      and the proof that steps 1 to 4 produced something real.
 *
 * Read from the shop's own data rather than remembered. A checklist that keeps
 * its own separate record of what has been done is a checklist that eventually
 * disagrees with the shop, and the shop is right.
 */
class SetupProgress
{
    /** Set once, when a sale's printable invoice is first opened. */
    public const PRINTED = 'setup_invoice_printed_at';

    /** Set when the shopkeeper puts the card away. */
    public const HIDDEN = 'setup_checklist_hidden';

    /**
     * @return list<array{
     *     key: string, title: string, note: string,
     *     done: bool, route: string|null, action: string
     * }>
     */
    public function steps(): array
    {
        return [
            [
                'key' => 'shop',
                'title' => __('Put your shop’s name and phone on invoices'),
                'note' => __('Everything you print carries these. Set them once.'),
                'done' => $this->shopIsNamed(),
                'route' => 'settings.edit',
                'action' => __('Settings'),
            ],
            [
                'key' => 'product',
                'title' => __('Add your first product, in a category'),
                'note' => __('A product belongs to a category, so make a category first — "Cables", "Phones".'),
                'done' => Category::query()->exists() && Product::query()->exists(),
                'route' => 'products.create',
                'action' => __('Add a product'),
            ],
            [
                'key' => 'purchase',
                'title' => __('Record what you bought, from a supplier'),
                'note' => __('Add the supplier first — the purchase form asks who you bought from. This is what puts stock on the shelf.'),
                'done' => Supplier::query()->exists() && Purchase::query()->exists(),
                'route' => 'purchases.create',
                'action' => __('New purchase'),
            ],
            [
                'key' => 'sale',
                'title' => __('Make your first sale'),
                'note' => __('Now there is stock to sell. Scan or type the product, choose the customer, save.'),
                'done' => Sale::query()->exists(),
                'route' => 'sales.create',
                'action' => __('New sale'),
            ],
            [
                'key' => 'print',
                'title' => __('Print the invoice'),
                'note' => __('Open the sale you just made and print it. This is what the customer takes away.'),
                'done' => filled(setting(self::PRINTED)),
                'route' => 'sales.index',
                'action' => __('Sales history'),
            ],
        ];
    }

    /** Done, out of five. */
    public function doneCount(): int
    {
        return count(array_filter($this->steps(), fn (array $step) => $step['done']));
    }

    public function isComplete(): bool
    {
        return $this->doneCount() === count($this->steps());
    }

    /**
     * Whether the card belongs on the dashboard at all.
     *
     * Gone once finished, and gone once put away. Never shown to a shop that
     * has been trading for months — a card telling a working shop to make its
     * first sale is the system not paying attention.
     */
    public function shouldShow(): bool
    {
        return ! $this->isComplete() && ! filled(setting(self::HIDDEN));
    }

    /** The step to do next, which is the first one not done. */
    public function next(): ?array
    {
        foreach ($this->steps() as $step) {
            if (! $step['done']) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The shop has said who it is.
     *
     * A name alone is not enough: every install is seeded with one, so the
     * question is whether anybody has been back to Settings since. A phone
     * number is the honest signal — nothing seeds it, and an invoice without
     * one gives the customer no way to ring about it.
     */
    private function shopIsNamed(): bool
    {
        return filled(setting('shop_phone'));
    }

    /**
     * Remember that an invoice has been printed.
     *
     * Called from the printable view, which is a GET — so it writes at most
     * once, ever, and only when there is a sale for it to be about. Detecting
     * this any other way would mean either a print log nobody asked for, or a
     * checklist that never finishes.
     */
    public function recordPrinted(): void
    {
        if (filled(setting(self::PRINTED))) {
            return;
        }

        Setting::put(self::PRINTED, now()->toDateTimeString());
    }

    public function hide(): void
    {
        Setting::put(self::HIDDEN, now()->toDateTimeString());
    }
}
