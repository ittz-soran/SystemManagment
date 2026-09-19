@extends('layouts.app')

@section('title', __('New product'))

@section('back')
    <x-back-link :to="route('products.index')" :label="__('Products')" remember="products" permission="products.view" />
@endsection

@section('actions')
@endsection

@section('content')
    {{-- ⚠️ The price boxes below take the chosen currency; what is stored is
         always base-currency units. On an edit that matters twice — a price
         nobody typed into keeps exactly the figure it had. --}}
    <x-lens-note :lens="$lens" />

    {{-- Section 9b: full page, not a modal — it has opening stock and several
         grouped sections. --}}
    <form action="{{ route('products.store') }}" method="POST" data-guard-submit>
        @csrf
        @include('products._form')
    </form>
@endsection
