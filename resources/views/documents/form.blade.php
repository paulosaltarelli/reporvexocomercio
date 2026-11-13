
@extends('layout')
@section('title',isset($document)?'Editar ordem de serviço #'.$document->id:($type==='quote'?'Novo orçamento':'Nova ordem de serviço'))
@section('subtitle',$type==='quote'?'Planeje os itens e valores para aprovação. O estoque só é baixado quando gerar a venda.':'Registre o atendimento e acompanhe cada etapa. Salvar a OS não baixa estoque nem gera cobrança.')
@section('content')<form method="post" action="{{ isset($document)?route('document.update',['company'=>$tenant->slug,'id'=>$document->id]):route('documents',['company'=>$tenant->slug]) }}">
@csrf
@if(isset($document))<input type="hidden" name="expected_hash" value="{{ old('expected_hash',$editHash) }}">@endif
@unless(isset($document))
<input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="request_key" value="{{ old('request_key',\Illuminate\Support\Str::uuid()) }}">
@endunless
<section class="panel"><h2>Dados do atendimento</h2><div class="form-grid">
@if(isset($document))<div class="field"><label for="os-seller">Vendedor creditado</label><input id="os-seller" value="{{ $document->seller_name??'Não informado' }}" readonly><small class="hint">A edição preserva o vínculo do vendedor. Alterações de responsabilidade são feitas na tela da OS.</small></div>@else
@include('components.sales.seller-field')
@endif
<div class="field"><label>Cliente</label><select name="customer_id"><option value="">Não informado</option>
@foreach($customers as $c)<option value="{{ $c->id }}" 
@selected(old('customer_id',$selectedCustomerId??null)==$c->id)>{{ $c->name }}</option>
@endforeach</select></div><div class="field"><label>{{ $type==='quote'?'Validade do orçamento':'Previsão de conclusão / entrega' }}</label><input type="date" name="valid_until" value="{{ old('valid_until',$document->valid_until??null) }}"></div><div class="field"><label>Equipamento ou veículo · série ou placa</label><input name="asset" maxlength="255" value="{{ old('asset',$document->asset??null) }}"></div><div class="field"><label>Responsável técnico</label><input name="technician" maxlength="255" value="{{ old('technician',$document->technician??null) }}"></div><div class="field full"><label>{{ $type==='quote'?'Descrição e observações':'Relato do cliente, diagnóstico e observações' }}</label><textarea name="notes" maxlength="2000">{{ old('notes',$document->notes??null) }}</textarea></div></div></section>@if($type==='work_order')<div class="notice">Você pode receber o atendimento sem itens e completar o diagnóstico depois, enquanto a OS estiver aberta. Para aprovar ou gerar venda, informe os produtos e serviços. Não há reserva ou baixa de estoque nesta etapa.</div>@endif
@include('components.sales.entry-items',['itemsHeading'=>'Produtos e serviços previstos','initialItems'=>$initialItems??[],'allowEmptyItems'=>$type==='work_order'])
<div class="actions"><button class="primary" @disabled(!isset($document) && $currentUser->role!=='admin' && (!$currentSalesperson || !$currentSalesperson->active))>{{ isset($document)?'Salvar alterações da OS':($type==='quote'?'Salvar orçamento':'Salvar ordem de serviço') }}</button><a href="{{ route('desk',['company'=>$tenant->slug,'salesperson_id'=>$selectedSalespersonId??($currentSalesperson??null)?->id,'customer_id'=>$selectedCustomerId??null]) }}">Voltar ao painel</a></div></form>

@endsection
