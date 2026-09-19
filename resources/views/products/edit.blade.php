@extends('layouts.app')

@section('title', __('Edit product'))
@section('subheading', $product->name)

@section('back')
    <x-back-link :to="route('products.show', $product)" :label="$product->name" permission="products.view" />
@endsection

@section('actions')
@endsection

@section('content')
    {{-- ⚠️ The price boxes below take the chosen currency; what is stored is
         always base-currency units. On an edit that matters twice — a price
         nobody typed into keeps exactly the figure it had. --}}
    <x-lens-note :lens="$lens" />

    <form action="{{ route('products.update', $product) }}" method="POST" data-guard-submit>
        @csrf
        @method('PUT')
        @include('products._form')
    </form>
@endsection
