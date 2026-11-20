@extends('layout')
@section('title','Receber compra')
@section('subtitle','Confira os produtos recebidos. A confirmação registra a entrada e recalcula o custo médio.')
@section('content')
@php
    $productInvoices = config('fiscal.product_invoices_enabled', false);
    $previousItems = collect(old('items', []))
        ->filter(fn ($item) => is_array($item))
        ->map(fn ($item) => $productInvoices ? $item : \Illuminate\Support\Arr::only(
            $item, ['product_id', 'quantity', 'unit_cost', 'measure_unit', 'factor_seen']
        ))->values()->all();
@endphp
@if($productInvoices)
<details class="panel disclosure">
    <summary>Preencher a partir de um XML de NF-e</summary>
    <form method="post" enctype="multipart/form-data" action="{{ route('purchase.preview-xml',['company'=>$tenant->slug]) }}">
        @csrf
        <div class="field"><label>Arquivo XML da NF-e modelo 55 (até 2 MB)</label><input type="file" name="invoice_xml" accept=".xml,application/xml,text/xml" required></div>
        <button type="submit" class="button secondary">Conferir prévia</button>
    </form>
</details>
@if(old('source_issuer'))
    <section class="panel"><h2>Prévia do XML</h2><p>Emitente {{ old('source_issuer') }}. Vincule cada item ao seu cadastro, confira a unidade de compra e indique o tratamento dos impostos. A entrada será registrada ao confirmar o recebimento.</p></section>
@endif
@endif
<form method="post" action="{{ route('purchases',['company'=>$tenant->slug]) }}">
    @csrf
    @if($productInvoices && old('source_issuer'))<input type="hidden" name="source_issuer" value="{{ old('source_issuer') }}">@endif
    <input type="hidden" name="request_key" value="{{ old('request_key',\Illuminate\Support\Str::uuid()) }}">
    <section class="panel">
        <h2>Fornecedor e recebimento</h2>
        <div class="form-grid">
            <div class="field"><label>Fornecedor</label><select name="supplier_id" required><option value="">Selecione</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected(old('supplier_id')==$supplier->id)>{{ $supplier->name }}</option>@endforeach</select></div>
            <div class="field"><label>Data do recebimento</label><input type="date" name="purchased_on" value="{{ old('purchased_on',now()->toDateString()) }}" max="{{ now()->toDateString() }}" required></div>
            <div class="field"><label>Referência interna (opcional)</label><input name="reference" value="{{ old('reference') }}" maxlength="100" placeholder="Número do pedido"></div>
        </div>
        @if($productInvoices)
        <details class="disclosure" @if(array_filter((array) old('invoice',[]))) open @endif>
            <summary>Nota fiscal de entrada</summary>
            <p class="hint">Para registrar a nota, informe número, série, emissão e total a pagar. Os valores gerais abaixo são distribuídos pelos itens conforme o valor bruto.</p>
            <div class="form-grid">
                <div class="field"><label>Número da nota</label><input name="invoice[number]" inputmode="numeric" maxlength="9" value="{{ old('invoice.number') }}"></div>
                <div class="field"><label>Série</label><input name="invoice[series]" inputmode="numeric" maxlength="3" value="{{ old('invoice.series') }}"></div>
                <div class="field"><label>Data de emissão</label><input type="date" name="invoice[issued_on]" value="{{ old('invoice.issued_on') }}"></div>
                <div class="field"><label>Chave NF-e (opcional)</label><input name="invoice[access_key]" maxlength="44" minlength="44" value="{{ old('invoice.access_key') }}"></div>
                <div class="field"><label>Total da nota (R$)</label><input type="number" min="0" step=".01" name="invoice[declared_total]" value="{{ old('invoice.declared_total') }}"></div>
                @foreach(\App\Services\PurchaseInvoice::CHARGES as $key => $label)
                    <div class="field"><label>{{ $label }} a ratear (R$)</label><input data-general-charge="{{ $key }}" type="number" min="0" step=".01" name="invoice[{{ $key }}]" value="{{ old('invoice.'.$key) }}"></div>
                @endforeach
            </div>
        </details>
        @endif
        @if($suppliers->isEmpty())
            <p class="hint"><a href="{{ route('catalog',['company'=>$tenant->slug,'kind'=>'suppliers']) }}">Cadastre o fornecedor</a> antes de receber a compra.</p>
        @endif
    </section>
    <section class="panel">
        <h2>Produtos recebidos</h2>
        @if($productInvoices)
        <p class="hint">Na entrada de nota fiscal, informe o preço unitário da mercadoria. Lance frete, descontos e tributos uma única vez, conforme aparecem na nota. Em uma compra simples, informe o custo efetivo.</p>
        @else
        <p class="hint">Informe a quantidade recebida e o custo efetivo por unidade de compra.</p>
        @endif
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
            <div class="field"><label>Forma de pagamento</label>@include('components.payment-method-select',['paymentSelectId'=>'payment-option','paymentDefault'=>'pix'])</div>
            <div class="field"><label>Número de parcelas</label><input name="installments" type="number" min="1" max="24" step="1" value="{{ old('installments',1) }}" required><small class="hint">Até 24 parcelas mensais para compras a pagar.</small></div>
            <div class="field"><label>Primeiro vencimento</label><input type="date" name="due_date" value="{{ old('due_date') }}"><small class="hint">Obrigatório se a compra estiver a pagar.</small></div>
        </div>
        <details class="disclosure compact"><summary>Observações da compra</summary><div class="field disclosure-content"><label>Observações (opcional)</label><textarea name="notes" maxlength="1000">{{ old('notes') }}</textarea></div></details>
        <p class="hint">Registre a baixa depois de efetuar o pagamento ao fornecedor.</p>
    </section>
    <div class="actions"><button class="primary">Confirmar recebimento</button><a href="{{ route('purchases',['company'=>$tenant->slug]) }}">Voltar</a></div>
