<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Typing an amount through a lens — Section 2b, the entry half.
 *
 * ⚠️ **The test this whole file exists for is
 * `test_saving_a_form_nobody_edited_changes_nothing`.** Rounding does not
 * survive a round trip: 10,000 dinars shown at 1,320 is $7.58, which converts
 * back to 10,006. A form that converted every field it was handed would rewrite
 * each one on every save — a few units at a time, each still looking plausible,
 * until a supplier's total no longer matches the paperwork.
 */
class MoneyEntryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->category = ExpenseCategory::firstOrFail();
    }

    private function looking(string $code): User
    {
        User::whereKey($this->admin->getKey())->update(['display_currency' => $code]);

        return User::findOrFail($this->admin->getKey());
    }

    private function reader(): User
    {
        return User::findOrFail($this->admin->getKey());
    }

    /** @param array<string, mixed> $extra */
    private function form(array $extra = []): array
    {
        return [
            'title' => 'Rent',
            'expense_category_id' => $this->category->id,
            'expense_date' => today()->toDateString(),
            ...$extra,
        ];
    }

    private function anExpense(int $amount): Expense
    {
        return Expense::create([
            ...$this->form(),
            'amount' => $amount,
            'user_id' => $this->admin->id,
            'document_no' => 'EXP-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        ]);
    }

    // -------------------------------------------------------------- creating

    public function test_an_amount_typed_in_dinars_is_stored_as_typed(): void
    {
        $this->actingAs($this->reader())
            ->post(route('expenses.store'), $this->form(['amount' => '250000']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(250_000, Expense::latest('id')->firstOrFail()->amount);
    }

    /** ⚠️ $500 typed on a dollar screen is 660,000 dinars in the books. */
    public function test_an_amount_typed_in_dollars_is_stored_in_dinars(): void
    {
        $this->actingAs($this->looking('USD'))
            ->post(route('expenses.store'), $this->form(['amount' => '500']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(660_000, Expense::latest('id')->firstOrFail()->amount);
    }

    /** Cents are the reason the entry half needed decimals at all. */
    public function test_cents_are_not_thrown_away(): void
    {
        $this->actingAs($this->looking('USD'))
            ->post(route('expenses.store'), $this->form(['amount' => '8.33']))
            ->assertRedirect()->assertSessionHasNoErrors();

        // §6b's own worked example: 8.33 × 1,320 = 10,995.6, to the dinar.
        $this->assertSame(10_996, Expense::latest('id')->firstOrFail()->amount);
    }

    /**
     * ⚠️ A decimal must not be refused just because the base has none.
     *
     * `integer` validation would throw `12.50` out of a dollar box, which is
     * why the rule is App\Rules\Amount rather than `integer`.
     */
    public function test_a_decimal_is_accepted_from_a_currency_that_has_them(): void
    {
        $this->actingAs($this->looking('USD'))
            ->post(route('expenses.store'), $this->form(['amount' => '12.50']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(16_500, Expense::latest('id')->firstOrFail()->amount);
    }

    // ---------------------------------------------------------- the RULE

    /**
     * ⚠️ **The one that matters.** Open a record through a lens, change
     * nothing, save — and the stored figure must be untouched.
     *
     * 10,000 dinars is $7.5757…, written $7.58, which converts back to 10,006.
     * Without the untouched-field rule this test finds six dinars that nobody
     * typed, on a form nobody edited.
     */
    public function test_saving_a_form_nobody_edited_changes_nothing(): void
    {
        $expense = $this->anExpense(10_000);

        // What the box is drawn holding, and posted straight back.
        $shown = '7.58';

        $this->actingAs($this->looking('USD'))
            ->put(route('expenses.update', $expense), $this->form([
                'amount' => $shown,
                'amount_shown' => $shown,
            ]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(10_000, $expense->fresh()->amount,
            'A save that touched nothing rewrote the amount by a rounding.');
    }

    /** And a field somebody DID edit is converted, as it must be. */
    public function test_a_field_that_was_edited_is_converted(): void
    {
        $expense = $this->anExpense(10_000);

        $this->actingAs($this->looking('USD'))
            ->put(route('expenses.update', $expense), $this->form([
                'amount' => '20',
                'amount_shown' => '7.58',
            ]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(26_400, $expense->fresh()->amount);
    }

    /**
     * Retyping the same number over itself is not an edit.
     *
     * Somebody who types `1,200` over a box reading `1200` has changed nothing
     * and must not be charged a rounding for the separator.
     */
    /**
     * Retyping the same number over itself is not an edit.
     *
     * Somebody who types `7575.76` over a box reading `7,575.76` has changed
     * nothing and must not be charged a rounding for the separator.
     *
     * ⚠️ 10,000,000 on purpose: it shows as `7,575.76` — which has a separator
     * AND does not survive the round trip, coming back as 10,000,003. A figure
     * that converted cleanly would let this test pass with the rule removed,
     * which is what the first version of it did.
     */
    public function test_a_separator_typed_over_the_same_figure_is_not_an_edit(): void
    {
        $expense = $this->anExpense(10_000_000);

        $this->actingAs($this->looking('USD'))
            ->put(route('expenses.update', $expense), $this->form([
                'amount' => '7575.76',
                'amount_shown' => '7,575.76',
            ]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(10_000_000, $expense->fresh()->amount);
    }

    /** With no lens there is nothing to keep untouched — the typed figure wins. */
    public function test_without_a_lens_the_typed_figure_is_simply_stored(): void
    {
        $expense = $this->anExpense(10_000);

        $this->actingAs($this->reader())
            ->put(route('expenses.update', $expense), $this->form(['amount' => '12345']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(12_345, $expense->fresh()->amount);
    }

    // ------------------------------------------------------------ refusals

    public function test_something_that_is_not_a_number_is_refused(): void
    {
        $this->actingAs($this->looking('USD'))
            ->post(route('expenses.store'), $this->form(['amount' => 'soon']))
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Expense::count());
    }

    /** The floor is in dinars, and is reported in the currency being typed. */
    public function test_an_amount_below_the_floor_is_refused(): void
    {
        $this->actingAs($this->reader())
            ->post(route('expenses.store'), $this->form(['amount' => '0']))
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Expense::count());
    }

    // -------------------------------------------------------------- the form

    /** The box says which currency it is taking, and takes that precision. */
    public function test_the_box_names_its_currency_and_takes_its_decimals(): void
    {
        $this->actingAs($this->reader())->get(route('expenses.index'))
            ->assertOk()
            ->assertSee('step="1"', false);

        $this->actingAs($this->looking('USD'))->get(route('expenses.index'))
            ->assertOk()
            ->assertSee('step="0.01"', false)
            ->assertSee('name="amount_shown"', false);
    }

    /** A currency switched off takes the entry boxes back to dinars too. */
    public function test_switching_the_currency_off_returns_the_boxes_to_dinars(): void
    {
        $user = $this->looking('USD');
        Currency::where('code', 'USD')->firstOrFail()->update(['is_active' => false]);
        Currency::flushCache();

        $this->actingAs($user->fresh())
            ->post(route('expenses.store'), $this->form(['amount' => '500']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(500, Expense::latest('id')->firstOrFail()->amount);
    }
}
