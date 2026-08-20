@extends('layouts.app')

@section('title', __('New product'))

@section('content')
    {{-- Section 9b: full page, not a modal — it has opening stock and several
         grouped sections. --}}
    <form action="{{ route('products.store') }}" method="POST" data-guard-submit>
        @csrf
        @include('products._form')
    </form>
@endsection
