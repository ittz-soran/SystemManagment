@extends('layouts.app')

@section('title', __('Import & export'))
@section('subheading', __('Move lists in and out as spreadsheets, and archive periods you have finished with'))

@section('content')
    {{-- The distinction that matters, said once and plainly. --}}
    <div class="alert alert-secondary d-flex gap-2 py-2">
        <i class="bi bi-info-circle mt-1"></i>
        <div>
            {{ __('Importing moves names, prices and contact details — never stock or money.') }}
            {{ __('Stock changes through a purchase or a stock adjustment, and a balance changes through a sale, a purchase or a payment.') }}
            <div class="small mt-1">
                {{ __('To move everything, including stock and the ledger, use a backup instead.') }}
                @can('settings.manage')
                    <a href="{{ route('settings.edit') }}#backups">{{ __('Backups') }}</a>
                @endcan
            </div>
        </div>
    </div>

    {{-- Transactions: exported and hidden, never removed. --}}
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-archive"></i>{{ __('Sales, purchases and money') }}
        </div>
        <div class="card-body">
            @if($archivedBefore)
                <div class="alert alert-secondary d-flex flex-wrap align-items-center gap-2 py-2">
                    <i class="bi bi-eye-slash"></i>
                    <span>
                        {{ __('Everything before :date is archived and hidden from the lists. It is all still here.', [
                            'date' => $archivedBefore->format(setting('date_format', 'Y-m-d')),
                        ]) }}
                    </span>

                    @can('settings.manage')
                        <form action="{{ route('data.period.unarchive') }}" method="POST" class="ms-auto">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-outline-secondary">{{ __('Show it again') }}</button>
                        </form>
                    @endcan
                </div>
            @endif

            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="fw-semibold mb-1">{{ __('Export a period') }}</div>
                    <p class="small text-secondary">
                        {{ __('A ZIP of spreadsheets: sales, purchases, returns, payments, expenses, stock movements and the ledger. Nothing changes in the system.') }}
                    </p>

                    @can('reports.view')
                        <form action="{{ route('data.period.export') }}" method="POST" class="row g-2" data-guard-submit>
                            @csrf
                            <div class="col-sm-5">
                                <label for="from" class="form-label small">{{ __('From') }}</label>
                                <input id="from" type="date" name="from" class="form-control form-control-sm">
                                <div class="form-text">{{ __('Blank means the beginning.') }}</div>
                            </div>
                            <div class="col-sm-5">
                                <label for="to" class="form-label small">{{ __('To') }}</label>
                                <input id="to" type="date" name="to" class="form-control form-control-sm"
                                       value="{{ today()->toDateString() }}" required>
                            </div>
                            <div class="col-sm-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-sm btn-outline-primary w-100"
                                        data-submitting-text="…">
                                    {{ __('Export') }}
                                </button>
                            </div>
                        </form>
                    @endcan
                </div>

                @can('settings.manage')
                    <div class="col-lg-6 border-start-lg">
                        <div class="fw-semibold mb-1">{{ __('Archive a period') }}</div>
                        <p class="small text-secondary mb-2">
                            {{ __('The same file, and then the lists stop showing that period.') }}
                            <strong>{{ __('Nothing is deleted.') }}</strong>
                            {{ __('Stock levels, costs and balances are all worked out from the whole history, so removing old documents would break them.') }}
                        </p>

                        <form action="{{ route('data.period.archive') }}" method="POST" class="row g-2" data-guard-submit
                              onsubmit="return confirm(@js(__('Archive everything up to this date? It stays in the system and stops appearing in the lists.')))">
                            @csrf
                            <div class="col-sm-7">
                                <label for="through" class="form-label small">{{ __('Everything up to and including') }}</label>
                                <input id="through" type="date" name="through" class="form-control form-control-sm"
                                       value="{{ $suggested }}" max="{{ today()->subDay()->toDateString() }}" required>
                                @error('through')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-sm-5 d-flex align-items-end">
                                <button type="submit" class="btn btn-sm btn-outline-danger w-100"
                                        data-submitting-text="{{ __('Archiving…') }}">
                                    {{ __('Archive') }}
                                </button>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="freeze" value="1"
                                           id="freeze" checked>
                                    <label class="form-check-label small" for="freeze">
                                        {{ __('Also freeze the period, so nothing in it can be edited or deleted.') }}
                                    </label>
                                </div>
                            </div>
                        </form>
                    </div>
                @endcan
            </div>
        </div>
    </div>

    <div class="row g-3">
        @foreach($entities as $entity)
            @php
                $columns = $transfer->columns($entity);
                $readOnly = $transfer->readOnlyColumns($entity);
                $mayImport = Gate::allows($entity.'.create') && Gate::allows($entity.'.edit');
            @endphp

            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">{{ $transfer->label($entity) }}</div>
                    <div class="card-body d-flex flex-column">
                        <div class="small text-secondary mb-3">
                            {{ __('Columns:') }}
                            <span dir="ltr">{{ implode(', ', $columns) }}</span>

                            @if($readOnly !== [])
                                <div class="mt-1">
                                    {{ __('Also exported, but ignored on the way back in:') }}
                                    <span dir="ltr">{{ implode(', ', $readOnly) }}</span>
                                </div>
                            @endif
                        </div>

                        <div class="d-flex flex-wrap gap-2 mb-3">
                            @can($entity.'.view')
                                <a href="{{ route('data.export', $entity) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-download me-1"></i>{{ __('Export') }}
                                </a>
                                <a href="{{ route('data.export', [$entity, 'template' => 1]) }}"
                                   class="btn btn-sm btn-outline-secondary">
                                    {{ __('Empty template') }}
                                </a>
                            @endcan
                        </div>

                        @if($mayImport)
                            <form action="{{ route('data.preview', $entity) }}" method="POST"
                                  enctype="multipart/form-data" class="mt-auto" data-guard-submit>
                                @csrf
                                <label for="file-{{ $entity }}" class="form-label small">{{ __('Import a file') }}</label>
                                <div class="input-group input-group-sm">
                                    <input id="file-{{ $entity }}" type="file" name="file" accept=".csv,text/csv"
                                           class="form-control" required>
                                    <button type="submit" class="btn btn-outline-primary"
                                            data-submitting-text="{{ __('Reading…') }}">
                                        {{ __('Check the file') }}
                                    </button>
                                </div>
                                <div class="form-text">
                                    {{ __('Nothing is saved yet — you see what would change first.') }}
                                </div>
                            </form>
                        @else
                            <div class="mt-auto small text-secondary">
                                {{ __('Importing needs permission to both add and change :label.', ['label' => mb_strtolower($transfer->label($entity))]) }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endsection
