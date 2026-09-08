@extends('layouts.app')

@section('title', $topic['title'])
@section('subheading', $topic['blurb'])

@section('back')
    {{-- This page always belongs to the guide, so it names its way back rather
         than leaving it to the tab's history: a reader who arrived from the ?
         panel has no guide page behind them to return to. --}}
    <a href="{{ route('guide.index') }}" class="btn btn-sm btn-link px-0 mb-2">
        <i class="bi bi-arrow-left" aria-hidden="true"></i>
        {{ __('All guides') }}
    </a>
@endsection

@section('content')
    <div class="row g-4">
        <div class="col-12 col-lg-8">
            <article class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-3 small text-secondary">
                        <i class="bi bi-{{ $topic['icon'] }} text-primary" aria-hidden="true"></i>
                        <span>{{ $group }}</span>
                        <span aria-hidden="true">·</span>
                        <span>{{ __(':minutes min read', ['minutes' => $topic['minutes']]) }}</span>
                    </div>

                    @foreach($topic['sections'] as [$heading, $paragraphs])
                        <h2 class="h6 {{ $loop->first ? '' : 'mt-4' }} mb-2">{{ $heading }}</h2>

                        @foreach($paragraphs as $paragraph)
                            <p class="mb-2 guide-body">{{ $paragraph }}</p>
                        @endforeach
                    @endforeach

                    {{-- The screen this is about, offered only to somebody who
                         may actually open it. Section 9b: never show a link
                         that leads to "access denied" — and the guide is open
                         to everybody, so this is the one thing on the page that
                         has to be asked about. --}}
                    @if(isset($topic['route']) && Route::has($topic['route'])
                        && ($topic['permission'] === null || auth()->user()->hasPermission($topic['permission'])))
                        <a href="{{ route($topic['route']) }}" class="btn btn-primary mt-4">
                            {{ __('Open this screen') }}
                            <i class="bi bi-arrow-right" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </article>
        </div>

        <div class="col-12 col-lg-4">
            @if(count($siblings) > 0)
                <div class="card">
                    <div class="card-header fw-semibold">{{ $group }}</div>

                    <div class="list-group list-group-flush">
                        @foreach($siblings as $slug => $sibling)
                            <a href="{{ route('guide.show', $slug) }}"
                               class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                                <i class="bi bi-{{ $sibling['icon'] }} text-primary flex-shrink-0" aria-hidden="true"></i>
                                <span class="small">{{ $sibling['title'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
