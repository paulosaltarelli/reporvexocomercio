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
@if($sale->status==='completed')<section class="panel"><h2>Emitir NFC-e em homologação</h2><p>Este fluxo atende venda presencial dentro da UF, já recebida em dinheiro, Pix ou transferência. Somente vendas compostas integralmente por mercadorias são aceitas. Vendas com serviços ficam bloqueadas neste fluxo; serviços dependem de NFS-e. Confira as regras do produto e a configuração em Minha empresa.</p><form method="post" action="{{ route('fiscal.emit',['company'=>$tenant->slug,'id'=>$sale->id]) }}">@csrf
<div class="form-grid"><div class="field"><label for="destination_state">UF de destino desta venda</label><input id="destination_state" name="destination_state" maxlength="2" required></div><div class="field"><label for="presence">Presença do comprador</label><select id="presence" name="presence"><option value="1">Presencial</option></select></div></div>
@if($sale->payment_method==='pix')
<div class="field"><label for="pix_mode">Modalidade do Pix recebido</label><select id="pix_mode" name="pix_mode" required><option value="">Confirme como o Pix foi recebido</option><option value="dynamic" @selected(old('pix_mode')==='dynamic')>Pix dinâmico · cobrança específica para esta venda</option><option value="static" @selected(old('pix_mode')==='static')>Pix estático · chave ou QR Code reutilizável</option></select><small class="hint">Informe a modalidade real do recebimento. A forma de pagamento “Pix” sozinha não determina esta informação.</small></div>
@endif
<label class="checkbox-line"><input type="checkbox" name="consumer_final" value="1" required> Confirmo que é uma venda a consumidor final.</label>
<label class="checkbox-line"><input type="checkbox" name="not_taxpayer" value="1" required> Destinatário não contribuinte de ICMS nesta operação.</label>
<label class="checkbox-line"><input type="checkbox" name="homologation_consent" value="1" required> Autorizo transmitir os dados desta empresa e venda à SEFAZ para teste em homologação, sem valor fiscal.</label>
<button class="primary">Solicitar emissão em homologação</button></form></section>@endif
@endsection
