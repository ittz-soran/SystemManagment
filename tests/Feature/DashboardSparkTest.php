<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The line behind each tile's figure — asked for by Soran, 2026-09-12.
 *
 * What is worth testing here is not the drawing. It is that the line is behind
 * the same wall as the number it sits under: **a chart is data too**, and the
 * shape of somebody's purchasing is worth withholding from a reader kept out of
 * the purchases screen. A picture that leaks what the figure withholds is the
 * figure not withheld.
 */
class DashboardSparkTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_gets_a_line_under_the_figures(): void
    {
        $this->seed();

        $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('app-spark', false);
    }

    /**
     * @param  list<string>  $keys
     */
    private function staff(array $keys, string $visibility = User::COST_REAL, string $email = 'till@example.com'): User
    {
        $user = User::create([
            'name' => 'Till', 'email' => $email,
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
            'cost_visibility' => $visibility, 'cost_markup_percent' => 0,
        ]);

        $user->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id')->all());

        return $user;
    }

    /**
     * ⚠️ A reader kept out of a screen gets no line for it either.
     *
     * The tile already withholds the figure. Before this test the line was free
     * to draw the same information as a shape.
     */
    public function test_a_reader_without_the_permission_gets_no_line_for_it(): void
    {
        $this->seed();

        $page = $this->actingAs($this->staff(['dashboard.view', 'sales.view']))->get(route('dashboard'))->assertOk();

        // One line — sales — and nothing for purchases, expenses or the shelf.
        $this->assertSame(
            1,
            substr_count($page->getContent(), 'class="app-spark"'),
            'A tile whose figure is withheld drew its shape anyway.',
        );
    }

    /**
     * ⚠️ And a reader shown no cost gets no shelf line, for the same reason.
     *
     * Section 2 lets a shop hide cost from a counter assistant. `cost_seen()`
     * returns null for them, and a line drawn from nulls would be a picture
     * made out of the figures being hidden.
     */
    public function test_the_stock_line_follows_the_cost_setting_not_only_the_permission(): void
    {
        $this->seed();

        /*
         * A NON-admin: Section 2 says an admin always sees the real cost and
         * cannot be restricted, so setting the column on one changes nothing.
         * The first version of this test did exactly that and failed for the
         * right reason — it was testing the wrong person.
         */
        $keys = ['dashboard.view', 'sales.view', 'reports.view'];

        $shown = $this->staff($keys, User::COST_REAL, 'sees@example.com');
        $hidden = $this->staff($keys, User::COST_HIDDEN, 'blind@example.com');

        $withCost = substr_count(
            $this->actingAs($shown)->get(route('dashboard'))->getContent(), 'class="app-spark"'
        );
        $withoutCost = substr_count(
            $this->actingAs($hidden)->get(route('dashboard'))->getContent(), 'class="app-spark"'
        );

        $this->assertGreaterThan(0, $withCost);
        $this->assertLessThan($withCost, $withoutCost,
            'Hiding cost left the shelf drawn from the very figures being hidden.');
    }
}
