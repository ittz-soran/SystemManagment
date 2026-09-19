<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Managing the currencies a shop can type and read in — Section 2b.
 *
 * The interesting tests here are the REFUSALS. Everything else is an ordinary
 * managed list; those are the reason the page needed thinking about at all,
 * because each of them can silently reprice the whole shop.
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

    // ---- Renaming a code -------------------------------------------------

    /**
     * **Soran, 2026-09-17:** *"fix currency code"*.
     *
     * His base was coded `IRQ`. The dinar's code is `IQD`. Until now a code
     * could only be chosen when the currency was created, so the only way to
     * correct a typo was to delete the currency — and the base cannot be
     * deleted, because the books are kept in it. A shop was stuck with it.
     */
    public function test_a_code_can_be_corrected(): void
    {
        $this->rename($this->usd(), 'USDX')->assertSessionHasNoErrors();

        $this->assertNull(Currency::where('code', 'USD')->first());
        $this->assertNotNull(Currency::where('code', 'USDX')->first());
    }

    /**
     * ⚠️ The books follow the rename.
     *
     * `settings.currency_base` holds a CODE. Left pointing at the old one,
     * `Money::base()` finds no row and falls back to an assumed currency — so
     * every figure in the shop is read against a rate nobody set.
     */
    public function test_renaming_the_base_moves_the_books_with_it(): void
    {
        $this->rename($this->iqd(), 'IQD2')->assertSessionHasNoErrors();

        Currency::flushCache();

        $this->assertSame('IQD2', setting('currency_base'));
        $this->assertSame('IQD2', Money::base()->code);

        // And it is a REAL row, not the assumption Money falls back to.
        $this->assertNotNull(Currency::where('code', 'IQD2')->first());
    }

    /** Every reader's lens follows it too, rather than silently resetting. */
    public function test_renaming_carries_each_readers_lens(): void
    {
        $this->admin->forceFill(['display_currency' => 'USD'])->save();

        $this->rename($this->usd(), 'USDX')->assertSessionHasNoErrors();

        Currency::flushCache();

        $this->assertSame('USDX', $this->admin->fresh()->display_currency);
        $this->assertSame('USDX', $this->admin->fresh()->lens()?->code);
    }

    /**
     * ⚠️ And so does the code frozen on purchases already entered.
     *
     * A line that said `IRQ` meant the dinar and still means the dinar — only
     * the shop's name for it was wrong. Left behind, `typedIn()` returns null
     * and the printed document quietly loses its "typed as $120" note.
     */
    public function test_renaming_carries_the_code_frozen_on_old_purchases(): void
    {
        $category = Category::create(['name' => 'Test']);
        $product = Product::create([
            'name' => 'Widget', 'sku' => 'W1', 'category_id' => $category->id, 'unit' => 'pcs',
            'purchase_price' => 0, 'sale_price' => 1_000, 'quantity' => 0,
        ]);

        $purchase = app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'S']),
            lines: [[
                'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 150_000,
                'entered_currency' => 'USD', 'entered_amount' => 100,
            ]],
            user: $this->admin,
            purchaseDate: now(),
        );

        $line = $purchase->items()->firstOrFail();
        $this->assertSame('USD', $line->entered_currency);

        $this->rename($this->usd(), 'USDX')->assertSessionHasNoErrors();

        Currency::flushCache();

        $line = $line->fresh();

        $this->assertSame('USDX', $line->entered_currency);
        $this->assertSame(
            'USDX',
            $line->typedIn()?->code,
            'The old purchase line lost the currency it was typed in.'
        );
    }

    /** Two currencies cannot end up sharing a code. */
    public function test_a_code_already_in_use_is_refused(): void
    {
        $this->rename($this->usd(), 'IQD')->assertSessionHasErrors('code');

        $this->assertSame('USD', $this->usd()->code);
    }

    /** The same rules as creating one: no spaces, no punctuation. */
    public function test_a_code_that_is_not_a_code_is_refused(): void
    {
        $this->rename($this->usd(), 'US $')->assertSessionHasErrors('code');

        $this->assertSame('USD', $this->usd()->code);
    }

    /** Typed in lower case, stored the way every other code is. */
    public function test_a_code_is_stored_upper_case(): void
    {
        $this->rename($this->usd(), 'usdx')->assertSessionHasNoErrors();

        $this->assertNotNull(Currency::where('code', 'USDX')->first());
    }

    /** Saving the form without touching the code changes nothing. */
    public function test_saving_without_changing_the_code_renames_nothing(): void
    {
        $this->admin->forceFill(['display_currency' => 'USD'])->save();

        $this->rename($this->usd(), 'USD')->assertSessionHasNoErrors();

        $this->assertSame('USD', $this->usd()->code);
        $this->assertSame('USD', $this->admin->fresh()->display_currency);
    }

    /**
     * ⚠️ **Soran's own shop, and the reason this was worth building.**
     *
     * The comment above `baseIsMissing` in CurrencyController records it: the
     * setting said `IQD`, he had replaced that row with his own `IRQ`, and
     * every figure in the shop was being read against an ASSUMED currency
     * rather than one anybody had set up. The page warned him, and there was
     * nothing he could do about it — the code could not be edited, and the row
     * could not be deleted.
     *
     * Renaming it is the cure, and it is one field.
     */
    public function test_a_shop_whose_books_point_at_a_missing_code_is_fixed_by_renaming(): void
    {
        // Put the shop in exactly that state: books kept in IQD, no such row.
        $this->iqd()->forceFill(['code' => 'IRQ'])->save();
        Currency::flushCache();

        $this->assertFalse(
            isset(Currency::cached()['IQD']),
            'This test is meaningless unless the books really are pointing at nothing.'
        );

        $this->actingAs($this->admin)->get(route('currencies.index'))
            ->assertOk()
            ->assertViewHas('baseIsMissing', true);

        // One field on one form.
        $this->rename(Currency::where('code', 'IRQ')->firstOrFail(), 'IQD')
            ->assertSessionHasNoErrors();

        Currency::flushCache();

        $this->assertTrue(isset(Currency::cached()['IQD']));
        $this->assertSame('IQD', Money::base()->code);

        $this->actingAs($this->admin)->get(route('currencies.index'))
            ->assertOk()
            ->assertViewHas('baseIsMissing', false);
    }

    /**
     * Post the edit form with a code, keeping everything else as it is.
     *
     * ⚠️ `is_active` is sent deliberately. It is a checkbox, so the form means
     * "off" by not sending it — and a helper that left it out switched the
     * currency off on every rename, which made `lens()` return null and looked
     * exactly like the rename having failed to carry the reader's lens across.
     * The bug was in this helper, not in the rename.
     */
    private function rename(Currency $currency, string $to)
    {
        return $this->actingAs($this->admin)->put(route('currencies.update', $currency), [
            'code' => $to,
            'name' => $currency->name,
            'symbol' => $currency->symbol,
            'decimals' => $currency->decimals,
            'rate' => $currency->rateAsTyped(),
            'is_active' => $currency->is_active ? '1' : '0',
        ]);
    }

    // ---- Which currency the books are kept in ---------------------------

    /**
     * ⚠️ THE MOST DANGEROUS BUTTON ON THIS SCREEN.
     *
     * Every stored integer counts base-currency minor units. Point
     * `currency_base` at the dollar and 250,000 recorded dinars do not convert
     * — they are REINTERPRETED as $250,000, on every document at once. Nothing
     * is written, so it is reversible; but a shopkeeper acts on the reading, so
     * once there are documents to misread the code has to be typed.
     *
     * **This was a flat refusal until 2026-09-14 and that was the wrong guard.**
     * Soran's base moved to the pound by itself that morning — `Money::base()`
     * fell back to whichever currency sorted first and he had just added GBP —
     * and the refusal then stopped him putting it back. A guard that blocks the
     * cure but not the disease is worse than none.
     */
    public function test_the_books_do_not_move_without_the_code_typed(): void
    {
        $this->aPurchase();

        $this->actingAs($this->admin)
            ->post(route('currencies.base', $this->usd()))
            ->assertSessionHasErrors('confirmation');

        $this->assertSame('IQD', Money::base()->code, 'the books moved on an empty confirmation');
    }

    /** Nor on a confirmation that is not the code. */
    public function test_a_wrong_confirmation_does_not_move_the_books(): void
    {
        $this->aPurchase();

        $this->actingAs($this->admin)
            ->post(route('currencies.base', $this->usd()), ['confirmation' => 'yes'])
            ->assertSessionHasErrors('confirmation');

        $this->assertSame('IQD', Money::base()->code);
    }

    /** ⚠️ And with the code typed it moves, because a shop must be able to. */
    public function test_the_books_move_when_the_code_is_typed(): void
    {
        $this->aPurchase();

        $this->actingAs($this->admin)
            ->post(route('currencies.base', $this->usd()), ['confirmation' => 'USD'])
            ->assertSessionHasNoErrors()->assertRedirect();

        Currency::flushCache();

        $this->assertSame('USD', Money::base()->code);
    }

    /**
     * ⚠️ Moving to a genuinely different currency switches the others off.
     *
     * Every rate in the table was quoted against the OLD base: 1,320 of a dinar
     * per dollar is not 1,320 of a dollar per dollar. Switched off rather than
     * converted, because a rate worked out from another rate carries its
     * rounding and nobody would know to check it.
     */
    public function test_moving_to_a_different_currency_switches_the_others_off(): void
    {
        $lira = Currency::create([
            'code' => 'TRY', 'name' => 'Turkish Lira', 'symbol' => null,
            'decimals' => 2, 'rate' => 40 * Money::RATE_SCALE, 'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('currencies.base', $this->usd()))
            ->assertSessionHasNoErrors();

        Currency::flushCache();

        $this->assertFalse($lira->fresh()->is_active);
        $this->assertFalse($this->iqd()->fresh()->is_active);
        $this->assertTrue($this->usd()->fresh()->is_active);
    }

    /**
     * But the same money under another name leaves every rate alone.
     *
     * A currency whose rate says "one of me is one base unit" IS the base,
     * spelled differently — which is the shop whose setting names a row nobody
     * ever created. Nothing about what a base unit is worth has moved, so the
     * dollar rate is still true.
     */
    public function test_renaming_the_base_leaves_the_other_rates_alone(): void
    {
        $iraq = Currency::create([
            'code' => 'IRQ', 'name' => 'Iraq dinar', 'symbol' => 'د.ع',
            'decimals' => 0, 'rate' => 1 * Money::RATE_SCALE, 'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('currencies.base', $iraq))
            ->assertSessionHasNoErrors();

        Currency::flushCache();

        $this->assertSame('IRQ', Money::base()->code);
        $this->assertTrue($this->usd()->fresh()->is_active, 'the dollar was switched off for nothing');
        $this->assertSame(1_320 * Money::RATE_SCALE, (int) $this->usd()->fresh()->rate);
    }

    /** ⚠️ And the screen shouts when the setting names a currency that is gone. */
    public function test_the_screen_says_when_the_books_name_a_currency_that_is_not_there(): void
    {
        $this->actingAs($this->admin)->get(route('currencies.index'))
            ->assertOk()
            ->assertDontSee(__('The books are set to :code, and there is no such currency here.', ['code' => 'IQD']));

        Currency::where('code', 'IQD')->delete();
        Currency::flushCache();

        $this->actingAs($this->admin)->get(route('currencies.index'))
            ->assertOk()
            ->assertSee(__('The books are set to :code, and there is no such currency here.', ['code' => 'IQD']));
    }

    /** A shop choosing its currency during setup needs no typing at all. */
    public function test_a_shop_with_nothing_recorded_can_choose_its_currency(): void
    {
        $this->actingAs($this->admin)
            ->post(route('currencies.base', $this->usd()))
            ->assertSessionHasNoErrors()->assertRedirect();

        Currency::flushCache();

        $this->assertSame('USD', Money::base()->code);

        // ⚠️ The new base takes the definitional rate: a dollar is one dollar.
        $this->assertSame(100 * Money::RATE_SCALE, $this->usd()->fresh()->rate);

        /*
         * And the old base is switched off with a worked-out rate. 1 IQD is
         * 1/1320 of a dollar — 0.076 of a cent-counting rate — which is a
         * starting point, not an answer, so nothing is priced with it until
         * somebody has looked.
         */
        $iqd = $this->iqd()->fresh();

        $this->assertFalse($iqd->is_active);
        $this->assertSame(76, $iqd->rate);
    }

    // ---- Decimals -------------------------------------------------------

    /**
     * ⚠️ Section 2b's redenomination, from the screen.
     *
     * Changing the base's decimals is what turns 250,000 dinars into 250. No
     * row moves and nothing is written — the stored integer stops counting
     * dinars and starts counting fils — which is exactly why it is safe to
     * offer: setting it back puts every figure where it was.
     */
    public function test_the_base_decimals_change_the_reading_and_nothing_else(): void
    {
        $before = Product::create([
            'name' => 'Cable', 'sku' => 'C9', 'unit' => 'pcs',
            'category_id' => Category::firstOrFail()->id,
            'purchase_price' => 250_000, 'sale_price' => 250_000,
        ]);

        $this->actingAs($this->admin)->put(route('currencies.update', $this->iqd()), [
            'name' => 'Iraqi Dinar',
            'symbol' => 'د.ع',
            'decimals' => 3,
        ])->assertSessionHasNoErrors()->assertRedirect();

        Currency::flushCache();

        // The stored figure has not moved one unit.
        $this->assertSame(250_000, $before->fresh()->purchase_price);

        // Only the reading of it has.
        $this->assertSame('250', Money::format(250_000));

        // And the base's rate follows the decimals it was just given.
        $this->assertSame(1_000 * Money::RATE_SCALE, $this->iqd()->fresh()->rate);
    }

    /** Setting it back puts every figure exactly where it was. */
    public function test_the_redenomination_is_reversible(): void
    {
        foreach ([3, 0] as $places) {
            $this->actingAs($this->admin)->put(route('currencies.update', $this->iqd()), [
                'name' => 'Iraqi Dinar', 'symbol' => 'د.ع', 'decimals' => $places,
            ])->assertSessionHasNoErrors();

            Currency::flushCache();
        }

        $this->assertSame('250,000', Money::format(250_000));
    }

    // ---- Removing one ---------------------------------------------------

    /** A currency nothing points at can simply go. */
    public function test_a_currency_nothing_uses_can_be_removed(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('currencies.destroy', $this->usd()))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseMissing('currencies', ['code' => 'USD']);
    }

    /**
     * ⚠️ But not one a document names.
     *
     * A purchase records the code it was invoiced in, and a code with no row
     * behind it prints as a blank on the invoice that needs it most.
     */
    public function test_a_currency_a_document_names_is_kept(): void
    {
        $this->aPurchase(currency: 'USD');

        $this->actingAs($this->admin)
            ->delete(route('currencies.destroy', $this->usd()))
            ->assertRedirect();

        $this->assertDatabaseHas('currencies', ['code' => 'USD']);
    }

    /** Nor one somebody is reading in. */
    public function test_a_currency_somebody_reads_in_is_kept(): void
    {
        User::whereKey($this->admin->getKey())->update(['display_currency' => 'USD']);

        $this->actingAs($this->admin)
            ->delete(route('currencies.destroy', $this->usd()))
            ->assertRedirect();

        $this->assertDatabaseHas('currencies', ['code' => 'USD']);
    }

    /** And never the one the books are kept in. */
    public function test_the_base_cannot_be_removed(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('currencies.destroy', $this->iqd()))
            ->assertRedirect();

        $this->assertDatabaseHas('currencies', ['code' => 'IQD']);
    }

    /** One recorded purchase, so the books are no longer empty. */
    private function aPurchase(?string $currency = null): void
    {
        $product = Product::create([
            'name' => 'Cable', 'sku' => 'C'.random_int(100, 999), 'unit' => 'pcs',
            'category_id' => Category::firstOrFail()->id,
            'purchase_price' => 1_000, 'sale_price' => 1_500,
        ]);

        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'S']),
            lines: [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 10_000,
                'entered_currency' => $currency ?? 'IQD',
                'entered_amount' => $currency ? 1_000 : null,
            ]],
            user: $this->admin,
            purchaseDate: today(),
            exchangeRate: $currency ? 1_320 : null,
        );
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
