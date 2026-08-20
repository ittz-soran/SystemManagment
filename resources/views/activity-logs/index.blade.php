@extends('layouts.app')

@section('title', __('Activity log'))

@section('content')
    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label for="user_id" class="form-label small">{{ __('User') }}</label>
                <select id="user_id" name="user_id" class="form-select form-select-sm">
                    <option value="">{{ __('Everyone') }}</option>
                    @foreach($users as $user)
                        <option value="{{ $user->id }}" @selected(request('user_id') == $user->id)>{{ $user->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="action" class="form-label small">{{ __('Action') }}</label>
                <select id="action" name="action" class="form-select form-select-sm">
                    <option value="">{{ __('All') }}</option>
                    @foreach($actions as $action)
                        <option value="{{ $action }}" @selected(request('action') === $action)>{{ Str::headline($action) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="module" class="form-label small">{{ __('Module') }}</label>
                <select id="module" name="module" class="form-select form-select-sm">
                    <option value="">{{ __('All') }}</option>
                    @foreach($modules as $module)
                        <option value="{{ $module }}" @selected(request('module') === $module)>{{ Str::headline($module) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="from" class="form-label small">{{ __('From') }}</label>
                <input id="from" type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary">{{ __('Filter') }}</button>
                <a href="{{ route('activity-logs.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Clear') }}</a>
            </div>
        </div>
    </form>

    @if($logs->isEmpty())
        <div class="card">
            <x-empty-state icon="clock-history" :message="__('Nothing has been recorded yet.')" />
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                    <tr>
                        <th>{{ __('When') }}</th>
                        <th>{{ __('User') }}</th>
                        <th>{{ __('Action') }}</th>
                        <th>{{ __('Module') }}</th>
                        <th>{{ __('What happened') }}</th>
                        <th>{{ __('From') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($logs as $log)
                        @php
                            $variant = match ($log->action) {
                                'create' => 'success',
                                'delete' => 'danger',
                                'update' => 'warning',
                                'login', 'logout' => 'info',
                                default => 'secondary',
                            };
                        @endphp
                        <tr>
                            <td class="small" dir="ltr">{{ $log->created_at->format('Y-m-d H:i') }}</td>
                            <td>{{ $log->user->name }}</td>
                            <td><span class="badge text-bg-{{ $variant }}">{{ Str::headline($log->action) }}</span></td>
                            <td class="small text-secondary">{{ Str::headline($log->module) }}</td>
                            <td>
                                {{ $log->description }}
                                @if($log->old_values)
                                    {{-- Section 8: every edit stores the full
                                         previous version. --}}
                                    <details class="small text-secondary mt-1">
                                        <summary>{{ __('Previous values') }}</summary>
                                        <pre class="small mb-0 mt-1" dir="ltr">{{ json_encode($log->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </details>
                                @endif
                            </td>
                            <td class="small text-secondary" dir="ltr">{{ $log->ip_address }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $logs->links() }}</div>
    @endif
@endsection
