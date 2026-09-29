<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Support\ProductName;
use App\Support\StarterWords;
use App\Support\WordList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finishing the word as you type — Section 9.
 *
 * Soran, 2026-09-27: *"how add words sugetions for ex i type "monit" auto show
 * "Monitor" click or tab to replase it, this is very importnat"*. He tried it
 * on a page first and chose the letters in the box plus the row underneath,
 * the word rather than the whole name, and the other name boxes as well.
 *
 * ⚠️ The keys — Tab, →, ↓, Esc, and Enter doing nothing — are behaviour no
 * markup can prove. They were driven in a browser; this holds the wiring and
 * the word list, which is what can go silently wrong.
 */
class WordHelpTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed();

        return User::where('email', 'admin@example.com')->firstOrFail();
    }

    private function product(string $name): Product
    {
        return Product::create([
            'name' => $name, 'sku' => 'SKU'.Product::count(),
            'category_id' => Category::firstOrCreate(['name' => 'C'])->id,
            'unit' => 'pcs', 'purchase_price' => 100, 'sale_price' => 200, 'quantity' => 0,
        ]);
    }

    // ---- the list itself -------------------------------------------------

    public function test_it_is_the_words_of_the_shop_with_their_counts(): void
    {
        $this->seed();
        $this->product('Monitor MSI GF244 24" 180Hz');
        $this->product('Monitor Samsung 27"');

        $words = collect(WordList::for('products'))->keyBy('w');

        $this->assertSame(2, $words['Monitor']['n']);
        $this->assertSame(1, $words['MSI']['n']);

        // Its own capitals, not a tidied guess at them.
        $this->assertTrue($words->has('GF244'));
        $this->assertTrue($words->has('180Hz'));
    }

    /**
     * ⚠️ Commonest first, then shortest, then alphabetical. Determinate on
     * purpose — the same three letters must offer the same word tomorrow, or
     * the shop learns not to trust the first suggestion.
     */
    public function test_the_commonest_word_comes_first(): void
    {
        $this->seed();
        $this->product('Cable Sikenai 30W C to LTG');
        $this->product('Cable Sikenai 20W C to C');
        $this->product('Charger Sikenai 45W PD');

        $order = array_column(WordList::for('products'), 'w');

        $this->assertLessThan(
            array_search('Charger', $order, true),
            array_search('Cable', $order, true),
            'Cable is used twice and Charger once',
        );
    }

    /**
     * ⚠️ **THE ONE THAT KILLED EVERY BOX.** PHP turns an all-digit array key
     * into an integer, so `5500` out of *MotherBord B450M-KII+R5 5500* reached
     * the page as the number 5500. The first `w.toLowerCase()` in the browser
     * threw, and every suggestion on every screen went silent — while the word
     * list on the page looked entirely correct.
     */
    public function test_a_word_that_is_all_digits_is_still_a_string(): void
    {
        $this->seed();
        $this->product('Bundle Asus MotherBord B450M-KII+R5 5500');
        $this->product('Flash Sandisk 128GB 2.0');

        foreach (WordList::for('products') as $word) {
            $this->assertIsString($word['w'], 'a word came back as '.get_debug_type($word['w']));
        }

        $this->assertContains('5500', array_column(WordList::for('products'), 'w'));
    }

    /** One letter is not a word; at one character every box offers the alphabet. */
    public function test_a_single_letter_is_never_offered(): void
    {
        $this->seed();
        $this->product('Cable Sikenai 30W C to LTG');

        $this->assertNotContains('C', array_column(WordList::for('products'), 'w'));
        $this->assertContains('to', array_column(WordList::for('products'), 'w'));
    }

    /** ⚠️ Every box learns from its own column, and they never mix. */
    public function test_each_box_learns_from_its_own_column(): void
    {
        $this->seed();
        $this->product('Monitor MSI GF244');
        Customer::create(['name' => 'Karwan Ahmed']);
        Supplier::create(['name' => 'Bazaar Mobile']);

        $products = array_column(WordList::for('products'), 'w');
        $customers = array_column(WordList::for('customers'), 'w');
        $suppliers = array_column(WordList::for('suppliers'), 'w');

        $this->assertContains('Monitor', $products);
        $this->assertNotContains('Karwan', $products);
        $this->assertNotContains('Monitor', $customers);
        $this->assertContains('Karwan', $customers);
        $this->assertContains('Bazaar', $suppliers);
        $this->assertNotContains('Karwan', $suppliers);
    }

    public function test_the_product_being_edited_is_left_out_of_its_own_words(): void
    {
        $this->seed();
        $product = $this->product('Cooler Sivpuls Magnetic');
        $this->product('Monitor MSI GF244');

        $this->assertContains('Sivpuls', array_column(WordList::for('products'), 'w'));
        $this->assertNotContains('Sivpuls', array_column(WordList::for('products', $product->id), 'w'));
    }

    public function test_a_kind_nobody_defined_is_refused_rather_than_silently_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WordList::for('invoices');
    }

    // ---- the wiring on the page -----------------------------------------

    /**
     * ⚠️ **The page hands the list OUT; it never calls into app.js.** The cart
     * leave-guard was written the other way round and silently did nothing,
     * because app.js is a deferred module and a page's inline script runs
     * first. This asserts the shape that cannot get that wrong.
     */
    public function test_the_product_form_carries_its_words_and_the_hooks(): void
    {
        $this->product('Monitor MSI GF244 24" 180Hz');

        $page = $this->actingAs($this->admin())->get(route('products.create'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input id="name"[^>]*\sdata-word-help="words-name"/', $page);
        $this->assertStringContainsString('<script type="application/json" id="words-name">', $page);
        $this->assertStringContainsString('id="row-name"', $page);
        $this->assertStringContainsString('GF244', $page);
    }

    /**
     * ⚠️ **THE ONE A BROWSER CAUGHT AND THE FIRST TEST DID NOT.** The block was
     * written with Blade's `@json`, which escapes quotes for HTML and so turned
     * the JSON's own quotes into `\u0022`. The tag was on the page, the word was
     * on the page, every assertion passed — and `JSON.parse` threw, so every box
     * was silent. Asserting the tag exists proves nothing; it has to parse.
     */
    public function test_the_words_on_the_page_are_valid_json(): void
    {
        $this->product('Monitor MSI GF244 24" 180Hz');

        $page = $this->actingAs($this->admin())->get(route('products.create'))->assertOk()->getContent();

        preg_match('~<script type="application/json" id="words-name">(.*?)</script>~s', $page, $found);

        $this->assertNotEmpty($found, 'the word list is not on the page at all');

        $list = json_decode($found[1], true, 512, JSON_THROW_ON_ERROR);

        $this->assertContains('Monitor', array_column($list['w'], 'w'));
        $this->assertContains('180Hz', array_column($list['w'], 'w'));
        $this->assertArrayHasKey('after', $list);
    }

    /** A name that could close the block early must not be able to. */
    public function test_a_name_cannot_break_out_of_the_block(): void
    {
        $this->product('Screen </script><script>alert(1)</script> Guard');

        $page = $this->actingAs($this->admin())->get(route('products.create'))->assertOk()->getContent();

        preg_match('~<script type="application/json" id="words-name">(.*?)</script>~s', $page, $found);

        $list = json_decode($found[1], true, 512, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('<script>alert', $page);
        $this->assertContains('Screen', array_column($list['w'], 'w'));
    }

    public function test_the_words_reach_the_other_name_boxes_too(): void
    {
        $this->product('Monitor MSI GF244');
        Customer::create(['name' => 'Karwan Ahmed']);
        Supplier::create(['name' => 'Bazaar Mobile']);

        foreach ([
            ['customers.index', 'words-customer-name', 'Karwan'],
            ['suppliers.index', 'words-supplier-name', 'Bazaar'],
            ['expenses.index', 'words-expense-title', null],
            ['repairs.create', 'words-device', null],
        ] as [$screen, $id, $word]) {
            $page = $this->actingAs($this->admin())->get(route($screen))->assertOk()->getContent();

            $this->assertStringContainsString('id="row-'.substr($id, 6).'"', $page, $screen);

            if ($word !== null) {
                $this->assertStringContainsString('<script type="application/json" id="'.$id.'">', $page, $screen);
                $this->assertStringContainsString($word, $page, $screen);
            }
        }
    }

    /**
     * A box with nothing to offer sends nothing — and the row is still drawn,
     * because it is what stops the form jumping when the first word appears.
     *
     * Suppliers are the honest case: a new shop has none, and there is no
     * starter list of company names to fall back on.
     */
    public function test_a_box_with_nothing_to_offer_sends_no_list(): void
    {
        $page = $this->actingAs($this->admin())->get(route('suppliers.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="words-supplier-name"', $page);
        $this->assertStringContainsString('id="row-supplier-name"', $page);
    }

    // ---- fuller, and cleverer about what it offers -----------------------

    /**
     * ⚠️ **THE SUITE CAUGHT THIS ONE.** `Supplier` holds walk-in sellers as
     * well as companies, and the supplier screen deliberately shows only
     * companies — so reading the whole table handed the names of people who
     * sold the shop a second-hand phone to the page that exists precisely not
     * to show them. A word list inherits its screen's scope.
     */
    public function test_a_walk_in_seller_is_not_in_the_supplier_words(): void
    {
        $this->seed();
        Supplier::create(['name' => 'Bazaar Mobile']);
        Supplier::create(['name' => 'Nyaz Abdullah', 'is_walk_in' => true]);

        $words = array_column(WordList::for('suppliers'), 'w');

        $this->assertContains('Bazaar', $words);
        $this->assertNotContains('Nyaz', $words);
    }

    /** The shop's own words for its shelves, one table away and unused. */
    public function test_the_product_words_include_the_category_names(): void
    {
        $this->seed();
        Category::firstOrCreate(['name' => 'Second-hand Phones']);

        $this->assertContains('Second-hand', array_column(WordList::for('products'), 'w'));
    }

    /**
     * Soran, 2026-09-29: *"make Full of words Dictionary to more smartest"*. A
     * shop set up this morning has written nothing, and that is exactly when
     * the help is worth most.
     */
    public function test_a_brand_new_shop_still_has_the_trade_s_words(): void
    {
        $this->seed();

        $words = array_column(WordList::for('products'), 'w');

        $this->assertContains('Charger', $words);
        $this->assertContains('Wireless', $words);
        $this->assertContains('Type-C', $words);

        $this->assertContains('PlayStation', array_column(WordList::for('devices'), 'w'));
        $this->assertContains('Electricity', array_column(WordList::for('expenses'), 'w'));
    }

    /**
     * ⚠️ There is no such thing as a common person's name, so neither box gets
     * a starter list. A customer box on a new shop holds only what the shop
     * itself has: the Cash Customer account.
     */
    public function test_nobody_is_offered_a_starter_customer_or_supplier_name(): void
    {
        $this->seed();

        $this->assertSame([], StarterWords::for('customers'));
        $this->assertSame([], StarterWords::for('suppliers'));

        $this->assertSame([], WordList::for('suppliers'));
        $this->assertSame(['Cash', 'Customer'], array_column(WordList::for('customers'), 'w'));
    }

    /**
     * ⚠️ **The shop outranks the starter list, always** — its own casing, its
     * own count, and never the same word twice.
     */
    public function test_the_shop_s_own_word_beats_the_starter_one(): void
    {
        $this->seed();
        $this->product('CHARGER Sikenai 30W PD');
        $this->product('CHARGER Anker 20W');

        $words = array_column(WordList::for('products'), 'n', 'w');

        $this->assertArrayHasKey('CHARGER', $words);
        $this->assertArrayNotHasKey('Charger', $words);
        $this->assertSame(2, $words['CHARGER']);

        // And everything the shop has written sorts above everything it has not.
        $order = array_column(WordList::for('products'), 'w');
        $this->assertLessThan(
            array_search('Wireless', $order, true),
            array_search('CHARGER', $order, true),
        );
    }

    /**
     * ⚠️ **The starter list offers and never corrects.** Section 9's rule is
     * untouched: the shop's catalogue is the only authority on what a word IS.
     */
    public function test_a_starter_word_never_corrects_a_spelling_or_a_casing(): void
    {
        $this->seed();

        /*
         * `Type-C` is in the starter list, and it is the discriminating case:
         * the built-in rules leave `type-c` alone because it carries
         * punctuation, so if the starter list were reaching the tidy this
         * would come back `Type-C`. Only a word the SHOP has written may
         * change a casing — that is Section 9's rule and this is what holds it.
         */
        $this->assertSame('Cable type-c 60W', ProductName::tidy('cable type-c 60w'));

        // `Wireless` is in the starter list too, and is never offered as a
        // correction for a word near it.
        $this->assertSame([], ProductName::spellingsItKnows('Wirless Charger Baseus')->all());
    }

    /** `Cable ` then `sik` — the shop has written that pair before. */
    public function test_it_knows_which_word_follows_which(): void
    {
        $this->seed();
        $this->product('Cable Sikenai 30W C to LTG');
        $this->product('Cable Sikenai 20W C to C');
        $this->product('Charger Sivpuls Magnetic');

        $after = WordList::pairs('products');

        $this->assertContains('sikenai', $after['cable']);
    }

    /** ⚠️ A pair written once is not a habit; only twice or more is kept. */
    public function test_a_pair_seen_once_is_not_a_habit(): void
    {
        $this->seed();
        $this->product('Cable Sikenai 30W');
        $this->product('Holder Sivpuls Magnetic');

        $this->assertArrayNotHasKey('holder', WordList::pairs('products'));
    }

    // ---- it keeps itself, while the shop works ---------------------------

    /**
     * Soran, 2026-09-29: *"while users work in system automatically updated
     * dictionary to more comprehensive and clean"*.
     *
     * Nothing is ever typed into a dictionary — it is worked out from what the
     * shop has saved, so a product written now is in the box on the next
     * screen, and a product removed takes its words with it.
     */
    public function test_a_word_arrives_and_leaves_with_the_product(): void
    {
        $this->seed();

        $this->assertNotContains('Sivpuls', array_column(WordList::for('products'), 'w'));

        $product = $this->product('Cooler Sivpuls Magnetic');
        $this->assertContains('Sivpuls', array_column(WordList::for('products'), 'w'));

        $product->delete();
        $this->assertNotContains('Sivpuls', array_column(WordList::for('products'), 'w'));
    }

    /**
     * ⚠️ **THE ONE HIS OWN CATALOGUE NEEDED.** *Wirless* is typed once, in
     * *Earphone Joyroom True Wirless JR-T03S Pro*, beside *Wireless* in two
     * other products. Offering both is how a dictionary built from real typing
     * goes bad: the slip gets completed, saved again, and becomes a word.
     */
    public function test_a_word_typed_once_beside_a_word_typed_often_is_a_slip(): void
    {
        $this->seed();
        $this->product('Earphone Joyroom True Wirless JR-T03S Pro');
        $this->product('Mic Wireless Hoco Dual');
        $this->product('Mouse Rapoo Wireless M10');

        $words = array_column(WordList::for('products'), 'w');

        $this->assertContains('Wireless', $words);
        $this->assertNotContains('Wirless', $words);
    }

    /** ⚠️ **Nothing stored changes.** The slip stays on the product it is on. */
    public function test_cleaning_the_dictionary_never_touches_a_saved_name(): void
    {
        $this->seed();
        $product = $this->product('Earphone Joyroom True Wirless JR-T03S Pro');
        $this->product('Mic Wireless Hoco Dual');
        $this->product('Mouse Rapoo Wireless M10');

        WordList::for('products');

        $this->assertSame('Earphone Joyroom True Wirless JR-T03S Pro', $product->fresh()->name);
    }

    /** Twice is a habit, not a slip — the shop meant it. */
    public function test_a_word_typed_twice_is_never_treated_as_a_slip(): void
    {
        $this->seed();
        $this->product('Earphone Joyroom Wirless One');
        $this->product('Earphone Joyroom Wirless Two');
        $this->product('Mic Wireless Hoco Dual');
        $this->product('Mouse Rapoo Wireless M10');

        $this->assertContains('Wirless', array_column(WordList::for('products'), 'w'));
    }

    /**
     * ⚠️ A code is never a misspelling of another code. `GF244` and `GF245`
     * are one character apart and are two different monitors.
     */
    public function test_a_model_code_is_never_treated_as_a_slip(): void
    {
        $this->seed();
        $this->product('Monitor MSI GF244 24"');
        $this->product('Monitor MSI GF245 27"');
        $this->product('Monitor MSI GF245 32"');

        $words = array_column(WordList::for('products'), 'w');

        $this->assertContains('GF244', $words);
        $this->assertContains('GF245', $words);
    }

    /**
     * Two letters apart is a different word, not a slip.
     *
     * ⚠️ `Magnatik` is exactly two from `Magnetic` — measured, not guessed. An
     * earlier version of this test used a word three apart, which no widening
     * of the rule could have caught, so it proved nothing about the boundary.
     */
    public function test_two_letters_apart_is_a_different_word(): void
    {
        $this->seed();
        $this->product('Holder Magnetic Baseus');
        $this->product('Holder Magnetic Hoco');
        $this->product('Case Magnatik Xiaomi');

        $this->assertSame(2, levenshtein('magnatik', 'magnetic'), 'the example stopped being two apart');
        $this->assertContains('Magnatik', array_column(WordList::for('products'), 'w'));
    }

    /** A curated starter word is trusted enough to catch a slip on its own. */
    public function test_a_starter_word_can_catch_a_slip(): void
    {
        $this->seed();
        $this->product('Cable Wirless Charging Pad');

        $words = array_column(WordList::for('products'), 'w');

        $this->assertContains('Wireless', $words);
        $this->assertNotContains('Wirless', $words);
    }

    /** The shop's own names for what it spends on. */
    public function test_the_expense_words_include_the_category_names(): void
    {
        $this->seed();
        ExpenseCategory::firstOrCreate(['name' => 'Generator Diesel']);

        $this->assertContains('Generator', array_column(WordList::for('expenses'), 'w'));
    }

    /**
     * ⚠️ **The fingerprint is the guard against a stale list.** The list is
     * kept until something changes; a saved product must move it, or the shop
     * types against last week's words.
     */
    public function test_a_saved_product_reaches_the_very_next_screen(): void
    {
        $this->seed();

        $this->assertNotContains('Sivpuls', array_column(WordList::for('products'), 'w'));

        $this->product('Cooler Sivpuls Magnetic');

        $this->assertContains('Sivpuls', array_column(WordList::for('products'), 'w'));
    }

    /**
     * ⚠️ In the compiled bundle, not only in the source app.js.
     *
     * The shop never runs `npm run build` — the built files are committed — so
     * a change to app.js that was not rebuilt is a change the shop never gets,
     * with every test still green. The timestamp is the part that catches it.
     */
    public function test_the_behaviour_is_in_the_built_bundle(): void
    {
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $built = public_path('build/'.$manifest['resources/js/app.js']['file']);
        $bundle = file_get_contents($built);

        $this->assertStringContainsString('data-word-help', $bundle);
        $this->assertStringContainsString('wordHelpRow', $bundle);

        $this->assertGreaterThanOrEqual(
            filemtime(resource_path('js/app.js')),
            filemtime($built),
            'app.js has been edited since the bundle was built — run npm run build',
        );
    }
}
