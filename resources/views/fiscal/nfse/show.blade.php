@extends('layout')
@section('title','DPS #'.$document->number.' · série '.$document->series)
@section('subtitle','Documento preparado localmente. Não é NFS-e autorizada nem DANFSe.')
@section('content')
<section class="panel"><div class="notice"><strong>Sem autorização fiscal</strong><p>A validação local e a assinatura não comprovam aceitação pelo ambiente nacional. Transmissão e consulta ainda não estão disponíveis nesta etapa.</p></div><p><strong>Identificador da DPS:</strong> <span style="overflow-wrap:anywhere">{{ $document->dps_id }}</span></p><p>Venda #{{ $document->sale_id }} · <strong>Total:</strong> R$ {{ number_format($document->total,2,',','.') }}</p><p><strong>Situação:</strong> {{ $document->status==='draft_validated'?'DPS validada localmente · não transmitida':$document->status }}</p>
@if($document->blocking_reason)<p>{{ $document->blocking_reason }}</p>@endif
<ul>@foreach(($report['blockers']??[]) as $blocker)<li>{{ is_array($blocker)?($blocker['message']??'Confira a configuração.'):$blocker }}</li>@endforeach</ul>
<div class="actions"><a class="button secondary" href="{{ route('nfse.xml',['company'=>$tenant->slug,'id'=>$document->id]) }}">Baixar XML da DPS preparada</a><a href="{{ route('nfse.index',['company'=>$tenant->slug]) }}">Voltar à NFS-e nacional</a></div><p class="hint">O XML é da DPS preparada, não de uma NFS-e autorizada. Não há PDF DANFSe ou cancelamento fiscal nesta etapa.</p></section>
@endsection
