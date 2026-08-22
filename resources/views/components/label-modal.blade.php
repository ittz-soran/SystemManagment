@props(['product', 'sizes', 'fields', 'printer'])

@php
    $defaultSize = (string) setting('label_size', array_key_first($sizes));

    // A browser cannot choose a printer for you — window.print() hands the job
    // to the operating system and the person picks there. Sending the label
    // straight to a printer is only possible because the server runs on the
    // same machine, so that route appears only when one has been set up.
    $direct = $printer->isConfigured() ? $printer->target() : null;
@endphp

<div class="modal fade" id="label-modal" tabindex="-1" aria-labelledby="label-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('products.label.print', $product) }}"
              id="label-form" data-browser-action="{{ route('products.label', $product) }}">
            @csrf

            <div class="modal-header py-2">
                <h6 class="modal-title" id="label-modal-title">
                    <i class="bi bi-upc-scan me-1"></i>{{ __('Print barcode label') }}
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>

            <div class="modal-body">
                {{-- Says the boxes below were looked at, so unticking them all
                     means "just the bars" rather than "use the defaults". --}}
                <input type="hidden" name="chose_fields" value="1">

                <div class="text-secondary small mb-3">
                    {{ $product->name }} · <span dir="ltr">{{ $product->barcode }}</span>
                </div>

                <div class="row g-3">
                    <div class="col-6">
                        <label for="label-copies" class="form-label">{{ __('How many') }}</label>
                        <input id="label-copies" type="number" name="copies" min="1" max="500" value="1"
                               class="form-control text-end" dir="ltr"
                               data-numpad="{{ __('How many') }}" data-numpad-min="1">
                    </div>

                    <div class="col-6">
                        <label for="label-size" class="form-label">{{ __('Label size') }}</label>
                        <select id="label-size" name="size" class="form-select">
                            @foreach($sizes as $key => $size)
                                <option value="{{ $key }}" @selected($key === $defaultSize)>{{ __($size['label']) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-12">
                        <div class="form-label">{{ __('Show on the label') }}</div>

                        @foreach([
                            'name' => __('Product name'),
                            'sku' => __('SKU'),
                            'price' => __('Sale price'),
                            'barcode_number' => __('The number under the bars'),
                            'shop' => __('Shop name'),
                        ] as $field => $caption)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="fields[]"
                                       value="{{ $field }}" id="label-field-{{ $field }}"
                                       @checked($fields[$field] ?? false)>
                                <label class="form-check-label" for="label-field-{{ $field }}">{{ $caption }}</label>
                            </div>
                        @endforeach

                        <div class="form-text">
                            {{ __('The bars themselves are always printed. These are the defaults from Settings; changing them here applies to this print only.') }}
                        </div>
                    </div>

                    <div class="col-12">
                        <label for="label-route" class="form-label">{{ __('Printer') }}</label>
                        <select id="label-route" name="route" class="form-select">
                            @if($direct)
                                <option value="direct" selected>
                                    {{ __('Send straight to :printer', ['printer' => $direct]) }}
                                </option>
                            @endif
                            <option value="browser" @selected(! $direct)>
                                {{ __('Print through the browser — choose the printer there') }}
                            </option>
                        </select>

                        @unless($direct)
                            <div class="form-text">
                                @can('settings.manage')
                                    {{ __('Set a printer up on the Settings page to skip the print dialog.') }}
                                    <a href="{{ route('settings.edit') }}">{{ __('Settings') }}</a>
                                @else
                                    {{ __('An administrator can set a printer up to skip the print dialog.') }}
                                @endcan
                            </div>
                        @endunless
                    </div>
                </div>
            </div>

            <div class="modal-footer py-2 d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">
                    {{ __('Cancel') }}
                </button>
                <button type="submit" class="btn btn-primary flex-fill" data-submitting-text="{{ __('Printing…') }}">
                    <i class="bi bi-printer me-1"></i>{{ __('Print') }}
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
    <script>
        (() => {
            const form = document.getElementById('label-form');
            const route = document.getElementById('label-route');

            if (! form || ! route) return;

            form.addEventListener('submit', (event) => {
                if (route.value !== 'browser') return;

                // The browser route is a page to look at and print, not a
                // request that changes anything — so it is a GET, opened in its
                // own tab so the product page stays where it was.
                event.preventDefault();

                const query = new URLSearchParams();
                query.set('auto', '1');
                query.set('chose_fields', '1');
                query.set('copies', document.getElementById('label-copies').value || '1');
                query.set('size', document.getElementById('label-size').value);

                form.querySelectorAll('input[name="fields[]"]:checked')
                    .forEach((field) => query.append('fields[]', field.value));

                window.open(form.dataset.browserAction + '?' + query.toString(), '_blank');

                bootstrap.Modal.getInstance(form.closest('.modal'))?.hide();
            });
        })();
    </script>
@endpush
