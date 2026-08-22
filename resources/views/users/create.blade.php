@extends('layouts.app')
@section('title', __('New user'))
@section('back')
    <x-back-link :to="route('users.index')" :label="__('Users')" remember="users" permission="users.view" />
@endsection

@section('content')
    <form action="{{ route('users.store') }}" method="POST" data-guard-submit>
        @csrf
        @include('users._form')
    </form>
@endsection
