<?php

namespace App\Support;

/**
 * The shop's reference, organised by the thing somebody is trying to do.
 *
 * The third and last of the help features, and the one that has to justify
 * itself: a guide page is the thing everybody builds and nobody opens. Two
 * decisions are what make this one worth having.
 *
 * It is arranged by task rather than by menu. A shopkeeper does not think "I
 * need the sale-returns screen"; they think "a customer brought something
 * back". A guide with a chapter per screen answers a question nobody asked, so
 * the titles here are the sentences people actually say.
 *
 * And it is reached rather than searched for. The `?` in the topbar
 * (App\Support\ScreenHelp) is what catches somebody mid-sale; this is where
 * they land when that is not enough, and where a new owner reads for twenty
 * minutes before the shop opens. Both are needed — neither replaces the other.
 *
 * No permission anywhere in it, deliberately: the reader most likely to need
 * the guide is the newest assistant, holding the fewest permissions in the
 * shop. Where a topic can offer a link to the screen it describes, that one
 * link is checked — a link to "access denied" is worse than no link.
 *
 * Every string is a literal inside __(), never assembled from variables:
 * `translations:check` tokenises the source, so a sentence built up at runtime
 * ships in English while the report still says 100%.
 *
 * The shape of a topic:
 *
 *   group       which of the five headings it sits under
 *   icon        Bootstrap Icons name, without the bi- prefix
 *   title       the sentence somebody would say out loud
 *   blurb       one line, so the list can be read without opening anything
 *   minutes     honest reading time, rounded up
 *   route       optional: the screen this is about
 *   permission  what that link needs, or null when anyone may go there
 *   sections    the body — a heading and its paragraphs
 */
final class Guide
{
    /** @return array<string, string> */
    public static function groups(): array
    {
        return [
            'start' => __('Getting started'),
            'selling' => __('Selling'),
            'stock' => __('Stock'),
            'money' => __('Money and reports'),
            'care' => __('Looking after it'),
        ];
    }

    /** The topic every brand-new reader should be sent to first. */
    public const FIRST = 'first-week';

    /** @return array<string, mixed>|null */
    public static function topic(string $slug): ?array
    {
        return self::topics()[$slug] ?? null;
    }

    /**
     * The topics of one group, in the order they are written.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function inGroup(string $group): array
    {
        return array_filter(self::topics(), fn ($topic) => $topic['group'] === $group);
    }

    /**
     * Everything a reader might search for in one topic, as plain text.
     *
     * The search box filters the list in the browser, so it needs the words
     * that are on the topic's own page rather than only the ones on the list.
     * Somebody looking for "barcode" should find the sale topic even though
     * the word appears three screens in.
     */
    public static function searchText(array $topic): string
    {
        $parts = [$topic['title'], $topic['blurb']];

        foreach ($topic['sections'] as [$heading, $paragraphs]) {
            $parts[] = $heading;
            $parts = [...$parts, ...$paragraphs];
        }

        return implode(' ', $parts);
    }

