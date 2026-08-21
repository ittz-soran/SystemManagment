<?php

namespace App\Http\Controllers;

use App\Services\MasterDataTransfer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Import and export the shop's master data.
 *
 * Not a backup and not a restore: this moves the descriptive rows — names,
 * prices, phone numbers — and never stock or money. Section 4 makes
 * `products.quantity` a cache of the batches and a balance a cache of the
 * ledger, so those two only ever move through a purchase, an adjustment, a sale
 * or a payment. MasterDataTransfer enforces that; this only asks for a file.
 */
class DataTransferController extends Controller
{
    public function __construct(private MasterDataTransfer $transfer) {}

    public function index(): View
    {
        return view('data.index', [
            'entities' => MasterDataTransfer::ENTITIES,
            'transfer' => $this->transfer,
        ]);
    }

    public function export(Request $request, string $entity): StreamedResponse
    {
        $this->assertKnown($entity);
        Gate::authorize($this->permission($entity, 'view'));

        $template = $request->boolean('template');

        $csv = $template
            ? $this->transfer->template($entity)
            : $this->transfer->export($entity);

        $name = $entity.($template ? '-template' : '-'.now()->format('Y-m-d')).'.csv';

        return response()->streamDownload(
            fn () => print $csv,
            $name,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * Section 9b: anything that can rewrite a lot at once shows what it will do
     * before it does it. An import can change every price in the shop.
     */
    public function preview(Request $request, string $entity): View|RedirectResponse
    {
        $this->assertKnown($entity);
        $this->assertMayImport($entity);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        // Kept out of the public disk: a supplier list is the shop's business.
        $path = $request->file('file')->store('imports', 'local');

        try {
            $result = $this->transfer->preview($entity, Storage::disk('local')->path($path));
        } catch (RuntimeException $e) {
            Storage::disk('local')->delete($path);

            return back()->with('error', $e->getMessage());
        }

        return view('data.preview', [
            'entity' => $entity,
            'label' => $this->transfer->label($entity),
            'result' => $result,
            'token' => $path,
        ]);
    }

    public function import(Request $request, string $entity): RedirectResponse
    {
        $this->assertKnown($entity);
        $this->assertMayImport($entity);

        $data = $request->validate([
            'token' => ['required', 'string'],
        ]);

        // The token is a path this app wrote a moment ago. Anything else is
        // someone trying their luck with a filename.
        if (! Str::startsWith($data['token'], 'imports/') || ! Storage::disk('local')->exists($data['token'])) {
            return redirect()->route('data.index')
                ->with('error', __('That upload has expired. Choose the file again.'));
        }

        $path = Storage::disk('local')->path($data['token']);

        try {
            $result = $this->transfer->import($entity, $path, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } finally {
            Storage::disk('local')->delete($data['token']);
        }

        return redirect()->route('data.index')->with(
            $result['skip'] > 0 ? 'warning' : 'success',
            __(':label: :created added, :updated changed, :unchanged already the same, :skipped skipped', [
                'label' => $this->transfer->label($entity),
                'created' => $result['create'],
                'updated' => $result['update'],
                'unchanged' => $result['unchanged'],
                'skipped' => $result['skip'],
            ]),
        );
    }

    private function assertKnown(string $entity): void
    {
        abort_unless(in_array($entity, MasterDataTransfer::ENTITIES, true), 404);
    }

    /**
     * An import both adds and changes rows, so it needs the permission for
     * both. Neither one alone is enough.
     */
    private function assertMayImport(string $entity): void
    {
        Gate::authorize($this->permission($entity, 'create'));
        Gate::authorize($this->permission($entity, 'edit'));
    }

    /** Every one of the four kinds is already a module in the permission catalogue. */
    private function permission(string $entity, string $action): string
    {
        return $entity.'.'.$action;
    }
}
