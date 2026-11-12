@extends('layout')
@section('title','Conferência fiscal #'.$preparation->id)
@section('subtitle','Registro preservado para revisão. Não houve emissão fiscal.')
@section('content')<section class="panel">
<p>Venda #{{ $preparation->sale_id }} · Registrada em {{ $preparation->created_at->format('d/m/Y H:i') }}. Dados e pendências refletem esse momento; alterações posteriores exigem nova conferência.</p>
@include('fiscal.summary') @include('fiscal.blockers')
<p><a href="{{ route('fiscal.sale',['company'=>$tenant->slug,'id'=>$preparation->sale_id]) }}">Conferir situação atual da venda</a></p>
</section>@endsection
