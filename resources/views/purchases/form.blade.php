@extends('layout')
@section('title','Receber compra')
@section('subtitle','Confira os produtos recebidos. A confirmação registra a entrada e recalcula o custo médio.')
@section('content')
<form method="post" action="{{ route('purchases',['company'=>$tenant->slug]) }}">
    @csrf
    <input type="hidden" name="request_key" value="{{ old('request_key',\Illuminate\Support\Str::uuid()) }}">
    <section class="panel">
        <h2>Fornecedor e recebimento</h2>
        <div class="form-grid">
            <div class="field"><label>Fornecedor</label><select name="supplier_id" required><option value="">Selecione</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected(old('supplier_id')==$supplier->id)>{{ $supplier->name }}</option>@endforeach</select></div>
            <div class="field"><label>Data do recebimento</label><input type="date" name="purchased_on" value="{{ old('purchased_on',now()->toDateString()) }}" max="{{ now()->toDateString() }}" required></div>
            <div class="field"><label>Documento / referência (opcional)</label><input name="reference" value="{{ old('reference') }}" maxlength="100" placeholder="Número da nota ou pedido"></div>
        </div>
        @if($suppliers->isEmpty())
            <p class="hint"><a href="{{ route('catalog',['company'=>$tenant->slug,'kind'=>'suppliers']) }}">Cadastre o fornecedor</a> antes de receber a compra.</p>
        @endif
    </section>
    <section class="panel">
        <h2>Produtos recebidos</h2>
        <p class="hint">Informe o custo efetivo por unidade, já considerando frete e tributos atribuídos ao produto. Não há rateio automático nesta versão.</p>
        <div id="purchase-items"></div>
        <button type="button" id="add-purchase-item" class="button secondary">+ Adicionar produto</button>
        <div class="total-bar"><span>Total da compra</span><strong id="purchase-total" role="status" aria-live="polite">R$ 0,00</strong></div>
        @if($products->isEmpty())
            <p class="hint"><a href="{{ route('product.new',['company'=>$tenant->slug]) }}">Cadastre os produtos</a> antes de receber a compra.</p>
        @endif
    </section>
    <section class="panel">
        <h2>Pagamento</h2>
        <div class="form-grid">
            <div class="field"><label>Situação</label><select name="paid"><option value="1" @selected((string)old('paid','1')==='1')>Já pago</option><option value="0" @selected((string)old('paid')==='0')>A pagar</option></select></div>
            <div class="field"><label>Forma de pagamento</label><select name="payment_method">@foreach(['cash'=>'Dinheiro','pix'=>'Pix','credit'=>'Cartão de crédito','debit'=>'Cartão de débito','transfer'=>'Transferência','invoice'=>'A prazo'] as $value=>$label)<option value="{{ $value }}" @selected(old('payment_method','pix')===$value)>{{ $label }}</option>@endforeach</select></div>
            <div class="field"><label>Número de parcelas</label><input name="installments" type="number" min="1" max="24" step="1" value="{{ old('installments',1) }}" required><small class="hint">Até 24 parcelas mensais para compras a pagar.</small></div>
            <div class="field"><label>Primeiro vencimento</label><input type="date" name="due_date" value="{{ old('due_date') }}"><small class="hint">Obrigatório se a compra estiver a pagar.</small></div>
        </div>
        <details class="disclosure compact"><summary>Observações da compra</summary><div class="field disclosure-content"><label>Observações (opcional)</label><textarea name="notes" maxlength="1000">{{ old('notes') }}</textarea></div></details>
        <p class="hint">O pagamento é registrado manualmente. Não há comunicação com bancos nem importação de nota fiscal.</p>
    </section>
    <div class="actions"><button class="primary">Confirmar recebimento</button><a href="{{ route('purchases',['company'=>$tenant->slug]) }}">Voltar</a></div>
</form>
<template id="purchase-row">
    <div class="sale-row purchase-row">
        <div class="selection"><label>Produto</label><select class="purchase-product" required><option value="">Selecione</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->sku }} · {{ $product->name }} ({{ $product->unit }})</option>@endforeach</select></div>
        <div><label>Quantidade recebida</label><input class="purchase-quantity" type="number" min="0.001" max="999999" step="0.001" value="1" required></div>
        <div><label>Custo por unidade (R$)</label><input class="purchase-cost" type="number" min="0" max="999999999.9999" step="0.0001" required></div>
        <button type="button" class="text-button remove">Remover</button>
    </div>
</template>
@endsection
@push('scripts')
<script>
(() => {
    let index=0;
    const previous=Object.values(@json(old('items',[])));
    const container=document.querySelector('#purchase-items');
    const addButton=document.querySelector('#add-purchase-item');
    function updateTotal(){let cents=0n;container.querySelectorAll('.purchase-row').forEach(row=>{const amount=Vendra.lineCents(row.querySelector('.purchase-quantity').value,row.querySelector('.purchase-cost').value,4);if(amount!==null)cents+=amount;});document.querySelector('#purchase-total').textContent=Vendra.centsText(cents);}
    function add(data){
        const row=document.querySelector('#purchase-row').content.firstElementChild.cloneNode(true);
        const current=index++;
        const fields=[['purchase-product','product_id'],['purchase-quantity','quantity'],['purchase-cost','unit_cost']];
        fields.forEach(([selector,name])=>{const input=row.querySelector('.'+selector);input.name=`items[${current}][${name}]`;if(data&&data[name]!==undefined)input.value=data[name];input.addEventListener('input',updateTotal);});
        row.querySelector('.remove').addEventListener('click',()=>{row.remove();updateTotal();addButton.focus();});
        container.append(row);Vendra.associateLabels(row);updateTotal();
    }
    addButton.addEventListener('click',()=>add());
    if(previous.length)previous.forEach(add);else add();
})();
</script>
@endpush
