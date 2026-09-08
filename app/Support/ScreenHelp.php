<?php

namespace App\Support;

/**
 * Help for the screen somebody is actually on.
 *
 * A guide page is the thing everybody builds and nobody opens: a shop
 * assistant halfway through a sale does not leave the till to read a manual.
 * So the reference lives at /guide, and this is what reaches the person while
 * they are stuck — one button in the topbar, opening the help for this route
 * and no other.
 *
 * Keyed by route name, which is the only honest key. A screen and its route
 * are the same thing, so help attached to a route cannot drift onto the wrong
 * page the way a URL pattern or a controller name eventually does.
 *
 * Six screens to begin with, chosen because they are where people get stuck
 * rather than where the code is complicated. Every string is written out as a
 * literal rather than built up, because `translations:check` tokenises the
 * source: a sentence assembled from variables ships in English while the
 * report still says 100%.
 *
 * The shape of an entry, all of it optional except the title:
 *
 *   title    what this screen is
 *   intro    one sentence on what it is for
 *   steps    the ordered thing to do — a heading and a line each
 *   warning  the mistake people actually make here, said plainly
 *   notes    what a reader would not discover on their own
 */
final class ScreenHelp
{
    /** @return array<string, mixed>|null */
    public static function for(?string $route): ?array
    {
        if ($route === null) {
            return null;
        }

        return self::all()[$route] ?? null;
    }