</form>
<template id="purchase-row">
    <div class="sale-row purchase-row">
        @if($productInvoices)<p class="hint purchase-source" hidden style="grid-column:1/-1"></p>@endif
        <div class="selection"><label>Produto</label><select class="purchase-product" required><option value="">Selecione</option>@foreach($products as $product)<option value="{{ $product->id }}" data-units="{{ json_encode(\App\Services\MeasureUnits::options($product,$operationConfig,'purchase')) }}">{{ $product->sku }} · {{ $product->name }} ({{ $product->unit }})</option>@endforeach</select></div>
        <div><label>Unidade de compra</label><select class="purchase-unit" required></select><input type="hidden" class="purchase-factor"></div>
        <div><label>Quantidade recebida</label><input class="purchase-quantity" type="number" min="0.001" max="999999" step="0.001" value="1" required></div>
        <div><label>Valor por unidade (R$)</label><input class="purchase-cost" type="number" min="0" max="999999999.9999" step="0.0001" required></div>
        @if($productInvoices)
        <details class="disclosure" style="grid-column:1/-1">
            <summary>Valores e tributos deste item</summary>
            <div class="form-grid">
                @foreach(\App\Services\PurchaseInvoice::CHARGES as $key => $label)
                    <div class="field"><label>{{ $label }} do item (R$)</label><input type="number" min="0" step=".01" data-adjustment="{{ $key }}"></div>
                @endforeach
            </div>
            <p class="hint">Para cada tributo informado, indique se o preço já o inclui e escolha o tratamento definido para esta compra.</p>
            @foreach(\App\Services\PurchaseInvoice::TAXES as $key => $label)
                <fieldset data-tax="{{ $key }}"><legend>{{ $label }}</legend><div class="form-grid">
                    <div class="field"><label>Valor (R$)</label><input type="number" min="0" step=".01" data-part="amount"></div>
                    <div class="field"><label>Composição da nota</label><select data-part="composition"><option value="">Selecione</option><option value="included">Já incluído no preço</option><option value="added">Somar ao total</option></select></div>
                    <div class="field"><label>Tratamento</label><select data-part="treatment"><option value="">Selecione</option>@foreach(\App\Services\PurchaseInvoice::TREATMENTS as $value => $name)<option value="{{ $value }}">{{ $name }}</option>@endforeach</select></div>
                </div></fieldset>
            @endforeach
        </details>
        @endif
        <button type="button" class="text-button remove">Remover</button>
    </div>
