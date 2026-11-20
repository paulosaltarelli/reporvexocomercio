@extends('layout')
@section('title',isset($document)?'Editar ordem de serviço #'.$document->id:($type==='quote'?'Novo orçamento':'Nova ordem de serviço'))
@section('subtitle',$type==='quote'?'Prepare os itens e valores para aprovação.':'Registre o cliente, o equipamento e o motivo do atendimento.')
@section('content')
<form class="document-form" method="post" action="{{ isset($document)?route('document.update',['company'=>$tenant->slug,'id'=>$document->id]):route('documents',['company'=>$tenant->slug]) }}">
@csrf
@if(isset($document))<input type="hidden" name="expected_hash" value="{{ old('expected_hash',$editHash) }}">@else
<input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="request_key" value="{{ old('request_key',\Illuminate\Support\Str::uuid()) }}">
@endif
<section class="panel"><h2>Dados do atendimento</h2><div class="form-grid">
@include('components.sales.customer-field',['customerFieldId'=>'document-customer'])
@if(isset($document))<div class="field"><label for="os-seller">Vendedor creditado</label><input id="os-seller" value="{{ $document->seller_name??'Não informado' }}" readonly><small class="hint">Vínculo preservado neste atendimento.</small></div>@else
@include('components.sales.seller-field')
@endif
<div class="field"><label for="document-due">{{ $type==='quote'?'Validade do orçamento':'Previsão de entrega (opcional)' }}</label><input id="document-due" type="date" name="valid_until" value="{{ old('valid_until',$document->valid_until??null) }}"></div>
<div class="field"><label for="document-asset">Equipamento</label><input id="document-asset" name="asset" maxlength="255" placeholder="Modelo ou número de série" value="{{ old('asset',$document->asset??null) }}"></div>
<div class="field"><label for="document-technician">Responsável técnico</label><input id="document-technician" name="technician" maxlength="255" value="{{ old('technician',$document->technician??null) }}"></div>
<div class="field full"><label for="document-notes">{{ $type==='quote'?'Observações':'Relato do cliente e diagnóstico' }}</label><textarea id="document-notes" name="notes" maxlength="2000" placeholder="{{ $type==='quote'?'Condições e informações para o cliente':'Descreva o motivo do atendimento e o que já foi identificado' }}">{{ old('notes',$document->notes??null) }}</textarea></div>
</div></section>
@if($type==='work_order')<div class="document-guidance"><span class="guidance-dot" aria-hidden="true"></span><p>Produtos e serviços são opcionais na abertura da OS.</p><details><summary>Entenda as etapas</summary><p>Inicie o serviço sem aprovação. Acrescente itens e valores durante o atendimento, antes de finalizar. Salvar a OS não reserva estoque nem gera cobrança; esses lançamentos ocorrem ao confirmar a venda.</p></details></div>@endif
@include('components.sales.entry-items',['itemsHeading'=>$type==='quote'?'Itens do orçamento':'Produtos e serviços previstos','initialItems'=>$initialItems??[],'allowEmptyItems'=>$type==='work_order','splitItemTotals'=>$type==='work_order'])
<div class="actions form-actions"><button class="primary" @disabled(!isset($document) && !$currentUser->allows('sales.seller') && (!$currentSalesperson || !$currentSalesperson->active))>{{ isset($document)?'Salvar alterações da OS':($type==='quote'?'Salvar orçamento':'Salvar ordem de serviço') }}</button>
@if(isset($document))
<a class="button secondary" href="{{ route('document.show',['company'=>$tenant->slug,'id'=>$document->id]) }}">Cancelar edição</a>
@elseif($currentUser->allows('desk.use'))
<a class="button secondary" href="{{ route('desk',['company'=>$tenant->slug,'salesperson_id'=>$selectedSalespersonId??($currentSalesperson??null)?->id,'customer_id'=>$selectedCustomerId??null]) }}">Voltar ao caixa</a>
@else
<a class="button secondary" href="{{ route('documents',['company'=>$tenant->slug]) }}">Voltar à lista</a>
@endif
</div>
</form>
@include('components.quick-create')
@endsection