    /**
     * What changed lately, newest first, in the shop's words rather than ours.
     *
     * A release note that says "added SetupProgress service" tells a shopkeeper
     * nothing. Each of these says what is different on their screen and why
     * they would care.
     *
     * @return list<array{date: string, title: string, body: string}>
     */
    public static function whatsNew(): array
    {
        return [
            [
                'date' => '2026-09-08',
                'title' => __('A guide, and help on the screens where people get stuck'),
                'body' => __('The blue ? at the top of the screen explains whatever page you are on, without losing what you were typing. This page is the longer version.'),
            ],
            [
                'date' => '2026-09-08',
                'title' => __('A first-week list on the dashboard'),
                'body' => __('A new shop opens on a screen of zeros. The dashboard now says what to do next, in the order the system needs it, and puts itself away when the list is done.'),
            ],
            [
                'date' => '2026-08-30',
                'title' => __('Getting back in when a password is forgotten'),
                'body' => __('Your phone can now let you back into your own account. Set it up under your name at the top right, before you need it.'),
            ],
            [
                'date' => '2026-08-22',
                'title' => __('The keypad does sums'),
                'body' => __('Tap a price or a quantity and the keypad has + − × ÷ on it. Type 20000 − 1500 and press OK to apply 18,500 — useful when a customer asks for something off.'),
            ],
            [
                'date' => '2026-08-22',
                'title' => __('Printing your own barcode labels'),
                'body' => __('Products that came without a barcode can be given one and a label printed for the shelf, in the size your printer takes.'),
            ],
            [
                'date' => '2026-08-21',
                'title' => __('Your shop’s own colour, font and logo'),
                'body' => __('Settings can now change how the whole system looks, and put your logo on every printed invoice.'),
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function topics(): array
    {
        return [
            /* ---- Getting started ------------------------------------- */

            self::FIRST => [
                'group' => 'start',
                'icon' => 'signpost-2',
                'title' => __('Your first week'),
                'blurb' => __('Five things to do before the shop starts trading, in the order the system needs them.'),
                'minutes' => 4,
                'route' => 'dashboard',
                'permission' => 'dashboard.view',
                'sections' => [
                    [__('The order is the important part'), [
                        __('Each step needs the one before it. Doing them out of order is the single most common reason a new shop decides the system is broken on the first day.'),
                        __('The dashboard shows this same list and ticks the steps off as you do them. It puts itself away once all five are done.'),
                    ]],
                    [__('1. Put your shop’s name and phone on invoices'), [
                        __('Settings, at the bottom of the menu. The name and phone you type here are what a customer reads on the printed invoice, so type them the way you want them read.'),
                    ]],
                    [__('2. Add your first product, with a category'), [
                        __('Categories come first, because a product has to belong to one. One or two are enough to start — Phones, Accessories — and more can be added at any time.'),
                    ]],
                    [__('3. Record what you bought from a supplier'), [
                        __('Add the supplier, then record the purchase. This is the step people skip, and it is the one that matters most: nothing is on the shelf until a purchase says so.'),
                    ]],
                    [__('4. Make your first sale'), [
                        __('New sale. If a product says none in stock, go back to step 3 — the system will not sell what it was never told you bought.'),
                    ]],
                    [__('5. Print the invoice'), [
                        __('The invoice opens by itself after a sale is saved. Print one, look at it, and go back to Settings if the shop details need changing.'),
                    ]],
                ],
            ],

            'staff-logins' => [
                'group' => 'start',
                'icon' => 'person-badge',
                'title' => __('Giving your staff their own logins'),
                'blurb' => __('Everybody signs in as themselves, and sees only the parts of the shop their job needs.'),
                'minutes' => 3,
                'route' => 'users.index',
                'permission' => null,
                'sections' => [
                    [__('One login each, never a shared one'), [
                        __('Every sale, purchase, return and price change is recorded against the name of the person who did it. A login shared by four people records four people as one, and the history stops being able to answer the only question you will ever ask of it.'),
                    ]],
                    [__('Start from a preset'), [
                        __('The Users screen offers ready-made sets of permissions — a cashier, a stock keeper, a manager. Pick the closest one and change what does not fit, rather than ticking forty boxes.'),
                    ]],
                    [__('Costs and profit are a separate decision'), [
                        __('Seeing what an item cost you is its own permission. Somebody can ring up sales all day without ever seeing what the shop paid, and for most shops that is the right arrangement.'),
                    ]],
                    [__('When somebody leaves'), [
                        __('Turn the account off rather than deleting it. Their name stays on everything they did, and nothing they touched loses its history.'),
                    ]],
                ],
            ],

            'invoice-details' => [
                'group' => 'start',
                'icon' => 'shop',
                'title' => __('Putting your shop on the invoice'),
                'blurb' => __('Name, phone and logo, so a printed invoice looks like it came from your shop.'),
                'minutes' => 2,
                'route' => 'settings.edit',
                'permission' => 'settings.manage',
                'sections' => [
                    [__('What is printed'), [
                        __('The shop name, phone and address from Settings appear at the top of every invoice, credit note and supplier document. A logo, if you upload one, goes beside them.'),
                    ]],
                    [__('The whole system can wear your colours'), [
                        __('Settings also holds the colour and the font used on screen. It is one shop’s system, not a generic one, and it should look like it.'),
                    ]],
                    [__('Saving needs a press and hold'), [
                        __('Settings change how money is counted across the whole system, so the save button has to be held down for a moment rather than clicked. That is deliberate, and it is the only place it happens.'),
                    ]],
                ],
            ],

            /* ---- Selling --------------------------------------------- */

            'making-a-sale' => [
                'group' => 'selling',
                'icon' => 'cart-plus',
                'title' => __('Making a sale'),
                'blurb' => __('Scan, take the money, print. What the till does, and the few things it does that a till does not.'),
                'minutes' => 3,
                'route' => 'sales.create',
                'permission' => 'sales.create',
                'sections' => [
                    [__('The barcode reader is the fast way'), [
                        __('Scan and the item is on the invoice. Nothing needs to be clicked first — the screen is waiting for the scanner from the moment it opens.'),
                        __('Without a barcode, type part of the name and press Enter. Two or three letters are usually enough.'),
                    ]],
                    [__('Changing a price or a quantity'), [
                        __('Tap the number. A keypad opens with buttons big enough for a thumb, and it does sums: 20000 − 1500 then OK applies 18,500.'),
                    ]],
                    [__('Cash Customer, or a name'), [
                        __('Cash Customer is for a walk-in who pays and leaves, and must always pay in full. Choosing a named customer is what allows part of the money to be left owing.'),
                    ]],
                    [__('Two prices for the same thing'), [
                        __('The same product can be put on the invoice twice at two different prices. That is how you give one of them away cheaper without changing what the product is worth.'),
                    ]],
                    [__('F2 saves'), [
                        __('The invoice opens ready to print. A sale can still be corrected afterwards, as long as nothing has been returned against it.'),
                    ]],
                ],
            ],

            'pay-later' => [
                'group' => 'selling',
                'icon' => 'person-lines-fill',
                'title' => __('Letting a customer pay later'),
                'blurb' => __('Selling on credit, and keeping track of what each person owes.'),
                'minutes' => 3,
                'route' => 'customers.index',
                'permission' => 'customers.view',
                'sections' => [
                    [__('It starts with a name'), [
                        __('Add the person as a customer. Cash Customer cannot owe anything — that is what makes it the safe default for a stranger at the counter.'),
                    ]],
                    [__('Take what they hand over, and no more'), [
                        __('On the sale, type what they actually paid. The rest becomes their balance, and it is theirs from that moment, not from the end of the day.'),
                    ]],
                    [__('When they come back with money'), [
                        __('Payments, then a payment from that customer. It is not tied to one invoice: it comes off what they owe overall, which is how a shop and a customer actually settle up.'),
                    ]],
                    [__('Seeing where the money is'), [
                        __('The customers list shows every balance at once. Reports has the same thing between two dates, which is the version to export if somebody needs it on paper.'),
                    ]],
                ],
            ],

            'customer-returns' => [
                'group' => 'selling',
                'icon' => 'arrow-return-left',
                'title' => __('A customer brings something back'),
                'blurb' => __('Take back all or part of an invoice, work out the refund, and put the stock back on the shelf.'),
                'minutes' => 3,
                // The index rather than the create screen: a return is always
                // started from the invoice it belongs to, so `sale-returns.create`
                // needs a sale in its URL and there is none to name here.
                'route' => 'sale-returns.index',
                'permission' => 'sale_returns.view',
                'sections' => [
                    [__('Start from the invoice, not from the product'), [
                        __('Find the sale and return against it. That is what lets the system know what the customer actually paid for that piece, discount included, instead of guessing at today’s price.'),
                    ]],
                    [__('Part of an invoice is normal'), [
                        __('Return one of the three they bought and the other two stay sold. What has already come back is remembered, so the same piece cannot be returned twice.'),
                    ]],
                    [__('The refund clears a debt before it becomes cash'), [
                        __('If that customer owes the shop money, the refund comes off what they owe first, and only what is left over is handed back. A customer’s balance is never allowed to go below zero.'),
                    ]],
                    [__('Nothing is deleted'), [
                        __('An invoice returned in full is marked as returned. Both documents stay in the history, and the profit for the two together comes to nothing — which is the truth of what happened.'),
                    ]],
                ],
            ],

            'two-customers' => [
                'group' => 'selling',
                'icon' => 'pause-circle',
                'title' => __('Serving two customers at once'),
                'blurb' => __('Park a half-finished sale, serve somebody else, and come back to it.'),
                'minutes' => 2,
                'route' => 'sales.create',
                'permission' => 'sales.create',
                'sections' => [
                    [__('Hold this cart'), [
                        __('Somebody is deciding, or has gone back for a charger, and there is a queue behind them. Hold the cart and the screen is clear for the next person.'),
                    ]],
                    [__('Picking it up again'), [
                        __('Held carts wait on the sale screen and can be brought back whenever. Nothing has been sold and no stock has moved — a held cart is a piece of paper on the counter, not a document.'),
                    ]],
                    [__('They are yours, not the till’s'), [
                        __('A cart you held is waiting for you, not for whoever is on the till next. Two people on two machines never see each other’s half-finished sales.'),
                    ]],
                ],
            ],

            /* ---- Stock ------------------------------------------------ */

            'recording-purchases' => [
                'group' => 'stock',
                'icon' => 'bag-plus',
                'title' => __('Recording what you bought'),
                'blurb' => __('The step that puts stock on the shelf and tells the system what it cost you.'),
                'minutes' => 3,
                'route' => 'purchases.create',
                'permission' => 'purchases.create',
                'sections' => [
                    [__('Nothing is in stock until a purchase says so'), [
                        __('Adding a product to the catalogue creates a name and a price. It does not create anything on the shelf. The purchase is what does that, and it is why a brand-new shop cannot sell before it has bought.'),
                    ]],
                    [__('The price you type is what it cost you'), [
                        __('Not what you will sell it for. The system keeps this figure for as long as those pieces are on the shelf and works out every profit figure from it, so a wrong cost here quietly makes months of reports wrong.'),
                    ]],
                    [__('Pay some of it, or none of it'), [
                        __('Say what you handed over. The rest becomes what the shop owes that supplier, and it is settled later from the Payments screen, exactly like a customer’s debt in reverse.'),
                    ]],
                    [__('Buying in dollars'), [
                        __('Type the rate and the amount in dollars and it converts once, to whole dinars. The dinar figure is what is stored — the rate is not remembered and will not change what you paid when it moves next week.'),
                    ]],
                ],
            ],

            'shelf-mismatch' => [
                'group' => 'stock',
                'icon' => 'sliders',
                'title' => __('The shelf does not match the screen'),
                'blurb' => __('Something broke, walked out, or was never counted. How to make the number honest again.'),
                'minutes' => 3,
                'route' => 'stock-adjustments.index',
                'permission' => 'stock_adjustments.view',
                'sections' => [
                    [__('Count the shelf first'), [
                        __('Always. An adjustment made from memory is a second guess written down as a fact, and next month nobody will be able to tell which of the two numbers was the guess.'),
                    ]],
                    [__('Out, or in'), [
                        __('Out for something broken, stolen, given away or miscounted. In for something found behind a box, or for stock you already had on the day you started using the system.'),
                    ]],
                    [__('The reason is the whole point'), [
                        __('One short line: dropped, taken by staff, count was wrong. In two months that sentence is the only thing that will explain a gap between what you bought and what you sold.'),
                    ]],
                    [__('Recheck stock is a different thing'), [
                        __('It changes nothing. It adds the shelf up again from what is already recorded and tells you what it finds. Use it when a figure looks odd; use an adjustment when you have counted and you know the screen is wrong.'),
                    ]],
                ],
            ],

            'two-costs' => [
                'group' => 'stock',
                'icon' => 'layers',
                'title' => __('Why the same product has two costs'),
                'blurb' => __('You bought it at 10,000 in June and 12,000 in August. What the system does about that.'),
                'minutes' => 4,
                'permission' => null,
                'sections' => [
                    [__('Each delivery is kept separate'), [
                        __('The ten you bought at 10,000 and the ten you bought at 12,000 are the same product on the shelf, but the system remembers them as two batches, each with the price you actually paid for it.'),
                    ]],
                    [__('The oldest is sold first'), [
                        __('Sell twelve and the system takes the ten old ones and two of the new. That is not a preference — it is how the profit on those twelve is worked out, and it is what makes the figure match the money in the drawer.'),
                    ]],
                    [__('So profit is not sale price less purchase price'), [
                        __('The purchase price on the product page is a reminder of what you generally pay. Profit is worked out from what each piece really cost, one batch at a time. The two are close, and they are not the same, and the second one is the true one.'),
                    ]],
                    [__('A returned piece goes back where it came from'), [
                        __('Return something sold from the June batch and it goes back into the June batch at 10,000, not into the current one. Your costs stay right no matter how much comes back.'),
                    ]],
                    [__('A discount on a purchase does not change the cost'), [
                        __('If a supplier takes something off the total, each item still cost what the line says. That is deliberate: the cost is what you paid per piece, and a discount is a separate thing that reports account for on its own.'),
                    ]],
                ],
            ],

            'supplier-returns' => [
                'group' => 'stock',
                'icon' => 'arrow-return-right',
                'title' => __('Sending something back to a supplier'),
                'blurb' => __('Faulty or wrong goods going back, and what happens to what you owe them.'),
                'minutes' => 2,
                'route' => 'purchase-returns.index',
                'permission' => 'purchase_returns.view',
                'sections' => [
                    [__('It has to still be on the shelf'), [
                        __('You can only send back what you still hold from that purchase. If it has already gone out to customers there is nothing to return, and the right tool is a stock adjustment instead.'),
                    ]],
                    [__('What you owe goes down'), [
                        __('The value of the return comes off the supplier’s balance. If you had already paid them, it becomes credit with them rather than cash coming back — which is what actually happens between a shop and its supplier.'),
                    ]],
                    [__('Print it for them'), [
                        __('The document lists what went back and what it was worth, so there is one piece of paper you both agree on.'),
                    ]],
                ],
            ],

            /* ---- Money and reports ----------------------------------- */

            'who-owes' => [
                'group' => 'money',
                'icon' => 'cash-coin',
                'title' => __('Who owes the shop money?'),
                'blurb' => __('Finding the debts, taking payments, and knowing the figure is right.'),
                'minutes' => 3,
                'route' => 'payments.index',
                'permission' => 'payments.view',
                'sections' => [
                    [__('The list is the answer'), [
                        __('Customers shows every balance side by side. Anything above zero is money owed to the shop, and the name beside it is who to ring.'),
                    ]],
                    [__('Taking a payment'), [
                        __('Payments, then the customer and the amount. It comes off their overall balance rather than off one invoice, because that is how people pay: they hand over what they can, against what they owe.'),
                    ]],
                    [__('Suppliers work the same way backwards'), [
                        __('A supplier balance is what the shop owes out. The same screen records those payments, and the same list shows who is waiting.'),
                    ]],
                    [__('A balance is never just a number in a box'), [
                        __('Every balance is added up from the sales, payments and returns behind it, and each one is recorded in order. That is why a balance can be trusted, and why it cannot be typed over by hand.'),
                    ]],
                ],
            ],

            'spending' => [
                'group' => 'money',
                'icon' => 'cash-stack',
                'title' => __('Recording what the shop spends'),
                'blurb' => __('Rent, electricity, tea. The money that leaves without any stock coming in.'),
                'minutes' => 2,
                'route' => 'expenses.index',
                'permission' => 'expenses.view',
                'sections' => [
                    [__('This is not for buying stock'), [
                        __('Anything you bought to sell is a purchase, not an expense. Expenses are the money that leaves without stock coming in: rent, the generator, a repair, wages.'),
                    ]],
                    [__('Categories make the total readable'), [
                        __('A year of expenses in one long list tells you nothing. A handful of categories turns it into an answer to the question you will actually ask, which is what the shop spends most on.'),
                    ]],
                    [__('They come off the profit'), [
                        __('Reports takes expenses off after the cost of what was sold, so the last figure on the page is what the shop really made rather than what it took.'),
                    ]],
                ],
            ],

            'where-profit-comes-from' => [
                'group' => 'money',
                'icon' => 'graph-up',
                'title' => __('Where the profit figure comes from'),
                'blurb' => __('Read the reports page from the top down, and every line explains the one under it.'),
                'minutes' => 3,
                'route' => 'reports.index',
                'permission' => 'reports.view',
                'sections' => [
                    [__('Pick the dates first'), [
                        __('Everything on the page follows the two dates at the top. A figure that looks wrong is very often a figure for the wrong month.'),
                    ]],
                    [__('Down the page, in order'), [
                        __('What you sold, less what came back, is the revenue. Take off what those exact pieces cost you, and that is the gross profit. Then discounts, write-offs and expenses come off, and what is left is the shop’s.'),
                    ]],
                    [__('What each piece cost you, not the list price'), [
                        __('Two of the same item bought at different prices are counted at what each one really cost, oldest sold first. It is the slower way to work it out and the only one that matches the money.'),
                    ]],
                    [__('Stock value is at cost'), [
                        __('The value of the shelf is what it cost you, not what it would fetch. Profit that has not happened yet is not counted as profit.'),
                    ]],
                    [__('Take it away with you'), [
                        __('Export gives the same figures as a spreadsheet, for an accountant or your own records.'),
                    ]],
                ],
            ],

            /* ---- Looking after it ------------------------------------ */

            'backups' => [
                'group' => 'care',
                'icon' => 'shield-check',
                'title' => __('Backups, and getting your data back'),
                'blurb' => __('What is saved, where it goes, and the one thing worth doing before you need it.'),
                'minutes' => 3,
                'permission' => null,
                'sections' => [
                    [__('It runs on its own'), [
                        __('A copy of everything is taken automatically, and older ones are kept for a while — a month of daily copies and a year of monthly ones. You do not have to remember.'),
                    ]],
                    [__('Take one before anything frightening'), [
                        __('Settings has a button that takes one right now. Press it before importing a file, before clearing the testing period, before anything you would not want to do twice.'),
                    ]],
                    [__('A second copy, not on the same machine'), [
                        __('A backup on the machine that broke is not a backup. There is a setting for a second folder — a drive, a network share, anything that is somewhere else.'),
                    ]],
                    [__('The part everybody skips'), [
                        __('A backup nobody has ever restored is a hope, not a backup. Ask whoever installed this to restore one onto a spare machine once, so you both know it works before the day it matters.'),
                    ]],
                ],
            ],

            'number-looks-wrong' => [
                'group' => 'care',
                'icon' => 'clipboard-check',
                'title' => __('When a number looks wrong'),
                'blurb' => __('Before assuming the system is broken, there is a page that asks the shop’s records seventeen questions.'),
                'minutes' => 3,
                'route' => 'settings.data-check',
                'permission' => 'settings.manage',
                'sections' => [
                    [__('Check the dates and the deleted rows first'), [
                        __('Most surprises are one of two things: a report for the wrong period, or rows that were deleted and are still counted somewhere else. Both look exactly like a bug.'),
                    ]],
                    [__('Data check'), [
                        __('In Settings. It asks the shop’s own records whether they still agree with each other — every batch against its movements, every balance against the entries behind it — and reports what it finds.'),
                    ]],
                    [__('It reports, it does not repair'), [
                        __('That is on purpose. Quietly fixing a contradiction destroys the evidence of what caused it, and the difference between a total to add up again and two records that cannot both be right is a judgement a person has to make.'),
                    ]],
                    [__('If a stock figure is the odd one'), [
                        __('Recheck stock on the product adds the shelf up again from the movements. It is safe, it changes nothing, and it is the right first move.'),
                    ]],
                ],
            ],

            'forgot-password' => [
                'group' => 'care',
                'icon' => 'key',
                'title' => __('Nobody can sign in'),
                'blurb' => __('Three ways back into the shop, and the one to set up before you need it.'),
                'minutes' => 3,
                'route' => 'authenticator.show',
                'permission' => null,
                'sections' => [
                    [__('This system sends no email'), [
                        __('There is no reset link, and there is nothing to wait for in an inbox. That is worth knowing on a good day rather than on a bad one.'),
                    ]],
                    [__('First: your phone'), [
                        __('Under your own name at the top right, an authenticator app on your phone can be set up in two minutes. After that a forgotten password is a small annoyance instead of a locked shop. Do it today.'),
                    ]],
                    [__('Then: an administrator'), [
                        __('Anyone with the Users screen can set a new password for somebody else. That covers every staff account.'),
                    ]],
                    [__('Last: the person who installed it'), [
                        __('If the owner’s own account is the one locked out and no phone was set up, it takes somebody at the machine running one command. It always works, and it is the slowest of the three, which is why the phone is worth the two minutes.'),
                    ]],
                ],
            ],
        ];
    }
}
