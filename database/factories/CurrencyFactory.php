<?php

namespace Database\Factories;

use App\Models\Currency;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Currency>
 */
class CurrencyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->lexify('???')),
            'name' => $this->faker->word(),
            'symbol' => null,
            'decimals' => 2,
            'rate' => 1_000 * Money::RATE_SCALE,
            'is_active' => true,
        ];
    }

    /** A dollar as an Iraqi shop knows it: two decimals, 1,320 to the dinar. */
    public function dollar(int $rate = 1_320): static
    {
        return $this->state(fn () => [
            'code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$',
            'decimals' => 2, 'rate' => $rate * Money::RATE_SCALE,
        ]);
    }
}
