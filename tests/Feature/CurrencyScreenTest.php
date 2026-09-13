<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Permission;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Managing the currencies a shop can type and read in — Section 2b.
 *
 * The interesting tests here are the two REFUSALS. Everything else is an
 * ordinary managed list; those two are the reason the page needed thinking
 * about at all, because both of them silently reprice the whole shop.
 */
class CurrencyScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    private function usd(): Currency
    {
        return Currency::where('code', 'USD')->firstOrFail();
    }

    private function iqd(): Currency
    {
        return Currency::where('code', 'IQD')->firstOrFail();
    }

    public function test_it_lists_what_the_shop_can_type_in(): void
    {
        $this->actingAs($this->admin)->get(route('currencies.index'))
            ->assertOk()
            ->assertSee('IQD')
            ->assertSee('USD')
            ->assertSee('US Dollar')
            // §6b's rate, carried across rather than invented.
            ->assertSee('1320');
    }

    public function test_the_base_is_marked_and_has_no_rate_of_its_own(): void
    {
        $this->actingAs($this->admin)->get(route('currencies.index'))
            ->assertOk()
            ->assertSee(__('Base'));

        $this->assertSame('IQD', Money::base()->code);
    }

    public function test_a_currency_can_be_added(): void
    {
        $this->actingAs($this->admin)->post(route('currencies.store'), [
            'code' => 'eur', 'name' => 'Euro', 'symbol' => '€',
            'decimals' => 2, 'rate' => '1450.5',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $eur = Currency::where('code', 'EUR')->firstOrFail();

        // Upper-cased on the way in, because a supplier's invoice says EUR.
        $this->assertSame('EUR', $eur->code);
        $this->assertSame(1_450_500, $eur->rate);
        $this->assertSame('1450.5', $eur->rateAsTyped());

        // And it converts, which is the whole point of adding it.
        $this->assertSame(14_505, Money::parse('10', $eur->fresh()));
    }

    public function test_a_rate_that_is_not_a_number_is_refused(): void
    {
        $this->actingAs($this->admin)->post(route('currencies.store'), [
            'code' => 'EUR', 'name' => 'Euro', 'decimals' => 2, 'rate' => 'soon',
        ])->assertSessionHasErrors('rate');

        $this->assertNull(Currency::where('code', 'EUR')->first());
    }

    public function test_a_rate_of_zero_is_refused(): void
    {
        $this->actingAs($this->admin)->post(route('currencies.store'), [
            'code' => 'EUR', 'name' => 'Euro', 'decimals' => 2, 'rate' => '0',
        ])->assertSessionHasErrors('rate');
    }

    public function test_the_same_code_cannot_be_added_twice(): void
    {
        $this->actingAs($this->admin)->post(route('currencies.store'), [
            'code' => 'USD', 'name' => 'Another dollar', 'decimals' => 2, 'rate' => '1300',
        ])->assertSessionHasErrors('code');

        $this->assertSame(1, Currency::where('code', 'USD')->count());
    }

    public function test_a_rate_can_be_changed_and_takes_effect_at_once(): void
    {
        $usd = $this->usd();

        $this->assertSame(660_000, Money::parse('500', $usd));

        $this->actingAs($this->admin)->put(route('currencies.update', $usd), [
            'name' => 'US Dollar', 'symbol' => '$', 'rate' => '1350', 'is_active' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(675_000, Money::parse('500', $this->usd()));
    }

    /**
     * ⚠️ Refusal one: the base currency has no rate anybody may set.
     *
     * It is `10^decimals` by definition — a dinar is one dinar. Letting
     * somebody type 1,320 into it would divide every figure in the shop by
     * 1,320 on the next page load.
     */
    public function test_the_base_currency_keeps_its_own_rate_whatever_is_posted(): void
    {
        $iqd = $this->iqd();
        $was = $iqd->rate;

        $this->actingAs($this->admin)->put(route('currencies.update', $iqd), [
            'name' => 'Iraqi Dinar', 'rate' => '1320', 'is_active' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($was, $this->iqd()->rate, 'The books were repriced by a rate box.');
        $this->assertSame('250,000', Money::format(250_000));
    }

    /**
     * ⚠️ Refusal two: the base cannot be switched off.
     *
     * A shop with no currency for its own books has no way to write a figure
     * at all — including on the page that would switch it back on.
     */
    public function test_the_base_currency_cannot_be_switched_off(): void
    {
        $this->actingAs($this->admin)->put(route('currencies.update', $this->iqd()), [
            'name' => 'Iraqi Dinar',
            // The checkbox posts nothing when unticked, which is exactly how
            // this would be attempted.
        ])->assertRedirect();

        $this->assertTrue($this->iqd()->is_active);
    }

    /** A currency the shop has stopped using is switched off, not deleted. */
    public function test_a_currency_can_be_switched_off(): void
    {
        $this->actingAs($this->admin)->put(route('currencies.update', $this->usd()), [
            'name' => 'US Dollar', 'rate' => '1320',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse($this->usd()->is_active);
        $this->assertNotNull($this->usd(), 'A currency in use on old records was deleted.');
    }

    /** ⚠️ The rate must survive being typed, stored and read back. */
    public function test_a_fractional_rate_survives_the_round_trip(): void
    {
        $this->actingAs($this->admin)->put(route('currencies.update', $this->usd()), [
            'name' => 'US Dollar', 'rate' => '1320.125', 'is_active' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1_320_125, $this->usd()->rate);
        $this->assertSame('1320.125', $this->usd()->rateAsTyped());
    }

    /** A thousands separator is what a person pastes out of a spreadsheet. */
    public function test_a_rate_typed_with_a_separator_is_understood(): void
    {
        $this->actingAs($this->admin)->put(route('currencies.update', $this->usd()), [
            'name' => 'US Dollar', 'rate' => '1,350', 'is_active' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1_350_000, $this->usd()->rate);
    }

    /** Section 8c: the whole of settings is behind one permission. */
    public function test_a_reader_without_settings_cannot_reach_it(): void
    {
        $staff = User::create([
            'name' => 'Till', 'email' => 'till@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);
        $staff->permissions()->sync(Permission::where('key', 'sales.view')->pluck('id')->all());

        $this->actingAs($staff)->get(route('currencies.index'))->assertForbidden();

        $this->actingAs($staff)->put(route('currencies.update', $this->usd()), [
            'name' => 'Theirs', 'rate' => '1',
        ])->assertForbidden();

        $this->assertSame(1_320_000, $this->usd()->rate);
    }

    /** Settings names the page, so it can be found without knowing the URL. */
    public function test_settings_links_to_it(): void
    {
        $this->actingAs($this->admin)->get(route('settings.edit'))
            ->assertOk()
            ->assertSee(route('currencies.index'), false);
    }
}
