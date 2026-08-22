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
@endsection