</template>
@endsection
@push('scripts')
<script>
(() => {
    let index=0;
    const previous=@json($previousItems);
    const container=document.querySelector('#purchase-items');
    const addButton=document.querySelector('#add-purchase-item');
    function updateTotal(){
        let cents=0n;
        @if($productInvoices)
        const money=input=>Vendra.decimalUnits(input.value,2) || 0n;
        document.querySelectorAll('[data-general-charge]').forEach(input=>{cents+=(input.dataset.generalCharge==='discount'?-1n:1n)*money(input);});
        @endif
        container.querySelectorAll('.purchase-row').forEach(row=>{
            cents+=Vendra.lineCents(row.querySelector('.purchase-quantity').value,row.querySelector('.purchase-cost').value,4) || 0n;
            @if($productInvoices)
            row.querySelectorAll('[data-adjustment]').forEach(input=>{cents+=(input.dataset.adjustment==='discount'?-1n:1n)*money(input);});
            row.querySelectorAll('[data-tax]').forEach(tax=>{
                if(tax.querySelector('[data-part="composition"]').value==='added') cents+=money(tax.querySelector('[data-part="amount"]'));
            });
            @endif
        });
        document.querySelector('#purchase-total').textContent=Vendra.centsText(cents);
    }
    @if($productInvoices)
    document.querySelectorAll('[data-general-charge]').forEach(input=>input.addEventListener('input',updateTotal));
    @endif
    function add(data){
        const row=document.querySelector('#purchase-row').content.firstElementChild.cloneNode(true);
        const current=index++;
        @if($productInvoices)
        if(data?.source_name){
            const source=row.querySelector('.purchase-source');
            source.hidden=false;
            source.textContent='Item do XML: '+[data.source_code,data.source_name,data.source_unit].filter(Boolean).join(' · ');
            ['source_code','source_name','source_unit'].forEach(key=>{
                const hidden=document.createElement('input');
                hidden.type='hidden';hidden.name=`items[${current}][${key}]`;hidden.value=data[key] ?? '';row.append(hidden);
            });
        }
        row.addEventListener('input',updateTotal);
        row.querySelectorAll('[data-adjustment]').forEach(input=>{
            const key=input.dataset.adjustment;
            input.name=`items[${current}][adjustments][${key}]`;
            input.value=data?.adjustments?.[key] ?? '';
        });
        row.querySelectorAll('[data-tax]').forEach(tax=>{
            const code=tax.dataset.tax;
            tax.querySelectorAll('[data-part]').forEach(input=>{
                const part=input.dataset.part;
                input.name=`items[${current}][taxes][${code}][${part}]`;
                input.value=data?.taxes?.[code]?.[part] ?? '';
            });
        });
        if (data && (Object.values(data.adjustments || {}).some(Number) || Object.values(data.taxes || {}).some(t=>Number(t.amount)))) row.querySelector('details').open=true;
        @endif
        const fields=[['purchase-product','product_id'],['purchase-quantity','quantity'],['purchase-cost','unit_cost']];
        fields.forEach(([selector,name])=>{const input=row.querySelector('.'+selector);input.name=`items[${current}][${name}]`;if(data&&data[name]!==undefined)input.value=data[name];input.addEventListener('input',updateTotal);});
        const product=row.querySelector('.purchase-product'),unit=row.querySelector('.purchase-unit'),factor=row.querySelector('.purchase-factor');
        unit.name=`items[${current}][measure_unit]`;
        factor.name=`items[${current}][factor_seen]`;
        const units=()=>JSON.parse(product.selectedOptions[0]?.dataset.units || '{}');
        function refresh(preferred){
            const options=units();
            unit.replaceChildren(...Object.keys(options).map(code=>new Option(code,code)));
            if(preferred && options[preferred]) unit.value=preferred;
            @if($productInvoices)
            else if(preferred && data?.source_unit) {
                unit.prepend(new Option('Vincule a unidade do XML',''));
                unit.value='';
            }
            @endif
            factor.value=options[unit.value] || '';
        }
        product.addEventListener('change',()=>refresh(data?.source_unit));
        unit.addEventListener('change',()=>factor.value=units()[unit.value]);
        refresh(data?.measure_unit);
        row.querySelector('.remove').addEventListener('click',()=>{row.remove();updateTotal();addButton.focus();});
        container.append(row);Vendra.associateLabels(row);updateTotal();
    }
    @if($productInvoices)
    // One bounded JSON field per row keeps long invoices below PHP's max_input_vars.
    container.closest('form').addEventListener('formdata',event=>{
        container.querySelectorAll('.purchase-row').forEach(row=>{
            const prefix=row.querySelector('.purchase-product').name.replace(/\[product_id\]$/,'');
            const adjustments={},taxes={};
            row.querySelectorAll('[data-adjustment]').forEach(input=>{
                event.formData.delete(input.name);
                if(input.value) adjustments[input.dataset.adjustment]=input.value;
            });
            row.querySelectorAll('[data-tax]').forEach(tax=>{
                const fields={};
                tax.querySelectorAll('[data-part]').forEach(input=>{
                    event.formData.delete(input.name);
                    if(input.value) fields[input.dataset.part]=input.value;
                });
                if(Object.keys(fields).length) taxes[tax.dataset.tax]=fields;
            });
            event.formData.set(prefix+'[fiscal_detail]',JSON.stringify({adjustments,taxes}));
        });
    });
    @endif
    addButton.addEventListener('click',()=>add());
    if(previous.length)previous.forEach(add);else add();
})();
</script>
@endpush
