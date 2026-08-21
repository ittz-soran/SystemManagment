@extends('layouts.app')

@section('title', __('Check the import'))
@section('subheading', $label)

@section('content')
    {{-- Section 9b: see what will happen before it happens. --}}
    <div class="row g-2 mb-3">
        @foreach([
            ['create', __('To be added'), 'success'],
            ['update', __('To be changed'), 'primary'],
            ['unchanged', __('Already the same'), 'secondary'],
            ['skip', __('Skipped'), 'warning'],
        ] as [$key, $title, $variant])
            <div class="col-6 col-lg-3">
                <div class="card">
                    <div class="card-body py-2">
                        <div class="text-secondary small">{{ $title }}</div>
                        <div class="fs-4 fw-semibold text-{{ $variant }}">{{ $result[$key] }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if($result['rows'] === [])
        <div class="card">
            <x-empty-state icon="file-earmark-x" :message="__('That file has no rows.')" />
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th style="width: 5rem">{{ __('Row') }}</th>
                        <th>{{ __('Name') }}</th>
                        <th style="width: 9rem">{{ __('What happens') }}</th>
                        <th>{{ __('Detail') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($result['rows'] as $row)
                        <tr>
                            <td class="text-secondary" dir="ltr">{{ $row['line'] }}</td>
                            <td class="fw-medium">{{ $row['name'] }}</td>
                            <td>
                                @php
                                    $badge = [
                                        'create' => ['success', __('Add')],
                                        'update' => ['primary', __('Change')],
                                        'unchanged' => ['secondary', __('No change')],
                                        'skip' => ['warning', __('Skip')],
                                    ][$row['action']];
                                @endphp
                                <span class="badge text-bg-{{ $badge[0] }}">{{ $badge[1] }}</span>
                            </td>
                            <td class="small {{ $row['action'] === 'skip' ? 'text-warning-emphasis' : 'text-secondary' }}">
                                {{ $row['note'] }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="d-flex flex-wrap gap-2 mt-3">
        @if($result['create'] + $result['update'] > 0)
            <form action="{{ route('data.import', $entity) }}" method="POST" data-guard-submit
                  onsubmit="return confirm(@js(__('Apply this import? Rows marked Skip are left alone.')))">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <button type="submit" class="btn btn-primary" data-submitting-text="{{ __('Importing…') }}">
                    <i class="bi bi-check2 me-1"></i>{{ __('Apply :count changes', ['count' => $result['create'] + $result['update']]) }}
                </button>
            </form>
        @else
            <span class="align-self-center text-secondary">
                {{ __('There is nothing to apply from this file.') }}
            </span>
        @endif

        <a href="{{ route('data.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
    </div>
@endsection
