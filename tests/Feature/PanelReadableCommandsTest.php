<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Services\Licence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The two commands the panel reads a shop through — PANEL_DOC Section 8.
 *
 * The panel runs a shop's own commands through the shared codebase with
 * SHOP_HOME set, so what it records is the shop's own opinion of itself rather
 * than the panel's opinion of the shop. That only works if the answer can be
 * read by a machine, and neither command could be: `licence:show` prints
 * coloured, translated prose, and the data check existed only as a screen you
 * had to sign in to a customer's shop to see.
 *
 * What is held here is the shape of the JSON, because the panel parses it and a
 * renamed key is a silent null in a health check rather than a crash.
 */
class PanelReadableCommandsTest extends TestCase
{
    use RefreshDatabase;

    private static string $private = '';

    private static string $public = '';

    public static function setUpBeforeClass(): void
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($resource, self::$private);
        self::$public = openssl_pkey_get_details($resource)['key'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** @return array<string, mixed> */
    private function ran(string $command): array
    {
        Artisan::call($command);

        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertIsArray($decoded, "[{$command}] did not print JSON: ".Artisan::output());

        return $decoded;
    }

    public function test_licence_show_json_says_what_the_shop_makes_of_its_licence(): void
    {
        $this->licensed(['shop' => 'Bazaar Computer', 'host' => null]);

        $found = $this->ran('licence:show --json');

        $this->assertSame([
            'state', 'shop', 'host', 'expires', 'days_left', 'id', 'allows_writing',
        ], array_keys($found), 'the panel parses these keys by name');

        $this->assertSame(Licence::VALID, $found['state']);
        $this->assertSame('Bazaar Computer', $found['shop']);
        $this->assertNull($found['host'], 'no host means it runs anywhere');
        $this->assertSame('TEST-0001', $found['id']);
        $this->assertSame(now()->addMonth()->toDateString(), $found['expires']);
        $this->assertTrue($found['allows_writing']);
    }

    /**
     * The cross-check the panel exists to make.
     *
     * A licence issued for one shop and put in another's .env verifies
     * perfectly — the signature is real — and is still wrong. The panel records
     * what the shop says here against what it believes itself, and this is the
     * disagreement that matters.
     */
    public function test_licence_show_json_reports_a_licence_meant_for_another_domain(): void
    {
        $this->licensed(['host' => 'somebody-else.soranstore.com']);

        $found = $this->ran('licence:show --json');

        $this->assertSame(Licence::WRONG_HOST, $found['state']);
        $this->assertSame('somebody-else.soranstore.com', $found['host']);
        $this->assertFalse($found['allows_writing']);
    }

    /** Put a licence on this copy, as the seller would. */
    private function licensed(array $payload = []): string
    {
        $licence = Licence::sign([
            'id' => 'TEST-0001',
            'shop' => 'Bazaar Computer',
            'host' => null,
            'issued' => now()->toDateString(),
            'expires' => now()->addMonth()->toDateString(),
            ...$payload,
        ], self::$private);

        config(['licence.public_key' => self::$public, 'licence.key' => $licence]);
        app(Licence::class)->forget();

        return $licence;
    }

    /** A copy that was never sold says so, rather than saying nothing. */
    public function test_licence_show_json_reports_an_unlicensed_copy(): void
    {
        config(['licence.public_key' => '', 'licence.key' => '']);
        app(Licence::class)->forget();

        $found = $this->ran('licence:show --json');

        $this->assertSame(Licence::UNLICENSED, $found['state']);
        $this->assertTrue($found['allows_writing']);
    }

    /**
     * A shop that is locked out still answers.
     *
     * This is the case the panel most needs: `missing` is what a provisioned
     * shop reads as before its licence is delivered, and if the command exited
     * non-zero the panel would record "the check failed" instead of "the shop
     * is read-only", which are different problems with different answers.
     */
    public function test_licence_show_json_still_answers_when_the_shop_cannot_write(): void
    {
        config(['licence.public_key' => self::$public, 'licence.key' => '']);
        app(Licence::class)->forget();

        $this->assertSame(0, Artisan::call('licence:show --json'));

        $found = json_decode(trim(Artisan::output()), true);

        $this->assertSame(Licence::MISSING, $found['state']);
        $this->assertFalse($found['allows_writing']);
    }

    public function test_data_check_json_counts_the_section_10b_assertions(): void
    {
        $found = $this->ran('data:check --json');

        $this->assertSame([
            'total', 'passed', 'serious', 'rebuildable', 'unavailable', 'rows', 'ran_for', 'failing',
        ], array_keys($found));

        $this->assertSame(17, $found['total'], 'Section 10b is seventeen assertions');
        $this->assertSame(17, $found['passed'], 'a freshly seeded shop contradicts itself nowhere');
        $this->assertSame([], $found['failing']);
    }

    /** A shop with a contradiction in it says so, and the exit code carries it. */
    public function test_data_check_reports_a_broken_shop_and_exits_non_zero(): void
    {
        $product = $this->productWithAWrongCachedQuantity();

        $this->assertSame(1, Artisan::call('data:check --json'));

        $found = json_decode(trim(Artisan::output()), true);

        $this->assertGreaterThan(0, $found['serious'] + $found['rebuildable']);
        $this->assertNotEmpty($found['failing']);
        $this->assertLessThan($found['total'], $found['passed']);
    }

    /** It reports and it does not touch: a contradiction is evidence. */
    public function test_the_data_check_changes_nothing(): void
    {
        $product = $this->productWithAWrongCachedQuantity();

        Artisan::call('data:check --json');

        $this->assertSame(99, $product->fresh()->quantity, 'the wrong number is still there to be looked at');
    }

    /**
     * A product whose cached quantity says 99 with no batch behind it.
     *
     * Section 4: `quantity` is a cache of the batches. Setting it without them
     * is the first assertion's exact failure, and the one a real shop hits.
     */
    private function productWithAWrongCachedQuantity(): Product
    {
        $category = Category::create(['name' => 'Accessories']);

        $product = Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32', 'category_id' => $category->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000,
            'quantity' => 0, 'is_active' => true,
        ]);

        Product::withoutEvents(fn () => $product->forceFill(['quantity' => 99])->save());

        return $product;
    }
}
