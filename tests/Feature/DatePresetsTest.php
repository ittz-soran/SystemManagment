<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Today, this month, last month, this year — asked for by Soran, 2026-09-12.
 */
class DatePresetsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed();

        return User::where('email', 'admin@example.com')->firstOrFail();
    }

    public function test_every_dated_list_offers_them(): void
    {
        $admin = $this->admin();

        foreach (['sales', 'purchases', 'sale-returns', 'purchase-returns', 'payments', 'expenses'] as $list) {
            $this->actingAs($admin)->get(route($list.'.index'))
                ->assertOk()
                ->assertSee(__('This month'))
                ->assertSee(__('Last month'));
        }
    }

    /**
     * ⚠️ A preset carries the rest of the query with it.
     *
     * Somebody who has narrowed to one customer and then presses "This month"
     * means *this month, for that customer*. Dropping the customer would answer
     * a question they did not ask, and quietly show them the whole shop.
     */
    public function test_a_preset_keeps_the_filters_already_set(): void
    {
        $page = $this->actingAs($this->admin())
            ->get(route('sales.index', ['customer_id' => 1, 'status' => 'active']))
            ->assertOk();

        $month = today()->startOfMonth()->toDateString();

        $this->assertMatchesRegularExpression(
            '/href="[^"]*customer_id=1[^"]*from='.preg_quote($month, '/').'/',
            $page->getContent(),
            'A preset dropped the filters already on the page.',
        );
    }

    /** The one in force is marked, so the list says which range it is showing. */
    public function test_the_range_in_force_is_marked(): void
    {
        $page = $this->actingAs($this->admin())->get(route('sales.index', [
            'from' => today()->toDateString(),
            'to' => today()->toDateString(),
        ]))->assertOk();

        $this->assertStringContainsString('aria-current="true"', $page->getContent());
    }

    /** Clearing back to every date is offered only once a range is set. */
    public function test_any_date_appears_only_when_a_range_is_on(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('sales.index'))
            ->assertDontSee(__('Any date'));

        $this->actingAs($admin)->get(route('sales.index', ['from' => today()->toDateString()]))
            ->assertSee(__('Any date'));
    }
}
