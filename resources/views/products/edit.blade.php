@extends('layouts.app')

@section('title', __('Edit product'))
@section('subheading', $product->name)

@section('back')
    <x-back-link :to="route('products.show', $product)" :label="$product->name" permission="products.view" />
@endsection

@section('content')
    <form action="{{ route('products.update', $product) }}" method="POST" data-guard-submit>
        @csrf
        @method('PUT')
        @include('products._form')
    </form>
@endsection
