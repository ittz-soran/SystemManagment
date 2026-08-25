<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Export and import the shop's master data — the descriptive rows: products,
 * categories, suppliers and customers.
 *
 * WHAT THIS DELIBERATELY CANNOT TOUCH
 *
 * Stock and money are never written here. `products.quantity` is a cache of
 * SUM(stock_batches.quantity_remaining) (Section 4), and a customer's or
 * supplier's balance is a cache of the ledger (Section 4 again). Writing either
 * from a spreadsheet would make the cache disagree with the batches and the
 * ledger that are the actual truth, and nothing in the system would notice.
 *
 * So stock changes through a purchase or a stock adjustment, and a balance
 * changes through a sale, a purchase or a payment — never through this. A file
 * that tries anyway is not ignored silently: the row is reported, with the
 * reason, so the difference between "did nothing" and "refused" is visible.
 *
 * PROTECTED is the enforcement rather than the intention: every attribute this
 * builds is checked against it before it reaches the database.
 */
class MasterDataTransfer
{
    /** Columns no import may ever write, whatever a file contains. */
    private const PROTECTED = ['quantity', 'balance', 'is_system', 'id', 'deleted_at'];

    /**
     * The four kinds, their columns, and how a row maps onto a model.
     *
     * Headers are English field names rather than translated labels: a file is
     * a data format, not a screen, and a Sorani export should still open in an
     * Arabic shop's copy of the system.
     */
    public const ENTITIES = ['products', 'categories', 'suppliers', 'customers'];

    public function __construct(private ProductCodeService $codes) {}

    /** @return list<string> */
    public function columns(string $entity): array
    {
        return match ($entity) {
            'products' => ['name', 'sku', 'barcode', 'category', 'unit',
                'purchase_price', 'sale_price', 'reorder_level', 'is_active'],
            'categories' => ['name', 'parent'],
            'suppliers', 'customers' => ['name', 'phone', 'address', 'is_active'],
            default => throw new RuntimeException(__('Unknown kind of data: :entity', ['entity' => $entity])),
        };
    }

    /**
     * Columns written on export but ignored on import, because they are caches
     * of something else. Shown so a stocktake list is useful; refused on the way
     * back in so a typo cannot corrupt the ledger.
     *
     * @return list<string>
     */
    public function readOnlyColumns(string $entity): array
    {
        return match ($entity) {
            // kind is read-only for the same reason quantity is: a second-hand
            // item became one by being bought through its own screen, and a
            // spreadsheet turning an ordinary product into a service — or the
            // other way about — would strand batches nothing can consume.
            'products' => ['quantity', 'kind'],
            'suppliers', 'customers' => ['balance'],
            default => [],
        };
    }

    public function label(string $entity): string
    {
        return match ($entity) {
            'products' => __('Products'),
            'categories' => __('Categories'),
            'suppliers' => __('Suppliers'),
            'customers' => __('Customers'),
            default => $entity,
        };
    }

    // ------------------------------------------------------------------ export

