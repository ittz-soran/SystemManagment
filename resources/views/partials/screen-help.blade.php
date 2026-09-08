{{--
    Help for the screen the reader is on, in a panel that slides in over it.

    Over rather than instead: the whole point is not to lose the half-typed
    sale behind you. Bootstrap's offcanvas, so it closes on Escape, traps focus
    while open, and comes from the correct side in both directions — the
    `-end` placement follows the writing direction rather than the screen, so
    it arrives from the right in English and the left in Sorani without a
    second stylesheet.

    Rendered only when App\Support\ScreenHelp has something for this route, so
    the button never opens an empty drawer.
--}}
@php($help = App\Support\ScreenHelp::for(request()->route()?->getName()))

@if($help)
    <div class="offcanvas offcanvas-end no-print" tabindex="-1" id="screen-help"
         aria-labelledby="screen-help-title" style="--bs-offcanvas-width: 25rem">

        <div class="offcanvas-header border-bottom bg-body-tertiary">
            <h2 class="offcanvas-title h6 mb-0 d-flex align-items-center gap-2" id="screen-help-title">
                <i class="bi bi-question-circle text-primary" aria-hidden="true"></i>
                {{ $help['title'] }}
            </h2>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas"
                    aria-label="{{ __('Close') }}"></button>
        </div>

        <div class="offcanvas-body">
            @isset($help['intro'])
                <p class="text-secondary small">{{ $help['intro'] }}</p>
            @endisset

            @isset($help['steps'])
                <h3 class="h6 mt-3 mb-2">{{ __('Doing it') }}</h3>

                <ol class="list-unstyled mb-0">
                    @foreach($help['steps'] as $index => [$heading, $body])
                        <li class="d-flex gap-3 mb-3">
                            {{-- The number is the sequence, so it is text rather
                                 than a bullet: a reader who cannot see the
                                 colour still gets the order. --}}
                            <span class="badge rounded-circle text-bg-primary-subtle text-primary-emphasis flex-shrink-0
                                         d-flex align-items-center justify-content-center"
                                  style="width:1.5rem;height:1.5rem" dir="ltr">{{ $index + 1 }}</span>

                            <span>
                                <span class="fw-semibold small d-block">{{ $heading }}</span>
                                <span class="small text-secondary">{{ $body }}</span>
                            </span>
                        </li>
                    @endforeach
                </ol>
            @endisset

            @isset($help['warning'])
                {{-- The mistake people actually make here. Amber rather than red:
                     it is a thing worth knowing before it bites, not a failure
                     that has already happened. --}}
                <div class="alert alert-warning small py-2 px-3 mt-3 mb-3">
                    <strong class="d-block mb-1">{{ $help['warning'][0] }}</strong>
                    {{ $help['warning'][1] }}
                </div>
            @endisset

            @isset($help['notes'])
                <h3 class="h6 mt-4 mb-2">{{ __('Worth knowing') }}</h3>

                <ul class="small text-secondary ps-3 mb-0">
                    @foreach($help['notes'] as $note)
                        <li class="mb-2">{{ $note }}</li>
                    @endforeach
                </ul>
            @endisset
        </div>
    </div>
@endif
