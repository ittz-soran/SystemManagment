<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The customer and the supplier, searched rather than scrolled — PROJECT_DOC
 * Section 9, the sale/purchase screen. Soran, 2026-11-02: *"add search for
 * Customer&Supplier like "Scan or type…" input type to search and show all at
 * empty or click on it"*.
 *
 * The box itself is app.js and was driven in Chromium: every name on a click,
 * narrowed by name or phone in any digits, chosen by click or ↓ Enter, the
 * chosen one back when the box is left half-typed. What PHP can hold is that
 * the field is still the <select> the form posts, marked for the box, with
 * what the box searches on.
 */
class PartySearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    /** @return array{0: string, 1: list<string>} the select's own tag, and its options */
    private function select(string $page, string $id): array
    {
        $this->assertSame(1, preg_match('/(<select id="'.$id.'"[^>]*>)(.*?)<\/select>/s', $page, $found), "no #{$id}");
        preg_match_all('/<option[^>]*>/', $found[2], $options);

        return [$found[1], $options[0]];
    }

    public function test_the_sale_screen_searches_its_customers(): void
    {
        $karwan = Customer::create(['name' => 'Karwan Ahmed', 'phone' => '07501234567']);

        [$tag, $options] = $this->select($this->actingAs($this->admin)->get(route('sales.create'))->assertOk()->getContent(), 'customer_id');

        // Still the field the form posts, so the walk-in default, New customer
        // and everything that listens for a change go on working.
        $this->assertStringContainsString('name="customer_id"', $tag);
        $this->assertStringContainsString('data-searchable', $tag);
        $this->assertStringContainsString('data-search-placeholder="'.e(__('Search by name or phone')).'"', $tag);
        $this->assertStringContainsString('data-search-required="'.e(__('Choose a customer from the list.')).'"', $tag);
        $this->assertNotEmpty(preg_grep('/value="'.$karwan->id.'"/', $options));
        $this->assertNotEmpty(preg_grep('/data-phone="07501234567"/', $options));
    }

    public function test_the_purchase_screen_searches_its_suppliers_and_still_requires_one(): void
    {
        $supplier = Supplier::create(['name' => 'Erbil Wholesale', 'phone' => '07501230000']);

        [$tag, $options] = $this->select($this->actingAs($this->admin)->get(route('purchases.create'))->assertOk()->getContent(), 'supplier_id');

        $this->assertStringContainsString('data-searchable', $tag);
        // The box moves the requirement onto itself, where the browser can say so.
        $this->assertStringContainsString('required', $tag);
        $this->assertStringContainsString('data-search-required="'.e(__('Choose a supplier from the list.')).'"', $tag);
        $this->assertNotEmpty(preg_grep('/value="'.$supplier->id.'"/', $options));
        $this->assertNotEmpty(preg_grep('/data-phone="07501230000"/', $options));
    }

    public function test_the_behaviour_is_in_the_built_bundle(): void
    {
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $bundle = file_get_contents(public_path('build/'.$manifest['resources/js/app.js']['file']));

        $this->assertStringContainsString('select[data-searchable]', $bundle);
        $this->assertStringContainsString('app-pick-list', $bundle);
    }
}
