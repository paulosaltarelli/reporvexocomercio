@extends('layout')
@section('title','NFC-e #'.$document->number.' · série '.$document->series)
@section('subtitle','Ambiente de homologação · sem valor fiscal.')
@section('content')<section class="panel">
<p>Venda #{{ $document->sale_id }} · <strong>{{ ['pending'=>'Na fila','sent'=>'Enviada, aguardando confirmação','uncertain'=>'Consulta necessária','authorized'=>'Autorizada em homologação','rejected'=>'Rejeitada','cancelled'=>'Cancelada em homologação'][$document->state]??$document->state }}</strong></p>
@if($document->official_code)<p>Código SEFAZ: {{ $document->official_code }} · Protocolo: {{ $document->protocol??'Ainda não disponível' }}</p>@endif
@if($document->operator_message)<p>{{ $document->operator_message }}</p>@endif
@if($document->state==='uncertain')<details class="disclosure"><summary>Reenviar após confirmação de referência não encontrada</summary><form class="disclosure-content" method="post" action="{{ route('fiscal.resend',['company'=>$tenant->slug,'id'=>$document->id]) }}">@csrf<label class="checkbox-line"><input type="checkbox" name="same_reference_consent" value="1" required> Solicito reenvio dos mesmos dados, com a mesma referência e numeração, somente se a consulta à SEFAZ confirmar que a referência não existe.</label><button class="button secondary">Solicitar reenvio da mesma referência</button></form></details>@endif
<p>A emissão fiscal é independente do cancelamento comercial. Este fluxo não altera estoque, recebimentos ou caixa.</p>
<div class="actions"><form method="post" action="{{ route('fiscal.consult',['company'=>$tenant->slug,'id'=>$document->id]) }}">@csrf<button class="button secondary">Consultar situação</button></form>
@if(in_array($document->state,['authorized','cancelled']))
@foreach(['xml'=>'Baixar XML','danfe'=>'Baixar DANFE em PDF'] as $kind=>$label)<a class="button" href="{{ route('fiscal.download',['company'=>$tenant->slug,'id'=>$document->id,'kind'=>$kind]) }}">{{ $label }}</a>@endforeach
@if($document->state==='cancelled')<a class="button secondary" href="{{ route('fiscal.download',['company'=>$tenant->slug,'id'=>$document->id,'kind'=>'cancel_xml']) }}">XML de cancelamento</a>@endif
@endif</div>
@if($document->state==='authorized')<details class="disclosure" style="margin-top:24px"><summary>Solicitar cancelamento fiscal</summary><form class="disclosure-content" method="post" action="{{ route('fiscal.cancel',['company'=>$tenant->slug,'id'=>$document->id]) }}">@csrf<div class="field"><label for="reason">Justificativa (15 a 255 caracteres)</label><textarea id="reason" name="reason" minlength="15" maxlength="255" required></textarea></div><button class="button danger">Solicitar cancelamento à SEFAZ</button></form></details>@endif
<p><a href="{{ route('fiscal',['company'=>$tenant->slug]) }}">Voltar ao fiscal</a></p></section>@endsection
