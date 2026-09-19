@extends('layouts.app')

@section('title', __('Remembrance'))

@section('content')
    {{--
        **Soran, 2026-09-15:** *"add islamic Remembrance for ex from morning show
        Morning Remembrances … or all short duas remembrance"*.

        Every list, not only the one whose hour it is. Somebody opening this at
        noon may well want the morning ones, and a page that hid them would look
        broken rather than considerate.
    --}}
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <i class="bi bi-stars fs-3 text-secondary" aria-hidden="true"></i>

            <div class="flex-grow-1 min-w-0">
                <div class="fw-semibold">{{ __('Remembrance') }}</div>
                <div class="text-secondary small">
                    {{ __('Tap one each time you say it. The count is yours, it stays on this device, and it starts again each day.') }}
                </div>
            </div>

            {{-- Nothing to save, so no form: this clears today's counts in the
                 browser, where they live. --}}
            <button type="button" id="dhikr-reset" class="btn btn-sm btn-outline-secondary">
                {{ __('Start today again') }}
            </button>
        </div>
    </div>

    @php($any = $lists[App\Support\Adhkar::ANY])
    @php($empty = $any === [] && $lists[App\Support\Adhkar::MORNING] === [] && $lists[App\Support\Adhkar::EVENING] === [])

    @if($empty)
        <div class="card">
            <x-empty-state icon="stars"
                           :message="__('No remembrances are set up yet. An admin can add them under Settings.')" />
        </div>
    @else
        <div class="row g-3">
            @foreach([
                App\Support\Adhkar::MORNING => __('Morning'),
                App\Support\Adhkar::EVENING => __('Evening'),
                App\Support\Adhkar::ANY => __('Any time'),
            ] as $key => $heading)
                @continue($lists[$key] === [])

                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header d-flex align-items-center justify-content-between">
                            <span>{{ $heading }}</span>
                            @if($key === $window)
                                {{-- Which one the shop's clock is in. A quiet
                                     mark rather than a colour: it is telling
                                     somebody where they are, not asking them
                                     to do anything. --}}
                                <span class="badge text-bg-secondary">{{ __('Now') }}</span>
                            @endif
                        </div>
                        <div class="card-body p-2">
                            @include('partials.dhikr-list', ['texts' => $lists[$key]])
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="mt-3">
        <form action="{{ route('preferences.remembrance') }}" method="POST" class="d-inline">
            @csrf
            {{-- The unchecked box has to reach the server too, or turning this
                 off would look like not answering. --}}
            <input type="hidden" name="adhkar" value="{{ $off ? '1' : '0' }}">
            <button class="btn btn-sm btn-link text-secondary px-0">
                {{ $off ? __('Show remembrance beside the bell') : __('Hide remembrance beside the bell') }}
            </button>
        </form>
    </div>
@endsection
