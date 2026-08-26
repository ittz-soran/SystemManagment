{{--
    Carts that have been put down, waiting to be picked up.

    Twenty-five things scanned and the supplier not chosen yet is a real
    afternoon, and the only thing worse than losing that cart is a system that
    quietly "reserved" its stock — those units would vanish from the till at the
    other end of the counter while nobody had actually bought them.

    So a held cart is a note to self. No document number, no batch, no movement,
    no ledger row. It waits here until somebody finishes it or throws it away.

    Expects: $heldCarts, $type ('sale'|'purchase'), $resumeRoute.
--}}
@if($heldCarts->isNotEmpty())
    <div class="card mb-3 no-print border-warning-subtle">
        <div class="card-body py-2">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="small text-secondary">
                    <i class="bi bi-pause-circle me-1"></i>
                    {{ trans_choice('{1}:count cart waiting|[2,*]:count carts waiting', $heldCarts->count(),
                        ['count' => number_format($heldCarts->count())]) }}
                </span>

                @foreach($heldCarts as $cart)
                    <div class="btn-group btn-group-sm">
                        <a href="{{ route($resumeRoute, ['held' => $cart->id]) }}"
                           class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>
                            {{ $cart->note ?: trans_choice('{1}:count line|[2,*]:count lines', $cart->lineCount(),
                                ['count' => $cart->lineCount()]) }}
                            <span class="text-secondary ms-1 money">{{ money($cart->total(), false) }}</span>
                            <span class="text-secondary ms-1 small">
                                · {{ $cart->user->name }} · {{ $cart->created_at->diffForHumans(short: true) }}
                            </span>
                        </a>

                        <form action="{{ route('held-carts.destroy', $cart) }}" method="POST" class="d-inline"
                              onsubmit="return confirm(@js(__('Throw this held cart away?')))">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger"
                                    title="{{ __('Discard') }}"><i class="bi bi-x"></i></button>
                        </form>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
