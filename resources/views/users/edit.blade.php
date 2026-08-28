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

    {{-- Who changed this account, when, and what they changed. On a hosted
         shop this is the one worth having: it is where a role, a password and
         a set of permissions get handed out. --}}
    <x-record-history :for="$user" />
@endsection
