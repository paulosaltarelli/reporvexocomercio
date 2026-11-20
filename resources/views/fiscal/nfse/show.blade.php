@extends('layout')
@section('title','DPS arquivada #'.$document->number.' · série '.$document->series)
@section('subtitle','Consulta ao registro histórico da empresa.')
@section('content')
<section class="panel">
    <p><strong>Identificador da DPS</strong> {{ $document->dps_id }}</p>
    <p><strong>Venda</strong> #{{ $document->sale_id }}</p>
    <p><strong>Total</strong> R$ {{ number_format($document->total,2,',','.') }}</p>
    <p><strong>Situação registrada</strong> {{ $document->status==='draft_validated'?'Rascunho local sem autorização fiscal':$document->status }}</p>
    @include('fiscal.work-order-link')
    <div class="actions">
        <a class="button secondary" href="{{ route('nfse.xml',['company'=>$tenant->slug,'id'=>$document->id]) }}">Baixar XML arquivado</a>
        <a href="{{ route('nfse.index',['company'=>$tenant->slug]) }}">Voltar ao arquivo</a>
    </div>
</section>
@endsection
