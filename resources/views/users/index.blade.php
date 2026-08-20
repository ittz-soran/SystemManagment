@extends('layouts.app')

@section('title', __('Users'))

@section('actions')
    @can('users.create')
        <a href="{{ route('users.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>{{ __('New user') }}
        </a>
    @endcan
@endsection

@section('content')
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                <tr>
                    <th>{{ __('Name') }}</th>
                    <th>{{ __('Role') }}</th>
                    <th>{{ __('Language') }}</th>
                    <th class="money">{{ __('Permissions') }}</th>
                    <th class="text-end">{{ __('Actions') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($users as $user)
                    <tr class="{{ $user->is_active ? '' : 'opacity-50' }}">
                        <td>
                            <div class="fw-medium">{{ $user->name }}</div>
                            <div class="small text-secondary" dir="ltr">{{ $user->email }}</div>
                        </td>
                        <td>
                            <span class="badge text-bg-{{ $user->isAdmin() ? 'primary' : 'light' }}">
                                {{ $user->isAdmin() ? __('Admin') : __('User') }}
                            </span>
                        </td>
                        <td>{{ \App\Http\Middleware\SetUserPreferences::LANGUAGES[$user->language] ?? $user->language }}</td>
                        <td class="money text-secondary">
                            {{ $user->isAdmin() ? __('All') : number_format($user->permissions_count) }}
                        </td>
                        <td class="text-end">
                            <x-row-actions
                                :edit="Gate::allows('users.edit') ? route('users.edit', $user) : null"
                                :delete="Gate::allows('users.delete') && ! $user->is(auth()->user()) ? route('users.destroy', $user) : null"
                                :delete-label="__('Delete :name? Their documents keep them as the author.', ['name' => $user->name])" />
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $users->links() }}</div>
@endsection
