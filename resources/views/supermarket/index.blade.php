@extends('operations.layout')
@section('title','Caixa do supermercado')
@section('navigation-section','Operação')
@section('subtitle','Leia um código ou busque pelo nome do produto para montar a venda.')
@section('content')
@php
    $productImages=app(\App\Services\ProductImages::class);
@endphp
@if($cart && $cart->status==='active')<div class="operation-compact-summary"><span>Venda #{{ $cart->id }} <strong>R$ {{ number_format($cart->total,2,',','.') }}</strong></span><a class="button secondary" href="#supermarket-code">Buscar produto</a><a class="button secondary" href="#market-payment">Receber</a></div>@endif
<div class="operation-toolbar">
    <p class="hint operation-shortcuts">Código, SKU ou nome · até 200 lançamentos <span><kbd>F2</kbd> Buscar produto <kbd>F9</kbd> Receber</span></p>
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
<label for="supermarket-code">Código, SKU ou nome do produto <kbd>F2</kbd>
</label>
<input id="supermarket-code" name="code" required maxlength="80" value="{{ old('code',request('q')) }}" data-operation-scanner autocomplete="off" aria-describedby="supermarket-search-help" placeholder="Leia o código ou digite, por exemplo, Cerveja" @if(!request()->filled('q')) autofocus @endif>
</div>
        <div class="field">
<label for="supermarket-quantity">Quantidade</label>
<input id="supermarket-quantity" name="quantity" type="number" min="0.001" max="999999" step="0.001" required value="{{ old('quantity',request('search_quantity','1')) }}" inputmode="decimal">
</div>
        <button class="button">Buscar ou adicionar</button>
    </form>
    <p class="hint" id="supermarket-search-help">Código exato adiciona direto. Pelo nome, escolha o produto nos resultados abaixo.</p>
    @php
        $lastScan=session('last_supermarket_scan');
        $scannedLine=is_array($lastScan) && (int)($lastScan['cart_id']??0)===(int)$cart->id
            ? collect($cart->items??[])->firstWhere('line_key',$lastScan['line_key']??null) : null;
    @endphp
    @if($scannedLine)
    <div class="operation-last-scan" role="status">
        <small>Último item confirmado · quantidade acumulada na venda</small>
        <div class="operation-line-top"><strong>{{ $scannedLine['description'] }}</strong><strong>R$ {{ number_format($scannedLine['total'],2,',','.') }}</strong></div>
        <p>{{ number_format($scannedLine['quantity'],3,',','.') }} {{ $scannedLine['unit']??'' }} × R$ {{ number_format($scannedLine['unit_price'],2,',','.') }}</p>
    </div>
    @endif
    @if($operationConfig['weighed_labels']['enabled'] ?? false)<p class="hint">Etiquetas pesáveis habilitadas. Use quantidade 1 para ler o peso ou o valor da etiqueta.</p>@endif
    @endif
</section>
@if($cart->status==='active' && request()->filled('q'))
<section class="operation-panel operation-search-results" id="supermarket-search-results" aria-labelledby="supermarket-results-heading" data-operation-search-results>
<div class="operation-heading">
<h2 id="supermarket-results-heading" tabindex="-1">Resultados para “{{ request('q') }}”</h2>
<a class="button secondary" href="{{ route('supermarket.show',['company'=>$tenant->slug,'cart'=>$cart->id]) }}">Limpar busca</a>
</div>
@if($products->isEmpty())
<div class="operation-empty" role="status">Nenhum produto encontrado. Confira o nome ou leia o código de barras.</div>
@else
<p class="hint" role="status">{{ $products->count() }} {{ $products->count()===1?'produto encontrado':'produtos encontrados' }}. Selecione o produto. A quantidade informada acima será mantida.</p>
@foreach($products as $product)
<form class="operation-line operation-line-top operation-search-result" method="post" action="{{ route('supermarket.items.add',['company'=>$tenant->slug,'cart'=>$cart->id]) }}">
@csrf
<input type="hidden" name="version" value="{{ $cart->version }}">
<input type="hidden" name="quantity" value="{{ old('quantity',request('search_quantity','1')) }}" data-result-quantity>
<input type="hidden" name="code" value="{{ $product->barcode ?: $product->sku }}">
@php($searchArtwork=$productImages->presentation($tenant->slug,$tenant->id,$product->id))
@if($searchArtwork)
<div class="operation-product-image">@include('components.product-image',['imageArtwork'=>$searchArtwork])</div>
@endif
<div class="operation-search-description">
<strong>{{ $product->name }}</strong>
<small>R$ {{ number_format($product->sale_price,2,',','.') }} / {{ $product->unit }}</small>
<small>SKU {{ $product->sku }}@if($product->barcode) · {{ $product->barcode }}@endif</small>
</div>
<button class="button secondary" aria-label="Adicionar {{ $product->name }} à venda" @disabled(!filled($product->barcode ?: $product->sku))>Adicionar</button>
</form>
@endforeach
@endif
</section>
@endif
<section class="operation-panel">
    <h2>Itens da venda</h2>
    @if(empty($cart->items))<div class="operation-empty">Leia um código ou busque pelo nome para adicionar o primeiro item.</div>
    @else
    @foreach($cart->items as $line)
    <article class="operation-line">
        <div class="operation-line-top">
