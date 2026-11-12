
@extends('layout')
@section('title','Nova venda')
@section('subtitle','Selecione os itens e confira os valores antes de finalizar. O estoque será baixado na confirmação.')
@section('content')<form method="post" action="{{ route('sales',['company'=>$tenant->slug]) }}">
@csrf<input type="hidden" name="request_key" value="{{ old('request_key',\Illuminate\Support\Str::uuid()) }}"><section class="panel"><h2>Cliente e pagamento</h2><div class="form-grid">
@include('components.sales.seller-field')<div class="field"><label>Cliente</label><select name="customer_id"><option value="">Consumidor final</option>
@foreach($customers as $c)<option value="{{ $c->id }}" 
@selected(old('customer_id')==$c->id)>{{ $c->name }}</option>
@endforeach</select></div><div class="field"><label>Forma de pagamento</label><select name="payment_method">
@foreach(['cash'=>'Dinheiro','pix'=>'Pix','credit'=>'Cartão de crédito','debit'=>'Cartão de débito','transfer'=>'Transferência','invoice'=>'A prazo'] as $v=>$l)<option value="{{ $v }}" 
@selected(old('payment_method')===$v)>{{ $l }}</option>
@endforeach</select></div><div class="field"><label>Recebimento</label><select name="paid"><option value="1" 
@selected(old('paid','1')==='1')>Já recebido</option><option value="0" 
@selected(old('paid')==='0')>Pendente / a receber</option></select></div><div class="field"><label>Vencimento (obrigatório se pendente)</label><input type="date" name="due_date" value="{{ old('due_date') }}"></div><div class="field"><label>Número de parcelas</label><input name="installments" type="number" min="1" max="24" step="1" value="{{ old('installments',1) }}" required><small class="hint">Até 24 parcelas mensais para vendas a receber, sem juros automáticos.</small></div><div class="field"><label>Observações</label><input name="notes" maxlength="1000" value="{{ old('notes') }}"></div></div><p class="hint">O registro de Pix/cartão é manual; não há cobrança nem conciliação bancária automática.</p></section>@include('components.sales.entry-items',['itemsHeading'=>'Itens da venda'])
<div class="actions"><button class="primary">Confirmar venda</button><a href="{{ route('sales',['company'=>$tenant->slug]) }}">Voltar</a></div></form>
@endsection
