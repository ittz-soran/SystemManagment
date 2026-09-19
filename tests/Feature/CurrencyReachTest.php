<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every screen that says an amount goes through the currency system.
 *
 * Soran, 2026-09-13: *"any screen read or write need this currency system add
 * to it"*. Section 2b's lens is opt-in per screen precisely so it can never
 * reach the till by accident — which is the right design and also the reason a
 * screen can silently be left out. Twenty-odd views were, until this swept
 * them, and nothing failed: the figures were simply in the wrong currency.
 *
 * So the rule is checked here rather than remembered. A new screen that prints
 * money and forgets the lens fails this test with its own filename.
 */
class CurrencyReachTest extends TestCase
{
    /**
     * ⚠️ Screens that must NEVER convert.
     *
     * The till, because a customer is standing at it with notes in their hand.
     * Anything printed, because a printed figure is read by somebody who never
     * chose a preference and cannot see one — a printed document says what it
     * says, at the rate frozen onto it (decision 1c) and nothing else.
     */
    private const NEVER = [
        'print/',
        'reports/print/',
        /*
         * ⚠️ **Still never the READER'S lens, even though the till now has a
         * currency of its own — Soran, 2026-09-19.**
         *
         * These are two different things and the distinction is the whole
         * rule. A lens converts a stored dinar figure at TODAY's rate, for
         * reading, and it must never touch the till: a customer is standing
         * there with notes in their hand. A document currency means the sale is
         * WRITTEN in dollars at a rate frozen onto that receipt, which is what
         * the purchase cart has always done and what the sale screen does now.
         *
         * So `money()` on this screen still takes no currency, and this entry
         * stays exactly where it was.
         */
        'sales/create',
        'labels/sheet',
        // The switcher itself, which draws a sample of each currency.
        'components/currency-lens',
    ];

    /** These print an amount and are deliberately left in the base currency. */
    private const BASE_ON_PURPOSE = [
        // Shared with the till, and a cart put down before a currency was
        // chosen has no currency to be read in.
        'partials/held-carts',
        // The purchase cart, whose figures follow the INVOICE currency chosen
        // on the document rather than the reader's own preference.
        'purchases/create',
    ];

    public function test_no_screen_that_must_not_convert_has_been_given_a_lens(): void
    {
        foreach ($this->views() as $path => $source) {
            if (! $this->pathIn($path, self::NEVER)) {
                continue;
            }

            $this->assertStringNotContainsString(
                '$lens',
                $source,
                "{$path} must never convert — see Section 2b.",
            );
        }
    }

    /** Every money figure on every other screen is handed a currency. */
    public function test_every_other_screen_hands_its_figures_a_currency(): void
    {
        $offenders = [];

        foreach ($this->views() as $path => $source) {
            if ($this->pathIn($path, self::NEVER) || $this->pathIn($path, self::BASE_ON_PURPOSE)) {
                continue;
            }

            foreach ($this->moneyCalls($source) as $call) {
                if (! str_contains($call, 'lens')) {
                    $offenders[] = "{$path}: {$call}";
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These figures are drawn in the base currency on a screen that converts.',
            'Pass the reader\'s lens: money($x, in: $lens). See Section 2b.',
            ...$offenders,
        ]));
    }

    /** @return array<string, string> */
    private function views(): array
    {
        $out = [];

        foreach ($this->files(resource_path('views')) as $file) {
            $out[str_replace(resource_path('views').'/', '', $file)] = file_get_contents($file);
        }

        return $out;
    }

    /** @return list<string> */
    private function files(string $directory): array
    {
        $found = [];

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path)) {
                $found = [...$found, ...$this->files($path)];
            } elseif (str_ends_with($entry, '.blade.php')) {
                $found[] = $path;
            }
        }

        return $found;
    }

    /** @param  list<string>  $needles */
    private function pathIn(string $path, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($path, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every money helper call in a template, with its arguments.
     *
     * Balanced parentheses, so `money(round($a / $b))` is one call and not a
     * truncated one — and a lookbehind, so `class="money"` and `$this->money()`
     * are not mistaken for calls at all.
     *
     * @return list<string>
     */
    private function moneyCalls(string $source): array
    {
        // Comments first: `money()` inside one is prose, not a figure.
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source) ?? $source;
        $source = preg_replace('/\/\*.*?\*\//s', '', $source) ?? $source;

        $found = [];

        foreach (['money', 'money_if', 'cost_money', 'money_short'] as $name) {
            $offset = 0;

            while (preg_match('/(?<![\w>$])'.$name.'\(/', $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
                $start = $m[0][1];
                $i = $start + strlen($m[0][0]);
                $depth = 1;

                while ($i < strlen($source) && $depth > 0) {
                    $depth += match ($source[$i]) {
                        '(' => 1, ')' => -1, default => 0
                    };
                    $i++;
                }

                $call = substr($source, $start, $i - $start);

                // An empty call is prose that survived the comment strip.
                if (trim(substr($call, strlen($name) + 1, -1)) !== '') {
                    $found[] = $call;
                }

                $offset = $i;
            }
        }

        return $found;
    }
}