    /**
     * The whole table as CSV.
     *
     * Written with a UTF-8 byte-order mark, without which Excel on Windows
     * renders every Kurdish and Arabic name as mojibake — which is most of the
     * names in this shop.
     */
    /**
     * @param  list<int>|null  $only  Restrict to these ids; null exports everything.
     */
    public function export(string $entity, ?array $only = null): string
    {
        $columns = [...$this->columns($entity), ...$this->readOnlyColumns($entity)];

        $handle = fopen('php://temp', 'r+');

        fwrite($handle, "\u{FEFF}");
        fputcsv($handle, $columns);

        // A handful of rows chosen on a list page, or the lot. Same columns,
        // same encoding, same file either way — one export, narrowed.
        $query = $this->query($entity);

        if ($only !== null) {
            $query->whereKey($only);
        }

        foreach ($query->cursor() as $model) {
            fputcsv($handle, array_map(
                fn (string $column) => $this->read($model, $column),
                $columns,
            ));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** A file with only the header row, to fill in and import back. */
    public function template(string $entity): string
    {
        $handle = fopen('php://temp', 'r+');

        fwrite($handle, "\u{FEFF}");
        fputcsv($handle, $this->columns($entity));

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    // ------------------------------------------------------------------ import

    /**
     * Read a file and work out what importing it would do — without doing it.
     *
     * Section 9b's rule for anything destructive is that Soran sees what will
     * happen before it happens. An import can rewrite every price in the shop,
     * so it always goes through here first.
     *
     * @return array{rows: list<array<string, mixed>>, create: int, update: int, skip: int, unchanged: int}
     */
    public function preview(string $entity, string $path): array
    {
        return $this->readRows($entity, $path, apply: false);
    }

    /**
     * Do it. One transaction: a file that fails halfway leaves nothing behind.
     *
     * @return array{rows: list<array<string, mixed>>, create: int, update: int, skip: int, unchanged: int}
     */
    public function import(string $entity, string $path, User $user): array
    {
        $result = DB::transaction(fn () => $this->readRows($entity, $path, apply: true));

        app(ActivityLogger::class)->log(
            action: 'update',
            module: $entity,
            description: __('Imported :label — :created added, :updated changed, :skipped skipped', [
                'label' => $this->label($entity),
                'created' => $result['create'],
                'updated' => $result['update'],
                'skipped' => $result['skip'],
            ]),
            user: $user,
        );

        return $result;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, create: int, update: int, skip: int, unchanged: int}
     */
    private function readRows(string $entity, string $path, bool $apply): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException(__('Could not read :path', ['path' => $path]));
        }

        $header = $this->readHeader($handle, $entity);

        $rows = [];
        $counts = ['create' => 0, 'update' => 0, 'skip' => 0, 'unchanged' => 0];
        $line = 1;

        try {
            while (($values = fgetcsv($handle)) !== false) {
                $line++;

                // A trailing newline, or a row of empty cells left by a
                // spreadsheet, is not an error worth reporting.
                if ($values === [null] || implode('', array_map('strval', $values)) === '') {
                    continue;
                }

                $row = $this->combine($header, $values);
                $outcome = $this->consider($entity, $row, $line, $apply);

                $counts[$outcome['action']]++;
                $rows[] = $outcome;
            }
        } finally {
            fclose($handle);
        }

        return [...$counts, 'rows' => $rows];
    }

    /**
     * @return list<string>
     */
    private function readHeader($handle, string $entity): array
    {
        $header = fgetcsv($handle);

        if ($header === false) {
            throw new RuntimeException(__('That file is empty.'));
        }

        // Excel writes a byte-order mark; without stripping it the first column
        // is named "\u{FEFF}name" and never matches anything.
        $header[0] = preg_replace('/^\x{FEFF}/u', '', (string) $header[0]);

        $header = array_map(
            fn ($name) => str_replace(' ', '_', mb_strtolower(trim((string) $name))),
            $header,
        );

        $known = [...$this->columns($entity), ...$this->readOnlyColumns($entity)];

        if (array_intersect($header, $known) === []) {
            throw new RuntimeException(__(
                'That file has no columns this import recognises. It should start with a row of names like: :columns',
                ['columns' => implode(', ', $this->columns($entity))],
            ));
        }

        return $header;
    }

    /**
     * @param  list<string>  $header
     * @param  list<string|null>  $values
     * @return array<string, string>
     */
    private function combine(array $header, array $values): array
    {
        $row = [];

        foreach ($header as $index => $name) {
            $row[$name] = trim((string) ($values[$index] ?? ''));
        }

        return $row;
    }

    /**
     * Decide what one row means, and carry it out when asked to.
     *
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function consider(string $entity, array $row, int $line, bool $apply): array
    {
        $report = fn (string $action, string $note, array $extra = []) => [
            'line' => $line,
            'name' => $row['name'] ?? '',
            'action' => $action,
            'note' => $note,
            ...$extra,
        ];

        $existing = $this->match($entity, $row);

        $validator = Validator::make($row, $this->rules($entity, $existing), [], $this->attributeNames($entity));

        if ($validator->fails()) {
            return $report('skip', implode(' ', $validator->errors()->all()));
        }

        try {
            $attributes = $this->attributes($entity, $row, $existing);
        } catch (RuntimeException $e) {
            return $report('skip', $e->getMessage());
        }

        $this->assertNothingProtected($attributes);

        // Section 4: the Cash Customer "cannot be deleted or renamed".
        if ($existing instanceof Customer && $existing->is_system) {
            return $report('skip', __('The Cash Customer is part of the system and cannot be changed here.'));
        }

        $ignored = $this->ignoredChanges($entity, $row, $existing);

        if ($existing === null) {
            if ($apply) {
                $this->create($entity, $attributes);
            }

            return $report('create', $ignored ?: __('Will be added'));
        }

        $changes = $this->changes($existing, $attributes);

        if ($changes === [] && $ignored === null) {
            return $report('unchanged', __('Already the same'));
        }

        if ($changes === []) {
            return $report('skip', $ignored);
        }

        if ($apply) {
            $existing->fill($attributes)->save();
        }

        return $report('update', trim(implode(' ', array_filter([
            implode(', ', $changes),
            $ignored,
        ]))));
    }

    /**
     * Section 4: stock and balances are caches of something else, so a file that
     * tries to set them is told so rather than quietly ignored.
     */
    private function ignoredChanges(string $entity, array $row, ?Model $existing): ?string
    {
        foreach ($this->readOnlyColumns($entity) as $column) {
            if (! array_key_exists($column, $row) || $row[$column] === '') {
                continue;
            }

            $current = $existing?->getAttribute($column) ?? 0;

            if ((int) $row[$column] === (int) $current) {
                continue;
            }

            return $column === 'quantity'
                ? __('Stock was left alone: it changes through a purchase or a stock adjustment, never through an import.')
                : __('The balance was left alone: it changes through a sale, a purchase or a payment, never through an import.');
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    private function changes(Model $existing, array $attributes): array
    {
        $changed = [];

        foreach ($attributes as $key => $value) {
            if ($existing->getAttribute($key) != $value) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * The last line of defence. The mappings below never build these, and this
     * makes sure a later edit cannot start.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertNothingProtected(array $attributes): void
    {
        $offending = array_intersect(array_keys($attributes), self::PROTECTED);

        if ($offending !== []) {
            throw new RuntimeException(
                'An import tried to write '.implode(', ', $offending).
                ', which is a cache of stock or the ledger and must never come from a file.'
            );
        }
    }

    // ------------------------------------------------------------- per entity

    private function query(string $entity): \Illuminate\Database\Eloquent\Builder
    {
        return match ($entity) {
            'products' => Product::with('category')->orderBy('name'),
            'categories' => Category::with('parent')->orderBy('name'),
            'suppliers' => Supplier::orderBy('name'),
            'customers' => Customer::orderByDesc('is_system')->orderBy('name'),
        };
    }

    private function read(Model $model, string $column): string
    {
        $value = match ($column) {
            'category' => $model->category?->name,
            'parent' => $model->parent?->name,
            'is_active' => $model->is_active ? 'yes' : 'no',
            default => $model->getAttribute($column),
        };

        return $value === null ? '' : (string) $value;
    }

    /** The column a row is recognised by, so a second import updates rather than duplicates. */
    private function match(string $entity, array $row): ?Model
    {
        return match ($entity) {
            // SKU is the product's identity, and it is unique in the database.
            'products' => ($row['sku'] ?? '') === ''
                ? null
                : Product::where('sku', $row['sku'])->first(),
            'categories' => Category::where('name', $row['name'] ?? '')->first(),
            'suppliers' => Supplier::where('name', $row['name'] ?? '')->first(),
            'customers' => Customer::where('name', $row['name'] ?? '')->first(),
        };
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(string $entity, ?Model $existing): array
    {
        $ignore = $existing?->getKey();

        return match ($entity) {
            'products' => [
                'name' => ['required', 'string', 'max:255'],
                'sku' => ['nullable', 'string', 'max:255',
                    \Illuminate\Validation\Rule::unique('products', 'sku')->ignore($ignore)],
                'barcode' => ['nullable', 'string', 'max:32',
                    \Illuminate\Validation\Rule::unique('products', 'barcode')->ignore($ignore)],
                'category' => ['required', 'string', 'max:255'],
                'unit' => ['nullable', 'string', 'max:32'],
                // Section 2: IQD is whole numbers only, never decimal.
                'purchase_price' => ['required', 'integer', 'min:0'],
                'sale_price' => ['required', 'integer', 'min:0'],
                'reorder_level' => ['nullable', 'integer', 'min:0'],
            ],
            'categories' => [
                'name' => ['required', 'string', 'max:255'],
                'parent' => ['nullable', 'string', 'max:255'],
            ],
            'suppliers', 'customers' => [
                'name' => ['required', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:32'],
                'address' => ['nullable', 'string', 'max:255'],
            ],
        };
    }

    /** @return array<string, string> */
    private function attributeNames(string $entity): array
    {
        return [
            'name' => __('name'),
            'sku' => __('SKU'),
            'barcode' => __('barcode'),
            'category' => __('category'),
            'parent' => __('parent'),
            'unit' => __('unit'),
            'purchase_price' => __('purchase price'),
            'sale_price' => __('sale price'),
            'reorder_level' => __('reorder level'),
            'phone' => __('phone'),
            'address' => __('address'),
        ];
    }

    /**
     * A row, as attributes to write. Never stock, never a balance.
     *
     * @return array<string, mixed>
     */
    private function attributes(string $entity, array $row, ?Model $existing): array
    {
        $text = fn (string $key, $fallback = null) => array_key_exists($key, $row) && $row[$key] !== ''
            ? $row[$key]
            : ($existing?->getAttribute($key) ?? $fallback);

        $flag = function (string $key, bool $fallback) use ($row, $existing) {
            if (! array_key_exists($key, $row) || $row[$key] === '') {
                return $existing?->getAttribute($key) ?? $fallback;
            }

            return in_array(mb_strtolower($row[$key]), ['1', 'yes', 'y', 'true', 'active'], true);
        };

        return match ($entity) {
            'products' => [
                'name' => $row['name'],
                // Blank on a new product means "generate one" — resolved in
                // create() exactly as the New product form does it.
                'sku' => $text('sku'),
                'barcode' => $text('barcode'),
                'category_id' => $this->categoryId($row['category']),
                'unit' => $text('unit', 'pcs'),
                'purchase_price' => (int) $row['purchase_price'],
                'sale_price' => (int) $row['sale_price'],
                'reorder_level' => ($row['reorder_level'] ?? '') === '' ? null : (int) $row['reorder_level'],
                'is_active' => $flag('is_active', true),
            ],
            'categories' => [
                'name' => $row['name'],
                'parent_id' => ($row['parent'] ?? '') === '' ? null : $this->categoryId($row['parent'], exclude: $existing),
            ],
            'suppliers', 'customers' => [
                'name' => $row['name'],
                'phone' => $text('phone'),
                'address' => $text('address'),
                'is_active' => $flag('is_active', true),
            ],
        };
    }

    /**
     * A category is referred to by name in a file, because a shopkeeper filling
     * in a spreadsheet has no idea what a foreign key is.
     *
     * Missing ones are reported rather than created: a typo would otherwise
     * silently add "Flah drives" alongside "Flash drives", and the shop would
     * find out months later when a report split in two.
     */
    private function categoryId(string $name, ?Model $exclude = null): int
    {
        $category = Category::where('name', $name)->first();

        if ($category === null) {
            throw new RuntimeException(__(
                'There is no category called ":name". Import the categories first, or check the spelling.',
                ['name' => $name],
            ));
        }

        if ($exclude !== null && $category->getKey() === $exclude->getKey()) {
            throw new RuntimeException(__('A category cannot be its own parent.'));
        }

        return (int) $category->getKey();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(string $entity, array $attributes): void
    {
        if ($entity !== 'products') {
            match ($entity) {
                'categories' => Category::create($attributes),
                'suppliers' => Supplier::create($attributes),
                'customers' => Customer::create($attributes),
            };

            return;
        }

        // Section 4: a blank SKU or barcode is generated, exactly as it is on
        // the New product form, so a file can leave both columns empty.
        Product::create([
            ...$attributes,
            ...$this->codes->resolve([
                'sku' => $attributes['sku'] ?? null,
                'barcode' => $attributes['barcode'] ?? null,
            ]),
            // Section 5: stock starts at zero. Opening stock is an "in" stock
            // adjustment, which is the only thing allowed to create a batch.
            'quantity' => 0,
        ]);
    }
}
