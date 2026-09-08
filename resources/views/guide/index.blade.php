@extends('layouts.app')

@section('title', __('Guide'))
@section('subheading', __('How to do the things this shop does — arranged by what you are trying to do'))

@section('content')
    {{--
        Arranged by task, not by menu. See App\Support\Guide: a shopkeeper does
        not think "I need the sale-returns screen", they think "a customer
        brought something back", so the titles are the sentences people say.

        No permission on this page. The reader most likely to open it is the
        newest assistant, holding the fewest permissions in the shop.
    --}}
    <div class="row g-4">
        <div class="col-12 col-xl-8">

            {{-- The one link that matters on somebody's first day, before the
                 list they have not learned to read yet. --}}
            @php($first = App\Support\Guide::topic(App\Support\Guide::FIRST))
            <div class="card border-primary-subtle mb-4">
                <div class="card-body d-flex flex-wrap align-items-center gap-3">
                    <i class="bi bi-signpost-2 fs-2 text-primary d-none d-sm-block" aria-hidden="true"></i>
                    <div class="flex-grow-1 min-w-0">
                        <h2 class="h6 mb-1">{{ __('New here?') }}</h2>
                        <div class="small text-secondary">
                            {{ __('Start with the first week. The five steps are in the order the system needs them, and the order is the part that matters.') }}
                        </div>
                    </div>
                    <a href="{{ route('guide.show', App\Support\Guide::FIRST) }}" class="btn btn-primary flex-shrink-0">
                        {{ $first['title'] }}
                    </a>
                </div>
            </div>

            {{-- Searched in the browser rather than on the server: every word of
                 every topic is already in the page (data-search below), so a
                 shop on a slow connection still gets an answer as it types. --}}
            <div class="input-group mb-3">
                <span class="input-group-text bg-body-tertiary border-end-0">
                    <i class="bi bi-search" aria-hidden="true"></i>
                </span>
                <input type="search" id="guide-search" class="form-control border-start-0"
                       placeholder="{{ __('Search the guide — returns, credit, barcode…') }}"
                       aria-label="{{ __('Search the guide') }}" autocomplete="off">
            </div>

            <p id="guide-no-results" class="text-secondary small d-none" role="status"
               data-none="{{ __('Nothing in the guide matches that. Try one word rather than a sentence.') }}"></p>

            @foreach($groups as $key => $heading)
                @php($inGroup = collect($topics)->filter(fn ($t) => $t['group'] === $key))

                <section class="mb-4" data-guide-group>
                    <h2 class="h6 text-secondary text-uppercase mb-2">{{ $heading }}</h2>

                    <div class="list-group">
                        @foreach($inGroup as $slug => $topic)
                            <a href="{{ route('guide.show', $slug) }}"
                               class="list-group-item list-group-item-action d-flex align-items-start gap-3"
                               data-guide-topic
                               data-search="{{ Str::lower(App\Support\Guide::searchText($topic)) }}">
                                <i class="bi bi-{{ $topic['icon'] }} fs-5 text-primary flex-shrink-0 mt-1" aria-hidden="true"></i>

                                <span class="flex-grow-1 min-w-0">
                                    <span class="d-block fw-semibold">{{ $topic['title'] }}</span>
                                    <span class="d-block small text-secondary">{{ $topic['blurb'] }}</span>
                                </span>

                                <span class="badge text-bg-light flex-shrink-0 fw-normal">
                                    {{ __(':minutes min', ['minutes' => $topic['minutes']]) }}
                                </span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>

        {{-- What changed lately, in the shop's words rather than ours. Beside
             the list on a wide screen and under it on a narrow one, because it
             is worth finding and is not what anybody came for. --}}
        <div class="col-12 col-xl-4">
            <div class="card">
                <div class="card-header d-flex align-items-center gap-2">
                    <i class="bi bi-stars text-primary" aria-hidden="true"></i>
                    <span class="fw-semibold">{{ __('What’s new') }}</span>
                    <span class="badge rounded-pill text-bg-primary ms-auto" dir="ltr">{{ count($whatsNew) }}</span>
                </div>

                <div class="card-body">
                    <ol class="list-unstyled mb-0">
                        @foreach($whatsNew as $item)
                            <li class="pb-3 mb-3 {{ $loop->last ? '' : 'border-bottom' }}">
                                <div class="small text-secondary" dir="ltr">{{ $item['date'] }}</div>
                                <div class="fw-semibold small">{{ $item['title'] }}</div>
                                <div class="small text-secondary">{{ $item['body'] }}</div>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>
        </div>
    </div>
@endsection
