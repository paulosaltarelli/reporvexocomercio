
@extends('layout')
@section('title','Nova venda')
@section('subtitle','Selecione os itens e confira os valores antes de finalizar. O estoque será baixado na confirmação.')
@section('content')<form method="post" action="{{ route('sales',['company'=>$tenant->slug]) }}">
@csrf<input type="hidden" name="request_key" value="{{ old('request_key',\Illuminate\Support\Str::uuid()) }}"><section class="panel"><h2>Cliente e pagamento</h2><div class="form-grid"><div class="field"><label>Cliente</label><select name="customer_id"><option value="">Consumidor final</option>
@foreach($customers as $c)<option value="{{ $c->id }}" 
@selected(old('customer_id')==$c->id)>{{ $c->name }}</option>
@endforeach</select></div><div class="field"><label>Forma de pagamento</label><select name="payment_method">
@foreach(['cash'=>'Dinheiro','pix'=>'Pix','credit'=>'Cartão de crédito','debit'=>'Cartão de débito','transfer'=>'Transferência','invoice'=>'A prazo'] as $v=>$l)<option value="{{ $v }}" 
@selected(old('payment_method')===$v)>{{ $l }}</option>
@endforeach</select></div><div class="field"><label>Recebimento</label><select name="paid"><option value="1" 
@selected(old('paid','1')==='1')>Já recebido</option><option value="0" 
@selected(old('paid')==='0')>Pendente / a receber</option></select></div><div class="field"><label>Vencimento (obrigatório se pendente)</label><input type="date" name="due_date" value="{{ old('due_date') }}"></div><div class="field"><label>Observações</label><input name="notes" maxlength="1000" value="{{ old('notes') }}"></div></div><p class="hint">O registro de Pix/cartão é manual; não há cobrança nem conciliação bancária automática.</p></section><section class="panel"><h2>Itens da venda</h2><div id="items"></div><button type="button" id="add" class="button secondary">+ Adicionar item</button><div class="actions"><strong id="total">Total: R$ 0,00</strong></div></section><div class="actions"><button class="primary">Confirmar venda</button><a href="{{ route('sales',['company'=>$tenant->slug]) }}">Voltar</a></div></form>
<template id="row"><div class="sale-row"><div class="selection"><label>Produto ou serviço</label><select class="choice" required><option value="">Selecione</option>
@foreach($products as $p)<option value="product:{{ $p->id }}" data-price="{{ $p->sale_price }}">{{ $p->sku }} · {{ $p->name }} (saldo {{ $p->quantity }})</option>
@endforeach 
@foreach($services as $s)<option value="service:{{ $s->id }}" data-price="{{ $s->price }}">Serviço · {{ $s->name }}</option>
@endforeach</select><input type="hidden" class="kind"><input type="hidden" class="item-id"></div><div><label>Quantidade</label><input class="qty" type="number" step="0.001" min="0.001" max="999999" value="1" required></div><div><label>Preço unitário (R$)</label><input class="price" type="number" step="0.01" min="0" max="999999999.99" required></div><button type="button" class="text-button remove">Remover</button></div></template>
@endsection
@push('scripts')<script>let count=0;const previous=Object.values(
@json(old('items',[])));function total(){let cents=0;document.querySelectorAll('.sale-row').forEach(r=>{cents+=Math.round(Number(r.querySelector('.qty').value)*Number(r.querySelector('.price').value)*100);});document.querySelector('#total').textContent='Total: '+(cents/100).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});}function add(data){const row=document.querySelector('#row').content.firstElementChild.cloneNode(true);const idx=count++;['kind','item-id','qty','price'].forEach((c,i)=>row.querySelector('.'+c).name=`items[${idx}][${['kind','id','quantity','unit_price'][i]}]`);row.querySelector('.choice').onchange=e=>{const[k,id]=e.target.value.split(':');row.querySelector('.kind').value=k||'';row.querySelector('.item-id').value=id||'';row.querySelector('.price').value=e.target.selectedOptions[0].dataset.price||'';total();};row.querySelectorAll('input').forEach(e=>e.oninput=total);row.querySelector('.remove').onclick=()=>{row.remove();total();};document.querySelector('#items').append(row);if(data){row.querySelector('.choice').value=data.kind+':'+data.id;row.querySelector('.choice').dispatchEvent(new Event('change'));row.querySelector('.qty').value=data.quantity;row.querySelector('.price').value=data.unit_price;}total();}document.querySelector('#add').onclick=()=>add();if(previous.length)previous.forEach(add);else add();</script>
@endpush
