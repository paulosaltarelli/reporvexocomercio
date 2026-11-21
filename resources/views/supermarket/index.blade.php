@extends('layout')
@section('title','Caixa do supermercado')
@section('navigation-section','Operação')
@section('subtitle','Leia o código do produto, confira os itens e finalize o recebimento.')
@push('styles')<link rel="stylesheet" href="/operational-fronts.css?v={{ filemtime(public_path('operational-fronts.css')) }}">
@endpush
@push('scripts')<script src="/operational-fronts.js?v={{ filemtime(public_path('operational-fronts.js')) }}" defer>
</script>
@endpush
@section('actions')<a class="button secondary" href="{{ route('cash',['company'=>$tenant->slug]) }}">Consultar caixa</a>
@endsection
@section('content')
<div class="operation-toolbar">
    <p class="hint">Código de barras ou SKU · até 200 produtos distintos por venda</p>
    <form method="post" action="{{ route('supermarket.start',['company'=>$tenant->slug]) }}">
@csrf
<input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
<button class="button secondary" @disabled($carts->contains('status','active'))>Nova venda</button>
</form>
</div>
@if($carts->where('status','suspended')->isNotEmpty())
<section class="operation-panel">
<h2>Vendas suspensas</h2>
<div class="operation-suspended">
@foreach($carts->where('status','suspended') as $saved)<a href="{{ route('supermarket.show',['company'=>$tenant->slug,'cart'=>$saved->id]) }}">Venda #{{ $saved->id }} · R$ {{ number_format($saved->total,2,',','.') }}</a>
@endforeach
</div>
</section>
@endif
@if($cart)
<div class="operation-grid">
<div>
<section class="operation-panel">
    <div class="operation-heading">
<h2>Venda #{{ $cart->id }}</h2>
<span class="operation-badge">{{ ['active'=>'Em atendimento','suspended'=>'Suspensa','completed'=>'Finalizada','cancelled'=>'Cancelada'][$cart->status] ?? $cart->status }}</span>
</div>
    @if($cart->status==='suspended')
        <form method="post" action="{{ route('supermarket.resume',['company'=>$tenant->slug,'cart'=>$cart->id]) }}">
@csrf
<input type="hidden" name="version" value="{{ $cart->version }}">
<button class="button">Retomar venda</button>
</form>
    @elseif($cart->status==='active')
    <form class="operation-scanner" method="post" action="{{ route('supermarket.items.add',['company'=>$tenant->slug,'cart'=>$cart->id]) }}">
@csrf
        <input type="hidden" name="version" value="{{ $cart->version }}">
        <div class="field">
<label for="supermarket-code">Código do produto <kbd>F2</kbd>
</label>
<input id="supermarket-code" name="code" required maxlength="100" value="{{ old('code') }}" data-operation-scanner autocomplete="off" autofocus>
</div>
        <div class="field">
<label for="supermarket-quantity">Quantidade</label>
<input id="supermarket-quantity" name="quantity" type="number" min="0.001" step="0.001" required value="{{ old('quantity','1') }}" inputmode="decimal">
</div>
        <button class="button">Adicionar item</button>
    </form>
    @endif
</section>
@if($cart->status==='active')
<details class="operation-panel" @if(request()->filled('q')) open @endif>
<summary>Buscar produto pelo nome</summary>
<form class="operation-catalog-search" method="get" action="{{ route('supermarket.show',['company'=>$tenant->slug,'cart'=>$cart->id]) }}">
<div class="field">
<label for="supermarket-search">Nome, código ou referência</label>
<input id="supermarket-search" name="q" value="{{ request('q') }}" maxlength="100">
</div>
<button class="button secondary">Buscar</button>
</form>
@if(request()->filled('q'))
    @if($products->isEmpty())<p class="hint">Nenhum produto encontrado.</p>
@endif
    @foreach($products as $product)
    <form class="operation-line operation-line-top" method="post" action="{{ route('supermarket.items.add',['company'=>$tenant->slug,'cart'=>$cart->id]) }}">
@csrf
<input type="hidden" name="version" value="{{ $cart->version }}">
<input type="hidden" name="quantity" value="1">
<input type="hidden" name="code" value="{{ $product->barcode ?: $product->sku }}">
<div>
<strong>{{ $product->name }}</strong>
<small>R$ {{ number_format($product->sale_price,2,',','.') }} · {{ $product->unit }}</small>
</div>
<button class="button secondary" @disabled(!filled($product->barcode ?: $product->sku))>Adicionar</button>
</form>
    @endforeach
@endif
</details>
@endif
<section class="operation-panel">
    <h2>Itens da venda</h2>
    @if(empty($cart->items))<div class="operation-empty">Leia um código ou informe o SKU para adicionar o primeiro item.</div>
    @else
    @foreach($cart->items as $line)
    <article class="operation-line">
        <div class="operation-line-top">
