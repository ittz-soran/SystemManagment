{{--
    The first-week checklist, for a shop that has not finished setting up.

    Section 9b: "an empty table is an instruction, not a blank space" — this is
    the same idea one level up. A brand-new shop's whole dashboard is zeros, and
    zeros tell a shopkeeper nothing about what to do next. This does.

    The order is the teaching. See App\Services\SetupProgress: each step is a
    thing the next step needs, and the pair in the middle is the one that
    matters — stock exists only after a purchase, so a shop that tries to sell
    before buying is told the system is empty and concludes it is broken.

    Shown only to an admin, only while unfinished, and only until put away.
--}}
@php
    $steps = $setup->steps();
    $done = $setup->doneCount();
    $total = count($steps);
    $next = $setup->next();
@endphp

<div class="card border-primary-subtle mb-4 no-print">
    <div class="card-body">
        <div class="d-flex align-items-start gap-3 mb-3">
            <i class="bi bi-signpost-2 fs-4 text-primary d-none d-sm-block" aria-hidden="true"></i>

            <div class="flex-grow-1">
                <h2 class="h6 mb-1">{{ __('Getting your shop going') }}</h2>
                <div class="small text-secondary">
                    {{ __('Five things, in this order. The order matters — the system has to know what you bought before it can sell it.') }}
                </div>
            </div>

            <div class="text-end flex-shrink-0">
                <div class="small text-secondary mb-1" dir="ltr">
                    {{ __(':done of :total done', ['done' => $done, 'total' => $total]) }}
                </div>

                {{-- Section 9b: destructive-ish actions say what they do. This one
                     is reversible only by finishing the list, so it is honest
                     about being a dismissal rather than a delete. --}}
                <form method="POST" action="{{ route('setup.hide') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                        {{ __('Hide this') }}
                    </button>
                </form>
            </div>
        </div>

        <div class="progress mb-3" style="height:.35rem"
             role="progressbar"
             aria-label="{{ __('Getting your shop going') }}"
             aria-valuenow="{{ $done }}" aria-valuemin="0" aria-valuemax="{{ $total }}">
            <div class="progress-bar" style="width: {{ $total > 0 ? round($done / $total * 100) : 0 }}%"></div>
        </div>

        <div class="d-flex flex-column gap-1">
            @foreach($steps as $step)
                @php
                    // Exactly one step is "now": the first unfinished one. Two
                    // highlighted steps would be two instructions at once,
                    // which is how a list stops being a sequence.
                    $isNext = ! $step['done'] && $next && $step['key'] === $next['key'];
                @endphp

                <div class="d-flex align-items-center gap-3 p-2 rounded {{ $isNext ? 'bg-primary-subtle' : '' }}">
                    @if($step['done'])
                        <i class="bi bi-check-circle-fill text-success flex-shrink-0" aria-hidden="true"></i>
                        <span class="visually-hidden">{{ __('Done') }}</span>
                    @elseif($isNext)
                        <i class="bi bi-arrow-right-circle-fill text-primary flex-shrink-0" aria-hidden="true"></i>
                        <span class="visually-hidden">{{ __('Do this next') }}</span>
                    @else
                        <i class="bi bi-circle text-secondary opacity-50 flex-shrink-0" aria-hidden="true"></i>
                    @endif

                    <div class="flex-grow-1 min-w-0">
                        <div class="small {{ $step['done'] ? 'text-secondary text-decoration-line-through' : ($isNext ? 'fw-semibold' : '') }}">
                            {{ $step['title'] }}
                        </div>

                        {{-- The note is the part that teaches, so it stays on the
                             step being done now and on the ones still to come.
                             A finished step needs no explanation. --}}
                        @unless($step['done'])
                            <div class="small text-secondary">{{ $step['note'] }}</div>
                        @endunless
                    </div>

                    @unless($step['done'])
                        @if(Route::has($step['route']))
                            <a href="{{ route($step['route']) }}"
                               class="btn btn-sm {{ $isNext ? 'btn-primary' : 'btn-outline-secondary' }} flex-shrink-0">
                                {{ $step['action'] }}
                            </a>
                        @endif
                    @endunless
                </div>
            @endforeach
        </div>
    </div>
</div>
