<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Permission;
use App\Models\Product;
use App\Models\User;
use App\Support\ProductName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Section 9 — "Help with the name of a product".
 *
 * Soran, 2026-09-27: *"i need Grammar check and spell corrector -> just have in
 * product add end edit first"*, and after trying four of them on a live page,
 * *"ok 2,3 and 4 if need 1 i let you after"*.
 *
 * The thing being prevented is not a misspelling. It is `cable sikenai 30w c to
 * ltg y2` on Monday and `Cable Sikanai 30w C to LTG Y2` on Thursday — two
 * products, two stocks, two costs, two lines in every report, and neither one
 * wrong enough for anybody to notice.
 */
class ProductNameTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name, ?string $sku = null): Product
    {
        return Product::create([
            'name' => $name,
            'sku' => $sku ?? 'SKU'.Product::count(),
            'category_id' => Category::firstOrCreate(['name' => 'C'])->id,
            'unit' => 'pcs', 'purchase_price' => 100, 'sale_price' => 200, 'quantity' => 0,
        ]);
    }

    private function admin(): User
    {
        $this->seed();

        return User::where('email', 'admin@example.com')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function advice(string $name, ?int $ignore = null): array
    {
        return $this->actingAs($this->admin())
            ->getJson(route('products.name-advice', array_filter(
                ['name' => $name, 'ignore' => $ignore],
                fn ($v) => $v !== null,
            )))
            ->assertOk()
            ->json();
    }

    // ---- 1. The name is tidied on save ----------------------------------

    public function test_the_name_he_typed_is_tidied(): void
    {
        $this->assertSame(
            'Cable Sikenai 30W C to LTG Y2',
            ProductName::tidy('cable sikenai 30w c to ltg  y2'),
        );
    }

    public function test_the_units_this_shop_writes(): void
    {
        $this->assertSame('Power Bank 20000mAh 22.5W', ProductName::tidy('power bank 20000mah 22.5w'));
        $this->assertSame('SSD 512GB', ProductName::tidy('ssd 512gb'));
        $this->assertSame('Monitor 144Hz', ProductName::tidy('monitor 144hz'));
        $this->assertSame('Adapter 12V 2A', ProductName::tidy('adapter 12v 2a'));
    }

    /**
     * ⚠️ The one that would have cost him money. Capitalising the first letter
     * of a part number corrupts it, silently, on every save.
     */
    public function test_a_part_number_is_left_exactly_as_typed(): void
    {
        foreach (['B450M-KII+R5', 'i5-10th', 'PD-17-UK', 'iPhone 14 Pro'] as $code) {
            $this->assertSame($code, ProductName::tidy($code), $code.' was changed');
        }
    }

    public function test_the_tidy_happens_when_the_product_is_saved(): void
    {
        $this->actingAs($this->admin())
            ->post(route('products.store'), [
                'name' => '  cable  sikenai 30w c to ltg y2 ',
                'category_id' => Category::firstOrCreate(['name' => 'C'])->id,
                'unit' => 'pcs', 'purchase_price' => 1000, 'sale_price' => 2000, 'is_active' => 1,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('products', ['name' => 'Cable Sikenai 30W C to LTG Y2']);
    }

    public function test_the_tidy_happens_on_an_edit_too(): void
    {
        $product = $this->product('Cable Sikenai 30W C to LTG Y2');

        $this->actingAs($this->admin())
            ->put(route('products.update', $product), [
                'name' => 'cable sikenai 20w  c to ltg y2',
                'sku' => $product->sku,
                'category_id' => $product->category_id,
                'unit' => 'pcs', 'purchase_price' => 1000, 'sale_price' => 2000, 'is_active' => 1,
            ])
            ->assertRedirect();

        $this->assertSame('Cable Sikenai 20W C to LTG Y2', $product->fresh()->name);
    }

    public function test_a_name_of_nothing_but_spaces_is_still_refused(): void
    {
        $this->actingAs($this->admin())
            ->post(route('products.store'), [
                'name' => '   ',
                'category_id' => Category::firstOrCreate(['name' => 'C'])->id,
                'unit' => 'pcs', 'purchase_price' => 1000, 'sale_price' => 2000,
            ])
            ->assertSessionHasErrors('name');
    }

    // ---- 2. The look-alike warning --------------------------------------

    public function test_the_typo_he_would_have_made_is_caught(): void
    {
        $this->product('Cable Sikenai 30W C to LTG Y2', 'SK-Y2');

        $advice = $this->advice('Cable Sikanai 30W C to LTG Y2');

        $this->assertCount(1, $advice['look_alikes']);
        $this->assertSame('Cable Sikenai 30W C to LTG Y2', $advice['look_alikes'][0]['name']);
        $this->assertSame('SK-Y2', $advice['look_alikes'][0]['sku']);
        $this->assertTrue($advice['look_alikes'][0]['certain']);
    }

    /**
     * The quieter half of the warning: close, but not close enough to say the
     * shop has typed the same product twice.
     */
    public function test_a_near_miss_is_worth_a_look_rather_than_certain(): void
    {
        $this->product('Cable Sikenai 30W C to LTG Y2');

        $advice = $this->advice('Cable Sikenai 30W C to C Y3');

        $this->assertCount(1, $advice['look_alikes']);
        $this->assertFalse($advice['look_alikes'][0]['certain']);
    }

    public function test_the_same_words_in_a_different_order_are_caught(): void
    {
        $this->product('Wireless Mouse Logitech M170');

        $advice = $this->advice('Logitech M170 Wireless Mouse');

        $this->assertCount(1, $advice['look_alikes']);
    }

    public function test_a_product_it_has_never_seen_is_not_flagged(): void
    {
        $this->product('Cable Sikenai 30W C to LTG Y2');

        $this->assertSame([], $this->advice('Xiaomi Redmi Note 13 Screen')['look_alikes']);
    }

    /**
     * ⚠️ **It advises and it never blocks.** A 20W and a 30W of one cable
     * differ by a single character, and they are two real products. The warning
     * appears — that is correct — and the save goes through anyway.
     */
    public function test_a_warning_never_stops_the_save(): void
    {
        $this->product('Cable Sikenai 30W C to LTG Y2');

        $this->assertNotEmpty($this->advice('Cable Sikenai 20W C to LTG Y2')['look_alikes']);

        $this->actingAs($this->admin())
            ->post(route('products.store'), [
                'name' => 'Cable Sikenai 20W C to LTG Y2',
                'category_id' => Category::firstOrCreate(['name' => 'C'])->id,
                'unit' => 'pcs', 'purchase_price' => 1000, 'sale_price' => 2000, 'is_active' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['name' => 'Cable Sikenai 20W C to LTG Y2']);
    }

    /** A product being edited is not a look-alike of itself. */
    public function test_editing_a_product_does_not_warn_about_itself(): void
    {
        $product = $this->product('Cable Sikenai 30W C to LTG Y2');

        $this->assertNotEmpty($this->advice($product->name)['look_alikes']);
        $this->assertSame([], $this->advice($product->name, $product->id)['look_alikes']);
    }

    public function test_at_most_three_look_alikes_come_back(): void
    {
        foreach (range(1, 6) as $n) {
            $this->product("Cable Sikenai 30W C to LTG Y{$n}", "SK-Y{$n}");
        }

        $this->assertCount(3, $this->advice('Cable Sikenai 30W C to LTG Y9')['look_alikes']);
    }

    // ---- 3. The shop's own catalogue is the dictionary -------------------

    public function test_a_word_the_shop_already_uses_is_offered(): void
    {
        $this->product('Wireless Mouse Logitech M170');
        $this->product('Wireless Keyboard Logitech K380');

        $advice = $this->advice('Wirless Charger Baseus 15');

        $this->assertCount(1, $advice['spellings']);
        $this->assertSame('Wirless', $advice['spellings'][0]['typed']);
        $this->assertSame('Wireless', $advice['spellings'][0]['suggested']);
        $this->assertSame(2, $advice['spellings'][0]['seen']);
    }

    /**
     * ⚠️ **This is the decision the feature rests on.** The dictionary is his
     * catalogue, not English — which is why a brand he sells is never
     * questioned, and why the browser's own spellchecker was turned down.
     */
    public function test_a_brand_the_shop_sells_is_never_questioned(): void
    {
        $this->product('Cable Sikenai 30W C to LTG Y2');
        $this->product('Cable Sikenai 20W C to C Y3');

        $this->assertSame([], $this->advice('Charger Sikenai 45W Wall')['spellings']);
    }

    /**
     * ⚠️ One product is not a vocabulary. A brand entered once, with its own
     * typo, would otherwise become the spelling everything after it is
     * corrected TO — the fault teaching itself.
     */
    public function test_a_word_seen_in_only_one_product_is_not_offered(): void
    {
        $this->product('Wireless Mouse Logitech M170');

        $this->assertSame([], $this->advice('Wirless Charger Baseus 15')['spellings']);
    }

    /**
     * ⚠️ Under five letters nearly every word is one or two edits from another,
     * so the shop would be second-guessed on words it spelled correctly.
     */
    public function test_a_short_word_is_never_corrected(): void
    {
        $this->product('Case Samsung A54 Clear');
        $this->product('Case Samsung A55 Black');

        // "Cose" is one letter from "Case", which the shop uses twice — and is
        // still left alone, because four letters is not enough to be sure.
        $this->assertSame([], $this->advice('Cose Xiaomi Redmi Clear')['spellings']);

        // Proof the floor is doing the work and not the dictionary: the same
        // mistake in a longer word IS caught.
        $this->product('Samsung Galaxy Charger');

        $spellings = $this->advice('Sumsung Redmi Cover Clear')['spellings'];

        $this->assertCount(1, $spellings);
        $this->assertSame('Samsung', $spellings[0]['suggested']);
    }

    /**
     * ⚠️ A word carrying a digit is a code, and the button offered for it could
     * not work anyway — a suggestion replaces the whole word, and `Sikanai2` is
     * not the word `Sikanai`.
     */
    public function test_a_word_with_a_digit_in_it_is_never_corrected(): void
    {
        $this->product('Cable Sikenai 30W C to LTG Y2');
        $this->product('Cable Sikenai 20W C to C Y3');

        $this->assertSame([], $this->advice('Cable Sikanai2 45W')['spellings']);

        // The same misspelling without the digit is caught, so it is the digit
        // doing the work here.
        $spellings = $this->advice('Cable Sikanai 45W')['spellings'];

        $this->assertCount(1, $spellings);
        $this->assertSame('Sikenai', $spellings[0]['suggested']);
    }

    public function test_a_product_being_edited_does_not_confirm_its_own_typo(): void
    {
        $wrong = $this->product('Wirless Charger Baseus 15');
        $this->product('Wirless Charger Baseus 20');
        $this->product('Wireless Mouse Logitech M170');
        $this->product('Wireless Keyboard Logitech K380');

        // Left in, its own spelling is in the dictionary twice and confirms
        // itself. Left out, the shop's other four products win.
        $this->assertSame([], $this->advice($wrong->name)['spellings']);

        $spellings = $this->advice($wrong->name, $wrong->id)['spellings'];

        $this->assertCount(1, $spellings);
        $this->assertSame('Wireless', $spellings[0]['suggested']);
    }

    // ---- The endpoint itself --------------------------------------------

    public function test_an_empty_name_is_answered_with_nothing(): void
    {
        $this->product('Cable Sikenai 30W C to LTG Y2');

        $advice = $this->advice('');

        $this->assertSame([], $advice['look_alikes']);
        $this->assertSame([], $advice['spellings']);
    }

    public function test_only_somebody_who_may_write_a_product_may_ask(): void
    {
        $this->seed();

        // Somebody who may look at the catalogue but not write to it.
        $viewer = User::create([
            'name' => 'Shop Assistant', 'email' => 'assistant@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);

        $viewer->permissions()->sync(Permission::where('key', 'products.view')->pluck('id')->all());

        $this->actingAs($viewer)
            ->getJson(route('products.name-advice', ['name' => 'Cable']))
            ->assertForbidden();
    }

    public function test_the_form_carries_the_advice_box(): void
    {
        $this->actingAs($this->admin())
            ->get(route('products.create'))
            ->assertOk()
            ->assertSee('id="name-advice"', false)
            ->assertSee(route('products.name-advice'), false);
    }

    // ---- 4. The browser's own spellchecker -------------------------------

    /**
     * Soran, 2026-09-27: *"Option 1"* — the one he had held back.
     *
     * ⚠️ Off for the whole shop and on for the one field that earns it. Every
     * other screen is full of SKUs, barcodes, IMEIs, document numbers, phone
     * numbers and people's names, and a red line under all of them teaches the
     * reader to ignore red lines.
     */
    public function test_the_shop_starts_with_the_spellchecker_off(): void
    {
        foreach (['sales.create', 'customers.index', 'products.index'] as $screen) {
            $page = $this->actingAs($this->admin())->get(route($screen))->assertOk()->getContent();

            $this->assertStringContainsString('<body class="bg-body-tertiary" spellcheck="false"', $page, $screen);
        }
    }

    public function test_the_product_name_switches_it_back_on(): void
    {
        $product = $this->product('Cable Sikenai 30W C to LTG Y2');

        foreach ([route('products.create'), route('products.edit', $product)] as $url) {
            $page = $this->actingAs($this->admin())->get($url)->assertOk()->getContent();

            $this->assertMatchesRegularExpression(
                '/<input id="name"[^>]*\sspellcheck="true"/',
                $page,
                $url,
            );

            // And nowhere else on the form: a part number is not a misspelling.
            $this->assertSame(1, substr_count($page, 'spellcheck="true"'), $url);
        }
    }

    /** On an edit the box knows which product to leave out of its own advice. */
    public function test_the_edit_form_tells_the_advice_which_product_it_is(): void
    {
        $product = $this->product('Cable Sikenai 30W C to LTG Y2');

        $this->actingAs($this->admin())
            ->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('data-ignore="'.$product->id.'"', false);
    }
}