<strong>{{ $line['description'] }}</strong>
<strong class="amount">R$ {{ number_format($line['total'],2,',','.') }}</strong>
</div>
        <small>{{ $line['sku'] ?? '' }} · {{ number_format($line['quantity'],3,',','.') }} {{ $line['unit'] ?? '' }} × R$ {{ number_format($line['unit_price'],2,',','.') }}</small>
@if(!empty($line['weighted_label']))<p><span class="operation-badge">Etiqueta pesável</span></p><small>{{ $line['weighted_label']['content']==='weight'?'Peso lido na etiqueta':'Peso calculado a partir do preço da etiqueta' }} · {{ number_format($line['quantity'],3,',','.') }} {{ $line['unit'] ?? '' }}</small>@endif
        @if($cart->status==='active')
        <details class="operation-line-actions">
<summary>{{ !empty($line['weighted_label']) ? ($currentUser->allows('supermarket.cancel') ? 'Conferir ou retirar item' : 'Conferir etiqueta') : ($currentUser->allows('supermarket.cancel') ? 'Alterar quantidade ou retirar item' : 'Alterar quantidade') }}</summary>
            @if(empty($line['weighted_label']))
<form class="operation-inline-quantity" method="post" action="{{ route('supermarket.items.update',['company'=>$tenant->slug,'cart'=>$cart->id,'line'=>$line['line_key']]) }}">
@csrf
<input type="hidden" name="version" value="{{ $cart->version }}">
<label for="quantity-{{ $line['line_key'] }}">Quantidade de {{ $line['description'] }}</label>
<input id="quantity-{{ $line['line_key'] }}" name="quantity" type="number" min="{{ $currentUser->allows('supermarket.cancel') ? '0.001' : $line['quantity'] }}" step="0.001" required value="{{ $line['quantity'] }}">
<input aria-label="Motivo da redução de quantidade" name="reason" minlength="3" maxlength="1000" placeholder="Motivo ao reduzir">
<button class="button secondary">Atualizar</button>
</form>
@else<p class="hint">{{ $currentUser->allows('supermarket.cancel') ? 'Para corrigir uma etiqueta pesável, retire o item e leia a etiqueta novamente.' : 'Para corrigir uma etiqueta pesável, peça a um responsável com permissão de cancelamento para retirar o item.' }}</p>
@endif
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
<div class="operation-summary" id="market-payment">
    <div class="operation-total">
<span>Total da venda</span>
<strong>R$ {{ number_format($cart->total,2,',','.') }}</strong>
<small>{{ count($cart->items ?? []) }} {{ count($cart->items ?? [])===1?'item':'itens' }}</small>
</div>
    @if($currentUser->allows('supermarket.checkout') && $cart->status==='active' && !empty($cart->items))
    <details class="operation-panel operation-checkout" data-operation-checkout @if(old('payments') || $errors->has('payments.*') || $errors->hasAny(['payments','payment_option','paid','due_date','salesperson_id','card_brand'])) open @endif>
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
<a class="button" href="{{ route('supermarket.receipt',['company'=>$tenant->slug,'cart'=>$cart->id]) }}">Imprimir comprovante</a>
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
