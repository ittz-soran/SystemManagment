<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\Setting;
use App\Rules\FontFile;
use App\Services\ActivityLogger;
use App\Support\GovernmentInvoice;
use App\Support\InvoiceLayout;
use App\Support\InvoiceOptions;
use App\Support\Units;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The invoice designer — PROJECT_DOC Section 9. Soran, 2026-10-20: *"should
 * layers are changeable and sizes with fonts, for ex i want use specific
 * font"* and *"use best tool to design invoice inside system"*.
 *
 * One page for the A5 government invoice: the paper it prints on, the font it
 * prints in, and where each layer sits. Guarded by `settings.manage`, like the
 * rest of what changes every invoice.
 */
class InvoiceDesignController extends Controller
{
    public function __construct(private ActivityLogger $logger) {}

    public function edit(): View
    {
        // The layers are shown filled with the last government sale, when
        // there is one, so a long name of the shop's own shows how it wraps.
        $lastSale = Sale::whereIn('customer_id', Customer::where('type', Customer::GOVERNMENT)->where('is_system', false)->select('id'))
            ->latest('id')->first();

        // ⚠️ After a refused save — a font over the server's upload limit —
        // the page comes back with what was on screen, not what was saved:
        // found by driving it, when a refused font threw away every move.
        $unsaved = json_decode((string) old('layout'), true);
        $layout = InvoiceLayout::check($unsaved) === [] ? InvoiceLayout::clean($unsaved) : InvoiceLayout::current();
        $unsaved = json_decode((string) old('options'), true);
        $options = InvoiceOptions::check($unsaved) === [] ? InvoiceOptions::clean($unsaved) : InvoiceOptions::current();

        $fill = $lastSale ? GovernmentInvoice::fill($lastSale) : GovernmentInvoice::sample();

        return view('settings.invoice-design', [
            'layout' => $layout,
            'options' => $options,
            // The sale's own parts for the browser to write again as the
            // options change, and the page as it prints with them now.
            'fill' => $fill,
            'shown' => GovernmentInvoice::present($fill, $options),
            // Every unit the shop uses, and any on the sale shown.
            'units' => collect(Units::all())->merge(collect($fill['rows'])->pluck('unit'))->filter()->unique()->values()->all(),
            'lastSale' => $lastSale,
            'paper' => BrandingController::paperUrl(),
            'font' => BrandingController::fontUrl(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'layout' => ['required', 'string', 'max:20000'],
            'options' => ['required', 'string', 'max:20000'],
            // A picture of the whole blank A5 sheet. A 300 dpi PNG of it is a
            // few hundred kilobytes; a phone photo can be several megabytes.
            BrandingController::PAPER => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:8192'],
            BrandingController::FONT => ['nullable', 'file', 'max:5120', new FontFile],
        ], [
            // PHP refuses a file over the host's own limit before Laravel sees
            // it, and "failed to upload" says nothing about why.
            BrandingController::PAPER.'.uploaded' => $tooBig = __('That file is bigger than this server accepts (:max). Choose a smaller one, or ask the host to raise the upload limit.', [
                'max' => ini_get('upload_max_filesize'),
            ]),
            BrandingController::FONT.'.uploaded' => $tooBig,
        ]);

        $layout = json_decode($request->string('layout')->value(), true);
        $options = json_decode($request->string('options')->value(), true);

        $problems = array_filter(['layout' => InvoiceLayout::check($layout), 'options' => InvoiceOptions::check($options)]);

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        $previous = Setting::cached();

        DB::transaction(function () use ($request, $layout, $options) {
            Setting::put(InvoiceLayout::SETTING, json_encode(InvoiceLayout::clean($layout)));
            Setting::put(InvoiceOptions::SETTING, json_encode(InvoiceOptions::clean($options), JSON_UNESCAPED_UNICODE));

            if ($request->hasFile(BrandingController::PAPER)) {
                $this->replace(BrandingController::PAPER, $request->file(BrandingController::PAPER)->store('branding', 'local'));
            }

            if ($request->hasFile(BrandingController::FONT)) {
                $font = $request->file(BrandingController::FONT);

                // Kept under the name of what it is, which is what it is sent as.
                $this->replace(BrandingController::FONT, $font->storeAs(
                    'branding', Str::random(40).'.'.FontFile::kind($font), 'local',
                ));
            }
        });

        Setting::flushCache();

        $this->logger->log(
            action: 'update',
            module: 'settings',
            description: __('Updated the invoice design'),
            oldValues: array_intersect_key($previous, array_flip([
                InvoiceLayout::SETTING, InvoiceOptions::SETTING, BrandingController::PAPER, BrandingController::FONT,
            ])),
        );

        return back()->with('success', __('Invoice design saved'));
    }

    /**
     * Point the setting at the new file and remove the one it replaces. The
     * disk path, not a URL: BrandingController sends the file.
     */
    private function replace(string $setting, string $path): void
    {
        $previous = BrandingController::path($setting);

        Setting::put($setting, $path);

        if ($previous !== null) {
            Storage::disk($previous[0])->delete($previous[1]);
        }
    }
}
