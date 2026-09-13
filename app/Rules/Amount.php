<?php

namespace App\Rules;

use App\Models\Currency;
use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A money field, checked in whichever currency it was typed in — Section 2b.
 *
 * Replaces `integer` on a field that a lens can reach. Under no lens it is the
 * same check by another name; under one, `12.50` is a valid amount and
 * `integer` would have refused it.
 *
 * The limits are always in BASE-currency units, because that is what gets
 * stored and what Section 4's rules are written about. A minimum of 1 means one
 * dinar, whatever currency the person is typing in.
 */
class Amount implements ValidationRule
{
    public function __construct(
        private readonly ?Currency $lens = null,
        private readonly ?int $min = null,
        private readonly ?int $max = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parsed = Money::parse($value, $this->lens);

        if ($parsed === null) {
            $fail(__('Enter an amount as a number.'));

            return;
        }

        // Reported back in the currency the person is looking at, because a
        // dollar field refusing "at least 1" is a field nobody can satisfy
        // without doing the arithmetic themselves.
        if ($this->min !== null && $parsed < $this->min) {
            $fail(__('The amount must be at least :least.', [
                'least' => money($this->min, true, $this->lens),
            ]));
        }

        if ($this->max !== null && $parsed > $this->max) {
            $fail(__('The amount cannot be more than :most.', [
                'most' => money($this->max, true, $this->lens),
            ]));
        }
    }
}
