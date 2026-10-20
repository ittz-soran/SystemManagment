@extends('layouts.app')

@section('title', __('Invoice designer'))
@section('subheading', __('Where each line of a government customer\'s A5 invoice prints, in which font and how big'))

@section('back')
    <a href="{{ route('settings.edit') }}" class="btn btn-sm btn-outline-secondary">{{ __('Settings') }}</a>
@endsection

{{--
    The invoice designer — PROJECT_DOC Section 9. Soran, 2026-10-20: "should
    layers are changeable and sizes with fonts, for ex i want use specific
    font" and "use best tool to design invoice inside system".

    The sheet below is the invoice's own markup and stylesheet
    (print/partials), drawn at so many pixels to the millimetre instead of one
    millimetre to the millimetre, so what is placed here is what prints. The
    dragging is resources/js/invoice-designer.js.
--}}
@section('content')
    @include('print.partials.invoice-font')
    @include('print.partials.invoice-layers-css')

    <style>
        .design-stage { background: var(--bs-tertiary-bg); border-radius: 0.5rem; padding: 1rem; overflow: auto; }
        .design-sheet { --mm: 4px; margin: 0 auto; box-shadow: 0 0.2rem 1rem rgba(0, 0, 0, 0.15); user-select: none; touch-action: none; }
        .design-sheet .inv-paper { pointer-events: none; -webkit-user-drag: none; }
        .design-sheet .inv-layer { outline: 1px dashed rgba(13, 110, 253, 0.6); cursor: move; touch-action: none; }
        .design-sheet .inv-layer:hover { outline-color: var(--bs-primary); }
        .design-sheet .inv-layer.is-selected { outline: 2px solid var(--bs-primary); z-index: 5; }
        .design-sheet .inv-layer.is-off { opacity: 0.35; }
        .design-grip { position: absolute; right: 0; bottom: 0; width: 8px; height: 8px; background: var(--bs-primary); cursor: nwse-resize; display: none; }
        .inv-layer.is-selected .design-grip { display: block; }
        .design-divider { position: absolute; top: 0; bottom: 0; width: 11px; margin-left: -5px; left: calc(var(--at) * var(--mm)); cursor: ew-resize; z-index: 2;
            background: linear-gradient(to right, transparent 5px, rgba(220, 53, 69, 0.75) 5px 6px, transparent 6px); }
        .design-tag { position: absolute; transform: translateY(-100%); background: var(--bs-primary); color: #fff; font: 600 11px/1.3 system-ui, sans-serif;
            padding: 1px 6px; border-radius: 3px 3px 0 0; pointer-events: none; white-space: nowrap; z-index: 6; }
        .design-nopaper { position: absolute; inset: 30% 10% auto; text-align: center; color: var(--bs-secondary-color); }
        .design-layers .list-group-item.active .text-secondary { color: inherit !important; }
    </style>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('invoice-design.update') }}" method="POST" enctype="multipart/form-data" data-guard-submit id="design-form">
        @csrf
        @method('PUT')
        <input type="hidden" name="layout" id="design-layout" value="{{ json_encode($layout) }}">
        <input type="hidden" name="options" id="design-options" value="{{ json_encode($options, JSON_UNESCAPED_UNICODE) }}">

        <div class="row g-4">
            <div class="col-xl-8">
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="design-undo">{{ __('Undo') }}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="design-reset">{{ __('Back to the book\'s places') }}</button>
                    <span class="text-secondary small ms-auto">{{ __('Drag a box to move it, its edges to resize it. Arrow keys move it 0.5 mm, with Shift 0.1 mm.') }}</span>
                </div>

                <div class="design-stage" id="design-stage">
                    <div class="inv-sheet design-sheet" id="design-sheet">
                        <img src="{{ $paper ?? '' }}" alt="" class="inv-paper {{ $paper ? '' : 'd-none' }}" id="design-paper" draggable="false">
                        @unless($paper)
                            <div class="design-nopaper" id="design-nopaper">{{ __('Choose your blank A5 invoice under Paper to see it here.') }}</div>
                        @endunless
                        @include('print.partials.invoice-layers', ['layout' => $layout, 'fill' => $shown, 'designer' => true])
                        <div class="design-tag d-none" id="design-tag"></div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card mb-3">
                    <div class="card-header">{{ __('Layers') }}</div>
                    <div class="list-group list-group-flush design-layers" id="design-layers">
                        @foreach($layout as $key => $layer)
                            <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between" data-select="{{ $key }}">
                                <span>{{ App\Support\InvoiceLayout::name($key) }}</span>
                                <span class="text-secondary small" data-summary="{{ $key }}"></span>
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="card mb-3" id="design-panel">
                    <div class="card-header" id="design-panel-title">{{ __('Choose a layer') }}</div>
                    <div class="card-body d-none" id="design-panel-body">
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label small mb-1" for="f-x">{{ __('From the left (mm)') }}</label>
                                <input type="number" step="0.1" class="form-control form-control-sm" id="f-x" data-field="x">
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-1" for="f-y">{{ __('From the top (mm)') }}</label>
                                <input type="number" step="0.1" class="form-control form-control-sm" id="f-y" data-field="y">
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-1" for="f-w">{{ __('Width (mm)') }}</label>
                                <input type="number" step="0.1" class="form-control form-control-sm" id="f-w" data-field="w">
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-1" for="f-h">{{ __('Height (mm)') }}</label>
                                <input type="number" step="0.1" class="form-control form-control-sm" id="f-h" data-field="h">
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-1" for="f-size">{{ __('Font size (pt)') }}</label>
                                <input type="number" step="0.5" min="{{ App\Support\InvoiceLayout::SIZE_MIN }}" max="{{ App\Support\InvoiceLayout::SIZE_MAX }}"
                                       class="form-control form-control-sm" id="f-size" data-field="size">
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-1" for="f-color">{{ __('Text colour') }}</label>
                                <input type="color" class="form-control form-control-sm form-control-color w-100" id="f-color" data-field="color">
                            </div>
                            <div class="col-12 d-flex flex-wrap gap-3">
                                <div class="form-check mb-0">
                                    <input type="checkbox" class="form-check-input" id="f-bold" data-field="bold">
                                    <label class="form-check-label small" for="f-bold">{{ __('Bold') }}</label>
                                </div>
                                <div class="form-check mb-0">
                                    <input type="checkbox" class="form-check-input" id="f-show" data-field="show">
                                    <label class="form-check-label small" for="f-show">{{ __('Print this layer') }}</label>
                                </div>
                            </div>
                        </div>

                        <div class="row g-2 mt-1 d-none" id="design-lines-fields">
                            <div class="col-12">
                                <label class="form-label small mb-1" for="f-row">{{ __('Row height (mm)') }}</label>
                                <input type="number" step="0.1" class="form-control form-control-sm" id="f-row" data-field="row">
                            </div>
                            <div class="col-12 small text-secondary">{{ __('Column lines, in mm from the left edge of the box — drag the red lines onto the paper\'s.') }}</div>
                            @foreach([0, 1, 2] as $i)
                                <div class="col-4">
                                    <input type="number" step="0.1" class="form-control form-control-sm" data-column="{{ $i }}"
                                           aria-label="{{ __('Column line :n', ['n' => $i + 1]) }}">
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Soran, 2026-10-20: "set numbers language -> have curency Symbol
                     or not -> … INV-009 show as 009 or not -> if one of Layers not
                     text show as " - " or empty " " -> in kurdish 1 psc is "١ دانە"
                     or just show " ١ "". --}}
                <div class="card mb-3" id="design-options-card">
                    <div class="card-header">{{ __('Invoice options') }}</div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label small mb-1" for="o-digits">{{ __('Numbers') }}</label>
                                <select class="form-select form-select-sm" id="o-digits" data-option="digits">
                                    <option value="latin">123</option>
                                    <option value="arabic">١٢٣ — {{ __('Kurdish and Arabic') }}</option>
                                    <option value="persian">۱۲۳ — {{ __('Persian') }}</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-1" for="o-number">{{ __('Invoice number') }}</label>
                                <select class="form-select form-select-sm" id="o-number" data-option="number">
                                    <option value="full">INV-00009</option>
                                    <option value="number">00009</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-1" for="o-empty">{{ __('A layer with nothing to say') }}</label>
                                <select class="form-select form-select-sm" id="o-empty" data-option="empty">
                                    <option value="blank">{{ __('Left empty') }}</option>
                                    <option value="dash">-</option>
                                </select>
                            </div>
                            <div class="col-6 d-flex flex-column justify-content-end gap-1">
                                <div class="form-check mb-0">
                                    <input type="checkbox" class="form-check-input" id="o-currency" data-option="currency">
                                    <label class="form-check-label small" for="o-currency">{{ __('Currency symbol on the total') }}</label>
                                </div>
                                <div class="form-check mb-0">
                                    <input type="checkbox" class="form-check-input" id="o-unit" data-option="unit">
                                    <label class="form-check-label small" for="o-unit">{{ __('Unit after the quantity') }}</label>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3" id="design-units">
                            <div class="small text-secondary mb-1">{{ __('Each unit as the invoice writes it — leave empty for the unit as typed.') }}</div>
                            <div class="row g-2">
                                @foreach($units as $unit)
                                    <div class="col-6 input-group input-group-sm">
                                        <span class="input-group-text" dir="auto">{{ $unit }}</span>
                                        <input type="text" class="form-control" dir="auto" maxlength="30" data-unit="{{ $unit }}"
                                               placeholder="{{ $unit }}" aria-label="{{ __('The invoice\'s word for :unit', ['unit' => $unit]) }}">
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">{{ __('Paper and font') }}</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="invoice_a5_paper" class="form-label">{{ __('Paper') }}</label>
                            <input id="invoice_a5_paper" type="file" name="invoice_a5_paper" accept="image/png,image/jpeg"
                                   class="form-control @error('invoice_a5_paper') is-invalid @enderror">
                            <div class="form-text">{{ __('A picture of your blank A5 invoice, the whole page, PNG or JPG, with no lines in the item area — the system draws them.') }}</div>
                        </div>
                        <div>
                            <label for="invoice_font" class="form-label">{{ __('Font') }}</label>
                            <input id="invoice_font" type="file" name="invoice_font" accept=".ttf,.otf,.woff,.woff2"
                                   class="form-control @error('invoice_font') is-invalid @enderror">
                            <div class="form-text" id="design-font-note">
                                {{ $font ? __('Your font is in use. Choose another to replace it.') : __('A .ttf, .otf, .woff or .woff2 file. Until you choose one, the shop\'s font is used.') }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary" data-submitting-text="{{ __('Saving…') }}">{{ __('Save design') }}</button>
                    @if($lastSale)
                        <a href="{{ route('sales.print', $lastSale) }}" target="_blank" class="btn btn-outline-secondary">
                            {{ __('Print :number with it', ['number' => $lastSale->document_no]) }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </form>

    <script type="application/json" id="design-data">{!! json_encode([
        'defaults' => App\Support\InvoiceLayout::DEFAULTS,
        'fill' => $fill,
        'alwaysShown' => App\Support\InvoiceLayout::ALWAYS_SHOWN,
        'names' => collect(App\Support\InvoiceLayout::DEFAULTS)->map(fn ($layer, $key) => App\Support\InvoiceLayout::name($key)),
        'limits' => [
            'width' => App\Support\InvoiceLayout::WIDTH,
            'height' => App\Support\InvoiceLayout::HEIGHT,
            'smallest' => App\Support\InvoiceLayout::SMALLEST,
            'sizeMin' => App\Support\InvoiceLayout::SIZE_MIN,
            'sizeMax' => App\Support\InvoiceLayout::SIZE_MAX,
            'rowMin' => App\Support\InvoiceLayout::ROW_MIN,
            'rowMax' => App\Support\InvoiceLayout::ROW_MAX,
            'columnMin' => App\Support\InvoiceLayout::COLUMN_MIN,
        ],
        'words' => [
            'choose' => __('Choose a layer'),
            'fontReady' => __('This font will be used once you save.'),
            'fontBad' => __('That is not a font file. Choose a .ttf, .otf, .woff or .woff2 file.'),
        ],
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
@endsection

@push('scripts')
    @vite('resources/js/invoice-designer.js')
@endpush
