<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * Section 8 — what holds a product, asked the way the database asks it.
 *
 * `Product::HELD_BY` exists so that destroying a product is refused in a
 * sentence, before the button, *"rather than discovered afterwards as an
 * integrity-constraint error page"*. It listed seven tables. Eleven hold a
 * `product_id`: swaps, assemblies, stock moves and repair parts were each
 * built after that list was written and never added to it, so each turned the
 * sentence it promises into the error page it exists to prevent.
 *
 * ⚠️ **A LIST KEPT IN STEP BY HAND FALLS OUT OF STEP.** So this reads the
 * foreign keys out of the database rather than trusting anybody to remember.
 */
class HeldProductTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function heldBy(): array
    {
        $held = (new ReflectionClass(Product::class))->getConstant('HELD_BY');

        return array_keys($held);
    }

    /**
     * Every table with a foreign key to `products` must be in the list, or a
     * product held by it crashes instead of explaining itself.
     */
    public function test_every_table_that_holds_a_product_is_accounted_for(): void
    {
        $pointing = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];

            foreach (Schema::getForeignKeys($name) as $key) {
                if ($key['foreign_table'] === 'products' && in_array('product_id', $key['columns'], true)) {
                    $pointing[] = $name;
                }
            }
        }

        sort($pointing);
        $missing = array_values(array_diff($pointing, $this->heldBy()));

        $this->assertSame([], $missing, implode("\n", [
            'These tables hold a product_id and are missing from Product::HELD_BY,',
            'so destroying a product they hold would be an error page instead of a sentence:',
            '  '.implode(', ', $missing),
        ]));
    }

    /** And nothing in the list may name a table that is not there. */
    public function test_the_list_names_no_table_that_does_not_exist(): void
    {
        foreach ($this->heldBy() as $table) {
            $this->assertTrue(Schema::hasTable($table), "HELD_BY names [{$table}], which does not exist.");
            $this->assertTrue(
                Schema::hasColumn($table, 'product_id'),
                "HELD_BY names [{$table}], which has no product_id.",
            );
        }
    }

    /**
     * ⚠️ Every label must have its own sentence. `describeBlockers()` writes
     * them out one literal at a time so `translations:check` can see them, and
     * a label with no arm of that match falls through to "stock movement" —
     * which would tell a shopkeeper the wrong thing with total confidence.
     */
    public function test_every_label_has_wording_of_its_own(): void
    {
        $held = (new ReflectionClass(Product::class))->getConstant('HELD_BY');

        foreach ($held as $table => [$parent, $label]) {
            if ($label === 'stock movements') {
                continue;
            }

            $said = Product::describeBlockers([$label => 2]);

            $this->assertStringNotContainsString(
                'stock movement',
                (string) $said,
                "[{$table}] is labelled [{$label}], which has no wording of its own and falls through to stock movements.",
            );
        }
    }

    /** The count is of documents, not of lines: "2 sales", never "2 sale items". */
    public function test_a_product_on_one_document_twice_is_counted_once(): void
    {
        $this->assertStringContainsString(
            '2 assemblies',
            (string) Product::describeBlockers(['assemblies' => 2]),
        );
    }

    /** ⚠️ It counts what the DATABASE sees — soft deletes and scopes included. */
    public function test_it_counts_rows_a_soft_deleted_document_left_behind(): void
    {
        $this->seed();

        $product = Product::create([
            'name' => 'Bundle Asus B450M', 'sku' => 'BND1',
            'category_id' => Category::firstOrCreate(['name' => 'Parts'])->id,
            'unit' => 'pcs', 'purchase_price' => 1000, 'sale_price' => 2000, 'quantity' => 0,
        ]);

        $assemblyId = DB::table('assemblies')->insertGetId([
            'document_no' => 'ASM-00001', 'direction' => 'together', 'total_cost' => 1000,
            'assembled_at' => now(), 'user_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
            'deleted_at' => now(),
        ]);

        DB::table('assembly_items')->insert([
            'assembly_id' => $assemblyId, 'product_id' => $product->id,
            'role' => 'result', 'quantity' => 1, 'unit_cost' => 1000, 'sequence' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $lock = $product->canBePurged();

        $this->assertFalse($lock['allowed']);
        $this->assertStringContainsString('assembly', $lock['reason']);
    }
}
