
@extends('layout')
@section('title',$type==='quote'?'Novo orçamento':'Nova ordem de serviço')
@section('subtitle','Planeje o atendimento. O estoque só será baixado quando o documento gerar uma venda.')
@section('content')<form method="post" action="{{ route('documents',['company'=>$tenant->slug]) }}">
@csrf<input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="request_key" value="{{ old('request_key',\Illuminate\Support\Str::uuid()) }}"><section class="panel"><h2>Dados do atendimento</h2><div class="form-grid"><div class="field"><label>Cliente</label><select name="customer_id"><option value="">Não informado</option>
@foreach($customers as $c)<option value="{{ $c->id }}" 
@selected(old('customer_id')==$c->id)>{{ $c->name }}</option>
@endforeach</select></div><div class="field"><label>Validade / previsão de entrega</label><input type="date" name="valid_until" value="{{ old('valid_until') }}"></div><div class="field"><label>Equipamento / série / veículo / placa</label><input name="asset" maxlength="255" value="{{ old('asset') }}"></div><div class="field"><label>Responsável técnico</label><input name="technician" maxlength="255" value="{{ old('technician') }}"></div><div class="field full"><label>Descrição, diagnóstico e observações</label><textarea name="notes" maxlength="2000">{{ old('notes') }}</textarea></div></div></section>@include('components.sales.entry-items',['itemsHeading'=>'Produtos e serviços previstos'])
<div class="actions"><button class="primary">Salvar documento</button><a href="{{ route('documents',['company'=>$tenant->slug]) }}">Voltar</a></div></form>

@endsection
