
@extends('layout')
@section('title','Nova venda')
@section('subtitle','Selecione os itens e confira os valores antes de finalizar. O estoque será baixado na confirmação.')
@section('content')@include('components.sales.checkout-form')
@endsection
