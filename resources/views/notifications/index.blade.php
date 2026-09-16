@extends('layouts.app')

@section('title', __('Notifications'))

@section('content')
    {{--
        Everything this reader is allowed to be told about, newest first.

        Not the activity log: that screen is the shop's whole record and sits
        behind a permission most people do not hold. This one is per reader —
        a salesperson sees the entries from the modules they can open, and
        their own sign-ins, and nothing else. See NotificationFeed.
    --}}
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <i class="bi bi-bell fs-3 text-secondary" aria-hidden="true"></i>

            <div class="flex-grow-1 min-w-0">
                <div class="fw-semibold">{{ __('What you are hearing') }}</div>
                <div class="text-secondary small">
                    {{ __('Right now: :tiers.', [
                        'tiers' => collect($tiers)
                            ->map(fn ($tier) => App\Support\Notifications::label($tier))
                            ->join(', '),
                    ]) }}
                </div>
            </div>

            <a href="{{ route('profile.edit') }}" class="btn btn-sm btn-outline-secondary">
                {{ __('Change this') }}
            </a>
        </div>
    </div>

    @if($logs->isEmpty())
        <div class="card">
            <x-empty-state icon="bell" :message="__('Nothing yet. This fills up as other people work in the shop.')" />
        </div>
    @else
        <div class="card">
            <div class="list-group list-group-flush">
                @foreach($logs as $log)
                    @php($href = App\Support\Notifications::linkFor($log->module, $log->action, $log->record_id))
                    @php($unread = $log->id > $mark)

                    <a @if($href) href="{{ $href }}" @endif
                       class="list-group-item d-flex gap-3 align-items-start {{ $unread ? 'app-bell-row is-unread' : '' }}">
                        <i class="bi {{ App\Support\Notifications::iconFor($log->action) }} mt-1 flex-shrink-0 {{ $log->tier === App\Support\Notifications::ALERT ? 'text-danger' : 'text-secondary' }}"
                           aria-hidden="true"></i>

                        <span class="min-w-0 flex-grow-1">
                            <span class="d-block">{{ $log->description }}</span>
                            <span class="d-block text-secondary small">
                                {{ $log->user?->name ?? __('Somebody') }}
                                · {{ App\Support\Notifications::when($log->created_at) }}
                                · {{ Str::headline($log->module) }}
                            </span>
                        </span>

                        @if($log->tier === App\Support\Notifications::ALERT)
                            <span class="badge text-bg-danger flex-shrink-0">{{ __('Alert') }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>

        <div class="mt-3">{{ $logs->links() }}</div>
    @endif
@endsection
