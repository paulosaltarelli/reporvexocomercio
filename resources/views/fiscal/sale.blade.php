@extends('layout')
@section('title','Conferência fiscal da venda #'.$sale->id)
@section('subtitle','Prévia para revisão. Não é NF-e, NFC-e, DANFE nem autorização fiscal.')
@section('content')
@include('fiscal.nfse.entry',['nfseSale'=>$sale])
<section class="panel"><h2>Simular o fluxo sem certificado</h2><div class="notice"><strong>Simulação interna · sem valor fiscal</strong><p>Não se comunica com a SEFAZ, não usa certificado e não gera NFC-e, DANFE ou XML fiscal. As pendências da conferência abaixo não impedem o teste local.</p></div>
@if($sale->status==='completed')<form method="post" action="{{ route('fiscal.simulations.create',['company'=>$tenant->slug,'id'=>$sale->id]) }}">
@csrf
<input type="hidden" name="request_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<div class="field"><label for="simulation-scenario">Cenário de teste</label><select id="simulation-scenario" name="scenario" required><option value="accepted">Aceite simulado</option><option value="rejected">Rejeição simulada</option><option value="uncertain">Timeout e consulta posterior simulados</option></select><small class="hint">O resultado é determinado pelo cenário escolhido; não representa validação tributária.</small></div><div class="actions"><button class="primary">Iniciar simulação interna</button><a href="{{ route('fiscal.simulations.index',['company'=>$tenant->slug]) }}">Histórico de simulações</a></div></form>@else<p>Esta venda foi cancelada. Abra uma venda concluída para iniciar um novo teste.</p>@endif</section>

<section class="panel"><h2>Conferência para homologação NFC-e · modelo 65</h2><p class="hint">As pendências desta seção se referem à NFC-e. A NF-e possui configuração própria abaixo; a simulação interna é independente.</p>@include('fiscal.summary') @include('fiscal.blockers')
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

@if($sale->status==='completed')
<section class="panel"><h2>Emitir NF-e · modelo 55 · homologação</h2><p>Fluxo para venda interna de mercadorias, com destinatário identificado e endereço fiscal completo, sem frete, ST ou IPI. Serviços não são incluídos. A NF-e utiliza configuração e numeração próprias, sem CSC.</p>
@if($sale->customer_id)
<p>Destinatário: <strong>{{ $sale->customer?->name }}</strong>. <a href="{{ route('nfe.customer',['company'=>$tenant->slug,'id'=>$sale->customer_id]) }}">Conferir identificação e endereço fiscal</a></p>
<form method="post" action="{{ route('nfe.emit',['company'=>$tenant->slug,'id'=>$sale->id]) }}">@csrf
<div class="form-grid"><div class="field"><label for="nfe_destination_state">UF de destino</label><input id="nfe_destination_state" name="destination_state" maxlength="2" required value="{{ old('destination_state',$sale->customer?->fiscal_state) }}"></div><div class="field"><label for="nfe_presence">Presença do comprador</label><select id="nfe_presence" name="presence"><option value="1">Presencial</option></select></div><div class="field"><label for="nfe_consumer_final">É consumidor final nesta operação?</label><select id="nfe_consumer_final" name="consumer_final" required><option value="">Confirme a operação</option><option value="1" @selected((string)old('consumer_final')==='1')>Sim</option><option value="0" @selected((string)old('consumer_final')==='0')>Não</option></select></div></div>
@if($sale->payment_method==='pix')
<div class="field"><label for="nfe_pix_mode">Modalidade real do Pix recebido</label><select id="nfe_pix_mode" name="pix_mode" required><option value="">Confirme a modalidade</option><option value="dynamic" @selected(old('pix_mode')==='dynamic')>Pix dinâmico · cobrança específica</option><option value="static" @selected(old('pix_mode')==='static')>Pix estático · chave ou QR Code reutilizável</option></select></div>
@endif
<label class="checkbox-line"><input type="checkbox" name="homologation_consent" value="1" required> Autorizo a transmissão desta venda à SEFAZ para NF-e em homologação, sem valor fiscal.</label><button class="primary">Solicitar NF-e em homologação</button><p class="hint">A solicitação não altera estoque ou financeiro novamente. A autorização depende da SEFAZ e dos dados validados.</p></form>
@else
<div class="notice">Esta venda não tem destinatário identificado. A NF-e exige cliente com CPF/CNPJ válido e endereço fiscal completo.</div>
@endif
</section>
@endif
@endsection
