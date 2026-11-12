@extends('layout')
@section('title','Conferência fiscal da venda #'.$sale->id)
@section('subtitle','Prévia para revisão. Não é NFC-e, DANFE nem autorização fiscal.')
@section('content')
<section class="panel">@include('fiscal.summary') @include('fiscal.blockers')
@if($sale->status==='completed')<form method="post" action="{{ route('fiscal.prepare',['company'=>$tenant->slug,'id'=>$sale->id]) }}">@csrf
<input type="hidden" name="request_key" value="{{ old('request_key',(string)\Illuminate\Support\Str::uuid()) }}">
<p>A conferência registra os dados exibidos agora, incluindo as pendências. Não reserva numeração, não transmite e não altera estoque ou financeiro.</p>
<button class="primary">Registrar conferência para revisão</button></form>@endif
<p><a href="{{ route('sale.show',['company'=>$tenant->slug,'id'=>$sale->id]) }}">Voltar à venda</a></p></section>
@endsection
