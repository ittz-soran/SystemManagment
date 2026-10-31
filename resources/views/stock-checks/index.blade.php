@extends('layouts.app')

@section('title', __('Stock check'))
@section('subheading', __('Count the shelves product by product: confirm, skip, or put right'))

{{--
    The stock check — PROJECT_DOC Section 9. Soran, 2026-10-30: "stock check
    manually … Confirm (mean all correct not need movment), Skip (Show another
    product), Wrong (mean need correction by ADJ or return)".
--}}
@section('actions')
    @if($seesAll)
        <a href="{{ route('stock-checks.to-fix') }}" class="btn btn-outline-secondary">
            <i class="bi bi-exclamation-triangle me-1"></i>{{ __('To fix') }}
            @if($toFix > 0)
                <span class="badge text-bg-warning ms-1">{{ $toFix }}</span>
            @endif
        </a>
    @endif
@endsection

@section('content')
    @if($mayStart || $maySend)
        <form action="{{ route('stock-checks.store') }}" method="POST" class="card mb-4" data-guard-submit>
            @csrf
            <div class="card-header">{{ $maySend ? __('Start a round, or send one') : __('Start a round') }}</div>
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="scope" class="form-label">{{ __('What to count') }}</label>
                        <select id="scope" name="scope" class="form-select" data-check-scope>
                            <option value="all" @selected(old('scope') === 'all')>{{ __('Every product') }}</option>
                            <option value="category" @selected(old('scope') === 'category')>{{ __('One category') }}</option>
                            @if($rooms->isNotEmpty())
                                <option value="room" @selected(old('scope') === 'room')>{{ __('One stock room') }}</option>
                            @endif
                        </select>
                    </div>
                    <div class="col-md-3" data-check-for="category">
                        <label for="category_id" class="form-label">{{ __('Category') }}</label>
                        <select id="category_id" name="category_id" class="form-select">
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" @selected((int) old('category_id') === $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if($rooms->isNotEmpty())
                        <div class="col-md-3" data-check-for="room">
                            <label for="room_id" class="form-label">{{ __('Stock room') }}</label>
                            <select id="room_id" name="room_id" class="form-select">
                                @foreach($rooms as $room)
                                    <option value="{{ $room->id }}" @selected((int) old('room_id') === $room->id)>{{ $room->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="col-md-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="with_empty" name="with_empty" value="1" @checked(old('with_empty'))>
                            <label class="form-check-label" for="with_empty">{{ __('Also products the system says are at zero') }}</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="blind" name="blind" value="1" @checked(old('blind'))>
                            <label class="form-check-label" for="blind">{{ __('Blind count') }}</label>
                        </div>
                    </div>
                    {{-- Soran, 2026-10-30: one person per round, told by the
                         bell, the phone and the dashboard. --}}
                    @if($maySend)
                        <div class="col-md-3">
                            <label for="assigned_to" class="form-label">{{ __('Send to') }}</label>
                            <select id="assigned_to" name="assigned_to" class="form-select" data-check-send @required(! $mayStart)>
                                @if($mayStart)
                                    <option value="">{{ __('Nobody — I count it') }}</option>
                                @else
                                    <option value="" disabled @selected(! old('assigned_to'))>{{ __('Choose who counts it') }}</option>
                                @endif
                                @foreach($counters as $counter)
                                    <option value="{{ $counter->id }}" @selected((int) old('assigned_to') === $counter->id)>{{ $counter->name }}</option>
                                @endforeach
                            </select>
                            @if($counters->isEmpty())
                                <div class="form-text">{{ __('Nobody else may count stock yet. Give someone the permission in Users.') }}</div>
                            @endif
                        </div>
                    @endif
                </div>
                <div class="form-text mt-2">
                    {{ __('A blind count hides what the system says until the count is typed, so nobody confirms because the screen said so.') }}
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary" data-start-label="{{ __('Start counting') }}" data-send-label="{{ __('Send') }}">
                    <i class="bi bi-clipboard-check me-1"></i><span data-check-submit>{{ $mayStart ? __('Start counting') : __('Send') }}</span>
                </button>
            </div>
        </form>
    @endif

    @if($checks->isEmpty())
        <div class="card">
            <x-empty-state icon="clipboard-check" :message="$seesAll ? __('No stock check yet. Start a round to count the shelves.') : __('Nothing has been sent to you to count.')" />
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-cards">
                    <thead>
                    <tr>
                        <th>{{ __('Round') }}</th>
                        <th>{{ __('What to count') }}</th>
                        <th>{{ __('Started') }}</th>
                        <th>{{ __('Counted by') }}</th>
                        <th>{{ __('Progress') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th class="text-end">{{ __('Actions') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($checks as $check)
                        @php($progress = $check->progress())
                        <tr>
                            <td class="list-card-title fw-medium">
                                <a href="{{ route('stock-checks.show', $check) }}" class="text-decoration-none">{{ $check->document_no }}</a>
                                @if($check->blind)
                                    <span class="badge text-bg-light">{{ __('Blind') }}</span>
                                @endif
                            </td>
                            <td data-label="{{ __('What to count') }}">{{ $check->scopeName() }}</td>
                            <td class="small" data-label="{{ __('Started') }}">
                                <span class="app-code">{{ $check->started_at->format(setting('date_format', 'Y-m-d')) }}</span>
                                <span class="text-secondary">· {{ $check->user->name }}</span>
                            </td>
                            <td data-label="{{ __('Counted by') }}">
                                @if($check->isSent())
                                    <i class="bi bi-person-circle me-1 text-secondary"></i>{{ $check->assignee?->name }}
                                @else
                                    <span class="text-secondary">{{ __('Anyone') }}</span>
                                @endif
                            </td>
                            <td data-label="{{ __('Progress') }}" style="min-width: 10rem">
                                @include('stock-checks.partials.progress', ['progress' => $progress, 'slim' => true])
                            </td>
                            <td data-label="{{ __('Status') }}">
                                @if($check->isFinished())
                                    <span class="badge text-bg-secondary">{{ __('Finished') }}</span>
                                @elseif($check->isSent() && $progress['done'] + $progress['skipped'] === 0)
                                    <span class="badge text-bg-warning">{{ __('Waiting for :name', ['name' => $check->assignee?->name]) }}</span>
                                @else
                                    <span class="badge text-bg-success">{{ __('Open') }}</span>
                                @endif
                            </td>
                            <td class="list-card-actions text-end">
                                @if(! $check->isFinished() && $check->answerableBy(auth()->user()))
                                    <a href="{{ route('stock-checks.next', $check) }}" class="btn btn-sm btn-primary">
                                        {{ $progress['done'] + $progress['skipped'] === 0 ? __('Start') : __('Continue') }}
                                    </a>
                                @endif
                                <a href="{{ route('stock-checks.show', $check) }}" class="btn btn-sm btn-outline-secondary">{{ __('Report') }}</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-3">{{ $checks->links() }}</div>
    @endif

    {{-- Only the box for what was chosen. --}}
    <script>
        (() => {
            const scope = document.querySelector('[data-check-scope]');
            if (!scope) return;
            const show = () => document.querySelectorAll('[data-check-for]').forEach((box) => {
                box.classList.toggle('d-none', box.dataset.checkFor !== scope.value);
            });
            scope.addEventListener('change', show);
            show();

            // The button says what it will do: start counting, or send.
            const send = document.querySelector('[data-check-send]');
            const label = document.querySelector('[data-check-submit]');
            const button = label?.closest('button');
            if (send && label && button) {
                const say = () => label.textContent = send.value ? button.dataset.sendLabel : button.dataset.startLabel;
                send.addEventListener('change', say);
                say();
            }
        })();
    </script>
@endsection
