@extends('layout')
@section('title','Preparar DPS · venda #'.$sale->id)
@section('subtitle','Conferência local de serviço para NFS-e nacional. Não autoriza nem transmite uma nota.')
@section('content')
<section class="panel"><div class="notice"><strong>Produção restrita · preparação local</strong><p>A transmissão e a consulta ao ambiente nacional ainda estão indisponíveis. Preparar uma DPS não gera NFS-e ou DANFSe e não altera estoque, recebimentos ou caixa.</p></div><h2>Venda #{{ $sale->id }}</h2><p>Cliente: {{ $sale->customer?->name??'Não informado' }} · Valor: R$ {{ number_format($sale->total,2,',','.') }}</p><p>A DPS cobre uma linha de serviço sem retenção ou exportação. Em OS faturada, confira a separação abaixo. Vendas com descontos ficam bloqueadas até a classificação fiscal desses descontos.</p>
<ul>@foreach(($report['blockers']??[]) as $blocker)<li>{{ is_array($blocker)?($blocker['message']??'Confira os dados fiscais.'):$blocker }}</li>@endforeach</ul>
<form method="post" action="{{ route('nfse.prepare',['company'=>$tenant->slug,'id'=>$sale->id]) }}">@csrf
@if($split ?? null)
<p>OS #{{ $split['work_order_id'] }} · Mercadorias R$ {{ number_format($split['goods_total'],2,',','.') }} · Serviço R$ {{ number_format($split['services_total'],2,',','.') }}.</p>
<label><input type="checkbox" name="segregate_work_order" value="1" required> Confirmo a preparação da DPS apenas para o serviço desta OS.</label>
@endif
@if($splitError ?? null)<p>{{ $splitError }}</p>@endif
<input type="hidden" name="request_key" value="{{ old('request_key',$requestKey) }}"><div class="field"><label for="nfse-competence">Data de competência do serviço</label><input id="nfse-competence" name="competence" type="date" value="{{ old('competence') }}" required><small class="hint">Informe a data real da prestação; não é preenchida automaticamente pela data da venda.</small></div><label class="checkbox-line"><input type="checkbox" name="operation_scope_confirmed" value="1" required> Confirmei serviço prestado no município do emitente, sem retenções, exportação, benefícios ou outras situações especiais; enquadramento conferido com responsável fiscal.</label><div class="actions"><button class="primary">Preparar e validar DPS localmente</button><a href="{{ route('nfse.index',['company'=>$tenant->slug]) }}">Conferir configuração NFS-e</a></div></form></section>
<p><a href="{{ route('sale.show',['company'=>$tenant->slug,'id'=>$sale->id]) }}">Voltar à venda</a></p>
@endsection
