<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Section 4: SKU and barcode generation.
 *
 * Both draw from counters rather than MAX(id), because deleting a product must
 * not let a code be reused.
 */
class ProductCodeService
{
    /**
     * The internal-use EAN-13 prefix range. Section 4 names 200-299, which is
     * reserved for in-store use and so never collides with a real manufacturer
     * barcode.
     */
    private const INTERNAL_PREFIX = '200';

    public function __construct(private DocumentNumberService $numbers) {}

    /**
     * Auto-generate a SKU: the configured prefix plus the next counter value,
     * e.g. `SS65` for "Soran Store 65".
     *
     * Used only when the product has no manufacturer code. When it does, Soran
     * types it (`C175`) and both kinds live in the same unique column.
     */
    public function generateSku(): string
    {
        $prefix = (string) setting('sku_prefix', 'SS');

        do {
            $sku = $prefix.$this->numbers->nextNumber(DocumentNumberService::PREFIX_SKU);
        } while (Product::withTrashed()->where('sku', $sku)->exists());

        return $sku;
    }

    /**
     * Auto-generate a scannable EAN-13 barcode so every product works at the
     * till, even one that arrived with no printed barcode.
     */
    public function generateBarcode(): string
    {
        do {
            $serial = str_pad(
                (string) $this->numbers->nextNumber(DocumentNumberService::PREFIX_BARCODE),
                12 - strlen(self::INTERNAL_PREFIX),
                '0',
                STR_PAD_LEFT
            );

            $body = self::INTERNAL_PREFIX.$serial;
            $barcode = $body.$this->ean13CheckDigit($body);
        } while (Product::withTrashed()->where('barcode', $barcode)->exists());

        return $barcode;
    }

    /**
     * The EAN-13 check digit: digits are weighted 1 and 3 alternately from the
     * left, and the check digit is what takes the total to the next multiple
     * of ten.
     */
    public function ean13CheckDigit(string $body): int
    {
        if (! preg_match('/^\d{12}$/', $body)) {
            throw new RuntimeException('An EAN-13 body must be exactly 12 digits.');
        }

        $sum = 0;

        foreach (str_split($body) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10;
    }

    public function isValidEan13(string $barcode): bool
    {
        if (! preg_match('/^\d{13}$/', $barcode)) {
            return false;
        }

        return $this->ean13CheckDigit(substr($barcode, 0, 12)) === (int) $barcode[12];
    }

    /**
     * Fill in whichever codes were left blank. Runs inside the caller's
     * transaction so the counters and the product row commit together.
     *
     * @param  array{sku?: string|null, barcode?: string|null}  $input
     * @return array{sku: string, barcode: string}
     */
    public function resolve(array $input): array
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Product codes must be generated inside a transaction.');
        }

        return [
            'sku' => filled($input['sku'] ?? null) ? trim($input['sku']) : $this->generateSku(),
            'barcode' => filled($input['barcode'] ?? null) ? trim($input['barcode']) : $this->generateBarcode(),
        ];
    }
}