    public static function has(?string $route): bool
    {
        return self::for($route) !== null;
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'sales.create' => [
                'title' => __('New sale'),
                'intro' => __('Ring up what a customer is buying. Stock goes down as you save, and the invoice is ready to print.'),
                'steps' => [
                    [__('Scan or type'), __('Scan the barcode, or type part of the name and press Enter.')],
                    [__('Tap a number to change it'), __('Quantity or price opens a keypad with big buttons. It does sums too, so 20000 − 1500 then OK applies 18,500.')],
                    [__('Choose the customer'), __('Cash Customer for a walk-in. Pick a name instead to let them pay later.')],
                    [__('Save with F2'), __('Or the button. The invoice opens ready to print.')],
                ],
                'warning' => [
                    __('Can’t find a product, or it says none in stock?'),
                    __('Stock only exists once you have recorded a purchase. A new shop has to buy before it can sell — that is how the system knows what each item cost you.'),
                ],
                'notes' => [
                    __('Hold this cart parks a half-finished sale so you can serve somebody else, and pick it up after.'),
                    __('The same product can be added twice at two prices, which is how you discount one of them.'),
                    __('The Cash Customer must always pay in full. Choose a named customer to sell on credit.'),
                    __('You can edit a sale afterwards, as long as nothing has been returned against it.'),
                ],
            ],

            'purchases.create' => [
                'title' => __('New purchase'),
                'intro' => __('Record what you bought from a supplier. This is what puts stock on the shelf and tells the system what it cost.'),
                'steps' => [
                    [__('Choose the supplier'), __('Add them first if they are not in the list yet.')],
                    [__('Add what you bought'), __('Scan or type each item, then its quantity and what you paid per piece.')],
                    [__('Say what you paid now'), __('Leave it short and the rest becomes what the shop owes this supplier.')],
                    [__('Save with F2'), __('The stock appears immediately.')],
                ],
                'warning' => [
                    __('The price you type here is what the item cost you'),
                    __('Not what you will sell it for. The system remembers this figure for ever and works out your profit from it, so a wrong cost here quietly makes every later profit figure wrong.'),
                ],
                'notes' => [
                    __('A discount on the whole purchase does not change what each item cost. That is deliberate — the cost is what you actually paid per piece.'),
                    __('Buying the same product again at a different price is normal. The system keeps each batch separate and sells the oldest first.'),
                    __('Prices in dollars: type the rate and the USD amount, and it converts once, to whole dinars.'),
                ],
            ],

            'sale-returns.create' => [
                'title' => __('A customer brings something back'),
                'intro' => __('Take back part or all of an invoice. The stock returns to the shelf and the money is worked out for you.'),
                'steps' => [
                    [__('Say how many of each'), __('Only what is still returnable can come back — anything returned before is already counted.')],
                    [__('Check the refund'), __('The total is worked out from what that customer actually paid, including any discount on the original invoice.')],
                    [__('Save'), __('The credit note is ready to print.')],
                ],
                'warning' => [
                    __('A refund clears what they owe first'),
                    __('Anything left over is paid back in cash. A customer’s balance is never allowed to go below zero, so the shop does not end up owing them on paper.'),
                ],
                'notes' => [
                    __('Each returned piece goes back into the exact batch it was sold from, so your costs stay right.'),
                    __('An invoice with everything returned is marked returned rather than deleted. Both documents stay in the history.'),
                    __('A return can be undone if it was a mistake, as long as the units are still on the shelf.'),
                ],
            ],

            'purchase-returns.create' => [
                'title' => __('Send something back to a supplier'),
                'intro' => __('Return goods you bought. The stock leaves the shelf and what you owe the supplier goes down.'),
                'steps' => [
                    [__('Say how many of each'), __('Only what you still hold from that purchase can go back.')],
                    [__('Check the amount'), __('It carries its share of any discount the supplier gave on the original invoice.')],
                    [__('Save'), __('The document is ready to print for the supplier.')],
                ],
                'warning' => [
                    __('You cannot return what you have already sold'),
                    __('The units have to still be on the shelf. If they have gone out to customers, this is a stock adjustment rather than a purchase return.'),
                ],
                'notes' => [
                    __('If you had already paid the supplier, the money becomes credit with them rather than cash back.'),
                    __('Undoing one puts the units back into the batch they came from, which nobody else can have touched.'),
                ],
            ],

            'stock-adjustments.index' => [
                'title' => __('Stock adjustments'),
                'intro' => __('Make the number on the screen match what is actually on the shelf. This is the only way to correct stock on a document that is already locked.'),
                'steps' => [
                    [__('Count what is really there'), __('Then find the product here.')],
                    [__('Out, or in'), __('Out for breakages, theft or a miscount. In for something found again, or stock you had before you started using the system.')],
                    [__('Say why'), __('One short reason. In a month it is the only thing that will explain the difference.')],
                ],
                'warning' => [
                    __('Recheck stock does not change anything'),
                    __('It recounts the shelf from the movements already recorded and reports what it finds. Use it when a figure looks wrong; use an adjustment when you know the shelf is right and the screen is not.'),
                ],
                'notes' => [
                    __('Stock going out is costed from the oldest batch first, exactly like a sale.'),
                    __('Stock coming in needs a cost. Leave it and the product’s own purchase price is used.'),
                    __('An adjustment can be corrected or undone, and both are recorded against your name.'),
                ],
            ],

            'reports.index' => [
                'title' => __('Reports'),
                'intro' => __('What the shop sold, bought and made, between any two dates.'),
                'steps' => [
                    [__('Choose the dates'), __('Everything on the page follows them.')],
                    [__('Read profit from the top down'), __('Revenue is what you sold less what came back. Take off what those items cost you, then discounts and write-offs.')],
                    [__('Export if you need it elsewhere'), __('A spreadsheet of the same figures, for an accountant or your own records.')],
                ],
                'warning' => [
                    __('Profit uses what each item actually cost you'),
                    __('Not the purchase price on the product. Two of the same item bought at different prices are counted at what each one really cost, oldest sold first — so the figure matches the money.'),
                ],
                'notes' => [
                    __('Stock value is what your shelf cost you, not what it would sell for.'),
                    __('A sale made today and returned tomorrow leaves both in the history, and the profit nets to zero.'),
                    __('If a figure looks wrong, Settings has a Data check that asks seventeen questions of the shop’s own records.'),
                ],
            ],
        ];
    }
}
