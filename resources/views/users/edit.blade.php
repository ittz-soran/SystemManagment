@extends('layouts.app')
@section('title', __('Edit user'))
@section('subheading', $user->name)
@section('back')
    <x-back-link :to="route('users.index')" :label="__('Users')" remember="users" permission="users.view" />
@endsection

@section('content')
    <form action="{{ route('users.update', $user) }}" method="POST" data-guard-submit>
        @csrf
        @method('PUT')
        @include('users._form')
    </form>

    {{-- The lost phone. An admin can already set this person's password
         outright, so being able to clear their authenticator hands over nothing
         new — and without it, a member of staff whose phone went in the river
         has no way back that does not involve deleting the account. --}}
    <div class="card mt-3">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-shield-check"></i>{{ __('Way back in') }}

            <span class="badge ms-auto text-bg-{{ $user->hasAuthenticator() ? 'success' : 'secondary' }}">
                {{ $user->hasAuthenticator() ? __('Authenticator on') : __('No authenticator') }}
            </span>
        </div>

        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <div class="flex-grow-1 text-secondary small mb-0" style="max-width: 44rem">
                @if($user->hasAuthenticator())
                    {{ __('This person can set their own new password with the app on their phone. If that phone is lost and the written codes are gone too, clear it here — then they can set it up again from their own preferences.') }}
                @else
                    {{ __('This person has no way to reset their own password. Only you can, by typing a new one above. It is worth asking them to set up an authenticator from their own preferences.') }}
                @endif
            </div>

            @if($user->hasAuthenticator())
                <form action="{{ route('users.authenticator.destroy', $user) }}" method="POST" class="d-inline"
                      onsubmit="return confirm(@js(__('Clear the authenticator for :name? They will have no way back in until they set it up again.', ['name' => $user->name])))">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-sm btn-outline-danger">{{ __('Clear it') }}</button>
                </form>
            @endif
        </div>
    </div>

    {{-- Who changed this account, when, and what they changed. On a hosted
         shop this is the one worth having: it is where a role, a password and
         a set of permissions get handed out. --}}
    <x-record-history :for="$user" />
@endsection
