@extends('layouts.app')

@section('title', __('Edit product'))
@section('subheading', $product->name)

@section('content')
    <form action="{{ route('products.update', $product) }}" method="POST" data-guard-submit>
        @csrf
        @method('PUT')
        @include('products._form')
    </form>
@endsection
