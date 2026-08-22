<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\LabelPrinter;
use App\Services\LabelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Printing a shelf label for a product.
 *
 * Section 4 gives an auto-generated barcode an internal EAN-13 prefix, which
 * means nothing is printed on the goods themselves — so the shop prints its own.
 */
class LabelController extends Controller
{
    public function __construct(
        private LabelService $labels,
        private LabelPrinter $printer,
    ) {}

    /** The page the browser prints: one label per page, sized to the stock. */
    public function show(Request $request, Product $product): View|RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $spec = $this->labels->spec(
                product: $product,
                sizeKey: $data['size'] ?? null,
                fields: $this->chosenFields($request),
                copies: (int) ($data['copies'] ?? 1),
            );
        } catch (RuntimeException $e) {
            return redirect()->route('products.show', $product)->with('error', $e->getMessage());
        }

        return view('labels.sheet', [
            'spec' => $spec,
            'labels' => $this->labels,
        ]);
    }

    /**
     * Straight to the printer, with no dialog. Only available when someone has
     * set a printer up; the browser route is always there either way.
     */
    public function print(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validated($request);

        try {
            $spec = $this->labels->spec(
                product: $product,
                sizeKey: $data['size'] ?? null,
                fields: $this->chosenFields($request),
                copies: (int) ($data['copies'] ?? 1),
            );

            $this->printer->send($this->labels->tspl($spec));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', trans_choice(
            '{1}:count label sent to :printer|[2,*]:count labels sent to :printer',
            $spec['copies'],
            ['count' => $spec['copies'], 'printer' => $this->printer->target()],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'size' => ['nullable', Rule::in(array_keys(config('labels.sizes')))],
            'copies' => ['nullable', 'integer', 'min:1', 'max:500'],
            'fields' => ['nullable', 'array'],
            'fields.*' => [Rule::in(LabelService::FIELDS)],
            'chose_fields' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * Checkboxes only post what is ticked, so an absent one means off — not
     * "fall back to the saved default", which would make unticking impossible.
     *
     * @return array<string, bool>
     */
    private function chosenFields(Request $request): array
    {
        // A form that ticked nothing sends no `fields` at all, which is
        // indistinguishable from never having asked — so the modal sends this
        // marker to say the choice was made deliberately.
        if (! $request->boolean('chose_fields') && ! $request->has('fields')) {
            return [];
        }

        $ticked = (array) $request->input('fields', []);

        return collect(LabelService::FIELDS)
            ->mapWithKeys(fn (string $field) => [$field => in_array($field, $ticked, true)])
            ->all();
    }
}