<strong>{{ $line['description'] }}</strong>
<strong class="amount">R$ {{ number_format($line['total'],2,',','.') }}</strong>
</div>
        <small>{{ $line['sku'] ?? '' }} · {{ number_format($line['quantity'],3,',','.') }} {{ $line['unit'] ?? '' }} × R$ {{ number_format($line['unit_price'],2,',','.') }}</small>
        @if($cart->status==='active')
        <details class="operation-line-actions">
<summary>Alterar quantidade ou retirar item</summary>
            <form class="operation-inline-quantity" method="post" action="{{ route('supermarket.items.update',['company'=>$tenant->slug,'cart'=>$cart->id,'line'=>$line['line_key']]) }}">
@csrf
<input type="hidden" name="version" value="{{ $cart->version }}">
<label for="quantity-{{ $line['line_key'] }}">Quantidade de {{ $line['description'] }}</label>
<input id="quantity-{{ $line['line_key'] }}" name="quantity" type="number" min="{{ $currentUser->allows('supermarket.cancel') ? '0.001' : $line['quantity'] }}" step="0.001" required value="{{ $line['quantity'] }}">
<input aria-label="Motivo da redução de quantidade" name="reason" minlength="3" maxlength="1000" placeholder="Motivo ao reduzir">
<button class="button secondary">Atualizar</button>
</form>
            @if($currentUser->allows('supermarket.cancel'))<form method="post" action="{{ route('supermarket.items.remove',['company'=>$tenant->slug,'cart'=>$cart->id,'line'=>$line['line_key']]) }}">
@csrf
<input type="hidden" name="version" value="{{ $cart->version }}">
<div class="field">
<label for="remove-{{ $line['line_key'] }}">Motivo da retirada</label>
<input id="remove-{{ $line['line_key'] }}" name="reason" required minlength="3" maxlength="500">
</div>
<button class="button secondary">Retirar item</button>
</form>
@endif
        </details>
        @endif
    </article>
    @endforeach
    @endif
</section>
</div>
<div class="operation-summary">
    <div class="operation-total">
<span>Total da venda</span>
<strong>R$ {{ number_format($cart->total,2,',','.') }}</strong>
<small>{{ count($cart->items ?? []) }} {{ count($cart->items ?? [])===1?'item':'itens' }}</small>
</div>
    @if($currentUser->allows('supermarket.checkout') && $cart->status==='active' && !empty($cart->items))
    <details class="operation-panel operation-checkout" data-operation-checkout @if($errors->hasAny(['payment_option','paid','due_date','salesperson_id','card_brand','payments'])) open @endif>
<summary>Receber e finalizar <kbd>F9</kbd>
</summary>
        <form method="post" action="{{ route('supermarket.checkout',['company'=>$tenant->slug,'cart'=>$cart->id]) }}" data-payment-total="{{ bcmul((string)$cart->total,'100',0) }}">
@csrf
<input type="hidden" name="version" value="{{ $cart->version }}">
            <div class="field">
<label for="supermarket-customer">Cliente</label>
<select id="supermarket-customer" name="customer_id">
<option value="">Consumidor final</option>
@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((string)old('customer_id',$cart->customer_id)===(string)$customer->id)>{{ $customer->name }}</option>
@endforeach
</select>
</div>
            @include('operations.payment-fields')
            <button class="button" type="submit">Confirmar venda e recebimento</button>
        </form>
    </details>
    @endif
    @if($cart->status==='completed' && $cart->sale_id)<section class="operation-panel">
<h2>Venda concluída</h2>
<a class="button" href="{{ route('sale.show',['company'=>$tenant->slug,'id'=>$cart->sale_id]) }}">Abrir venda #{{ $cart->sale_id }}</a>
</section>
@endif
    @if(in_array($cart->status,['active','suspended'],true))
    <section class="operation-panel">
<h2>Gerenciar atendimento</h2>
        @if($cart->status==='active')<form method="post" action="{{ route('supermarket.suspend',['company'=>$tenant->slug,'cart'=>$cart->id]) }}">
@csrf
<input type="hidden" name="version" value="{{ $cart->version }}">
<button class="button secondary">Suspender venda</button>
</form>
@endif
        @if($currentUser->allows('supermarket.cancel'))<details class="operation-line-actions">
<summary>Cancelar atendimento</summary>
<form method="post" action="{{ route('supermarket.cancel',['company'=>$tenant->slug,'cart'=>$cart->id]) }}">
@csrf
<input type="hidden" name="version" value="{{ $cart->version }}">
<div class="field">
<label for="cart-cancel-reason">Motivo do cancelamento</label>
<textarea id="cart-cancel-reason" name="reason" required minlength="3" maxlength="500">
</textarea>
</div>
<button class="button secondary">Confirmar cancelamento</button>
</form>
</details>
@endif
    </section>
    @endif
</div>
</div>
@else
<section class="operation-panel">
<div class="operation-empty">Abra uma nova venda para iniciar o atendimento.</div>
</section>
@endif
@endsection
