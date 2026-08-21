@extends('layouts.app')

@section('title', __('Import & export'))
@section('subheading', __('Move product, supplier and customer lists in and out as spreadsheets'))

@section('content')
    {{-- The distinction that matters, said once and plainly. --}}
    <div class="alert alert-secondary d-flex gap-2 py-2">
        <i class="bi bi-info-circle mt-1"></i>
        <div>
            {{ __('This moves names, prices and contact details — never stock or money.') }}
            {{ __('Stock changes through a purchase or a stock adjustment, and a balance changes through a sale, a purchase or a payment.') }}
            <div class="small mt-1">
                {{ __('To move everything, including stock and the ledger, use a backup instead.') }}
                @can('settings.manage')
                    <a href="{{ route('settings.edit') }}#backups">{{ __('Backups') }}</a>
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
