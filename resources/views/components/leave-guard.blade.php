{{--
    Leaving a cart screen with work in it asks first — Soran, 2026-09-26:
    *"when counter or user on page sale/purchase and already added item to cart
    -> should not go another where or page until complete or show warning
    message to close or stay"*.

    ⚠️ **Not a lock.** A shopkeeper who really means to leave must be able to;
    what must never happen is losing eight scanned lines to a thumb landing on
    the menu, silently, with a customer waiting.

    ⚠️ **The text names the way out the shop already has.** "Hold this cart"
    exists and is the right answer most of the time, so the modal says so
    rather than offering only lose-it-or-stay. It is not a third button: Hold
    asks for a note of its own, and a prompt inside a modal is a worse screen
    than the one it replaced.
--}}
<div class="modal fade" id="leave-guard" tabindex="-1" aria-hidden="true"
     data-stay="{{ __('Stay on this page') }}">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle text-warning me-1"></i>
                    {{ __('There is something in the cart') }}
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                        aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">{{ __('Leaving now loses what has been scanned. Nothing has been saved yet.') }}</p>
                <p class="small text-secondary mb-0">
                    <i class="bi bi-pause-circle me-1"></i>
                    {{ __('To keep it for later without finishing it, stay here and press Hold this cart.') }}
                </p>
            </div>
            <div class="modal-footer">
                {{-- ⚠️ Staying is the primary button and comes first: the
                     press that opened this was almost always a mistake. --}}
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">
                    {{ __('Stay on this page') }}
                </button>
                <button type="button" class="btn btn-outline-danger" data-leave-anyway>
                    {{ __('Leave and lose it') }}
                </button>
            </div>
        </div>
    </div>
</div>
