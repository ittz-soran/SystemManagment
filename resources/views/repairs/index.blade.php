@extends('layouts.app')

@section('title', __('Repairs'))
@section('subheading', __('What is on the bench, and whose it is'))

@section('actions')
    @can('repairs.create')
        <a href="{{ route('repairs.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>{{ __('Take in a repair') }}
        </a>
    @endcan
@endsection

@section('content')
    <x-lens-note :lens="$lens" />

    {{-- ⚠️ Open by default. A repairs list is a bench rather than a history:
         what it is opened to ask is "what am I holding", and a year of
         collected tickets buries that on the first page. --}}
    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label for="search" class="form-label small">{{ __('Find') }}</label>
                <input id="search" type="search" name="search" value="{{ request('search') }}"
                       class="form-control form-control-sm"
                       placeholder="{{ __('Ticket, device, IMEI, fault or customer') }}">
            </div>

            <div class="col-6 col-md-3">
                <label for="status" class="form-label small">{{ __('Status') }}</label>
                <select id="status" name="status" class="form-select form-select-sm">
                    <option value="open" @selected($status === 'open')>
                        {{ __('On the bench') }} ({{ number_format($counts['open']) }})
                    </option>
                    @foreach(App\Models\Repair::STATUSES as $value)
                        <option value="{{ $value }}" @selected($status === $value)>
                            {{ __(Str::headline($value)) }} ({{ number_format($counts[$value]) }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-6 col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-outline-secondary flex-fill">{{ __('Filter') }}</button>
                <a href="{{ route('repairs.index') }}" class="btn btn-sm btn-outline-secondary"
                   title="{{ __('Clear') }}"><i class="bi bi-x-lg"></i></a>
            </div>
        </div>
    </form>

    @if($repairs->isEmpty())
        <x-empty-state icon="tools"
                       :message="__('Nothing on the bench. Take a repair in when a customer brings something to be fixed.')" />
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-cards">
                    <thead>
                    <tr>
                        <th>{{ __('Ticket') }}</th>
                        <th>{{ __('Customer') }}</th>
                        <th>{{ __('Fault') }}</th>
                        <th>{{ __('Promised') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th class="money">{{ __('Charge') }}</th>
                    </tr>
                    </thead>

                    <tbody>
                    @foreach($repairs as $repair)
                        <tr>
                            <td class="list-card-title">
                                <a href="{{ route('repairs.show', $repair) }}" class="text-decoration-none fw-medium">
                                    {{ $repair->device }}
                                </a>
                                <div class="small text-secondary app-code">{{ $repair->document_no }}</div>
                                @if($repair->identifier)
                                    <div class="small text-secondary app-code" dir="ltr">{{ $repair->identifier }}</div>
                                @endif
                            </td>

                            <td class="small" data-label="{{ __('Customer') }}">
                                {{ $repair->customer->name }}
                                @if($repair->customer->phone)
                                    <div class="text-secondary" dir="ltr">{{ $repair->customer->phone }}</div>
                                @endif
                            </td>

                            <td class="small" data-label="{{ __('Fault') }}">{{ Str::limit($repair->fault, 60) }}</td>

                            <td class="small" data-label="{{ __('Promised') }}">
                                @if($repair->promised_for)
                                    {{-- Late is worth seeing before anything else on the row. --}}
                                    <span class="text-nowrap {{ $repair->isOverdue() ? 'text-danger fw-semibold' : 'text-secondary' }}">
                                        {{ $repair->promised_for->format(setting('date_format', 'Y-m-d')) }}
                                    </span>
                                    @if($repair->isOverdue())
                                        <div class="text-danger">{{ __('Late') }}</div>
                                    @endif
                                @else
                                    <span class="text-secondary">—</span>
                                @endif
                            </td>

                            <td data-label="{{ __('Status') }}">
                                @php
                                    $tone = match ($repair->status) {
                                        App\Models\Repair::STATUS_READY => 'success',
                                        App\Models\Repair::STATUS_IN_PROGRESS => 'warning',
                                        App\Models\Repair::STATUS_COLLECTED => 'secondary',
                                        App\Models\Repair::STATUS_RETURNED => 'danger',
                                        default => 'light',
                                    };
                                @endphp
                                <span class="badge text-bg-{{ $tone }}">{{ __(Str::headline($repair->status)) }}</span>
                                @if($repair->sale)
                                    <div class="small mt-1">
                                        <x-document-link :document="$repair->sale" :kind="false" />
                                    </div>
                                @endif
                            </td>

                            <td class="money fw-semibold" data-label="{{ __('Charge') }}">
                                {{ money($repair->total(), false, $lens) }}
                                @if($repair->estimate !== null && $repair->isOpen())
                                    <div class="small text-secondary fw-normal">
                                        {{ __('quoted :amount', ['amount' => money($repair->estimate, false, $lens)]) }}
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $repairs->links() }}</div>
    @endif
@endsection
