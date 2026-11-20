@extends('layout')
@section('title','Minha licença')
@section('subtitle','Acompanhe as mensalidades do Vendra e abra uma parcela para pagar com Pix ou conferir o recebimento.')
@section('actions')<a class="button secondary" href="{{ route('license.help',['company'=>$tenant->slug]) }}">Ajuda com a licença</a>@endsection
@section('content')
<section class="panel"><h2>Parcelas da licença</h2>@include('license.installments', ['central'=>false])</section>
<section class="panel"><h2>Contratos da empresa</h2>@include('license.contracts', ['central'=>false])</section>
@endsection
