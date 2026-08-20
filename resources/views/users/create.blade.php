@extends('layouts.app')
@section('title', __('New user'))
@section('content')
    <form action="{{ route('users.store') }}" method="POST" data-guard-submit>
        @csrf
        @include('users._form')
    </form>
@endsection
