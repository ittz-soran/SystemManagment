{{-- Section 9b: the till is used on a counter, often on a touchscreen, and a
     price is the number most often typed by hand. Tapping the field opens this
     rather than asking a finger to hit a small number input.

     One modal per page, reused by whichever field opened it. Any input carrying
     data-numpad gets it — the attribute's value is the heading. --}}
<div class="modal fade" id="number-pad" tabindex="-1" aria-labelledby="number-pad-title" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title text-truncate" id="number-pad-title">{{ __('Enter a number') }}</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>

            <div class="modal-body">
                <output id="number-pad-display" class="number-pad-display" dir="ltr">0</output>

                <div class="number-pad-grid mt-3">
                    @foreach(['7', '8', '9', '4', '5', '6', '1', '2', '3'] as $digit)
                        <button type="button" class="btn btn-outline-secondary" data-pad="{{ $digit }}">{{ $digit }}</button>
                    @endforeach

                    <button type="button" class="btn btn-outline-secondary" data-pad="00">00</button>
                    <button type="button" class="btn btn-outline-secondary" data-pad="0">0</button>

                    {{-- Only useful where a decimal is allowed, so it is hidden
                         unless the field asks for one. --}}
                    <button type="button" class="btn btn-outline-secondary d-none" data-pad="." id="number-pad-dot">.</button>

                    <button type="button" class="btn btn-outline-secondary" data-pad="back"
                            aria-label="{{ __('Backspace') }}">
                        <i class="bi bi-backspace"></i>
                    </button>
                </div>
            </div>

            <div class="modal-footer py-2 d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary flex-fill" data-pad="clear">
                    {{ __('Clear') }}
                </button>
                <button type="button" class="btn btn-primary flex-fill" id="number-pad-ok">
                    {{ __('OK') }}
                </button>
            </div>
        </div>
    </div>
</div>
