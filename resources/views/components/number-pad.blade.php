{{-- Section 9b: the till is used on a counter, often on a touchscreen, and a
     price is the number most often typed by hand. Tapping the field opens this
     rather than asking a finger to hit a small number input.

     It does arithmetic as well as digits, because the sums a shopkeeper does at
     a price field are real ones: taking a discount off (15000 − 500), or
     working back from a total a customer quotes (36000 ÷ 3).

     One modal per page, reused by whichever field opened it. Any input carrying
     data-numpad gets it — the attribute's value is the heading. --}}
<div class="modal fade" id="number-pad" tabindex="-1" aria-labelledby="number-pad-title" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                {{-- me-auto, not Bootstrap's own margin on the close button: that one is a
                     physical margin-left and does not flip, so in RTL the × ends up
                     pressed against the title. --}}
                <h6 class="modal-title text-truncate me-auto" id="number-pad-title">{{ __('Enter a number') }}</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>

            <div class="modal-body">
                {{-- What has been entered so far, so a half-finished sum is
                     never a mystery: "15000 −" while the second number is typed. --}}
                <div class="number-pad-expression" id="number-pad-expression" dir="ltr">&nbsp;</div>
                <output id="number-pad-display" class="number-pad-display" dir="ltr">0</output>

                <div class="number-pad-grid mt-3">
                    <button type="button" class="btn btn-outline-secondary" data-pad="clear">{{ __('C') }}</button>
                    <button type="button" class="btn btn-outline-secondary" data-pad="back"
                            aria-label="{{ __('Backspace') }}"><i class="bi bi-backspace"></i></button>
                    <button type="button" class="btn btn-outline-primary" data-pad="/" aria-label="{{ __('Divide') }}">÷</button>
                    <button type="button" class="btn btn-outline-primary" data-pad="*" aria-label="{{ __('Multiply') }}">×</button>

                    <button type="button" class="btn btn-outline-secondary" data-pad="7">7</button>
                    <button type="button" class="btn btn-outline-secondary" data-pad="8">8</button>
                    <button type="button" class="btn btn-outline-secondary" data-pad="9">9</button>
                    <button type="button" class="btn btn-outline-primary" data-pad="-" aria-label="{{ __('Subtract') }}">−</button>

                    <button type="button" class="btn btn-outline-secondary" data-pad="4">4</button>
                    <button type="button" class="btn btn-outline-secondary" data-pad="5">5</button>
                    <button type="button" class="btn btn-outline-secondary" data-pad="6">6</button>
                    <button type="button" class="btn btn-outline-primary" data-pad="+" aria-label="{{ __('Add') }}">+</button>

                    <button type="button" class="btn btn-outline-secondary" data-pad="1">1</button>
                    <button type="button" class="btn btn-outline-secondary" data-pad="2">2</button>
                    <button type="button" class="btn btn-outline-secondary" data-pad="3">3</button>
                    <button type="button" class="btn btn-primary number-pad-equals" data-pad="="
                            aria-label="{{ __('Equals') }}">=</button>

                    <button type="button" class="btn btn-outline-secondary" data-pad="00">00</button>
                    <button type="button" class="btn btn-outline-secondary" data-pad="0">0</button>

                    {{-- Only useful where a decimal is allowed, so it is hidden
                         unless the field asks for one. --}}
                    <button type="button" class="btn btn-outline-secondary invisible" data-pad="." id="number-pad-dot">.</button>
                </div>
            </div>

            <div class="modal-footer py-2 d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">
                    {{ __('Cancel') }}
                </button>
                <button type="button" class="btn btn-success flex-fill" id="number-pad-ok">
                    {{ __('OK') }}
                </button>
            </div>
        </div>
    </div>
</div>
