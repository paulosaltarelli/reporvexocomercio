@extends('operations.layout')
@section('title','Comanda #'.$order->id)
@section('navigation-section','Restaurante')
@section('subtitle',$order->table?->name.' · '.($order->customer?->name ?? 'Cliente não identificado'))
@section('actions')
<div class="actions">
    @if($currentUser->allows('restaurant.use') && $order->status!=='cancelled')
        <a class="button secondary" target="_blank" rel="noopener" href="{{ route('restaurant.orders.prebill',['company'=>$tenant->slug,'order'=>$order->id]) }}">Imprimir pré-conta</a>
    @endif
    @if($currentUser->allows('restaurant.print') && $order->items->contains(fn($item)=>!in_array($item->status,['draft','cancelled'],true)))
        <a class="button secondary" target="_blank" rel="noopener" href="{{ route('restaurant.orders.ticket',['company'=>$tenant->slug,'order'=>$order->id]) }}">Imprimir último envio</a>
    @endif
</div>
@endsection
@section('content')
@php($productImages=app(\App\Services\ProductImages::class))
<div class="operation-compact-summary"><span>Comanda #{{ $order->id }} <strong>R$ {{ number_format($total,2,',','.') }}</strong></span><a class="button secondary" href="#order-summary">Ver comanda</a><a class="button secondary" href="#restaurant-catalog">Cardápio</a></div>
<div class="operation-grid restaurant-order-grid">
<div>
@if($order->status==='open')
<section class="operation-panel">
    <h2>Adicionar ao pedido</h2>
    <div class="field">
<label for="restaurant-product-search">Buscar no cardápio</label>
<input id="restaurant-product-search" type="search" data-product-search="restaurant-catalog" aria-describedby="restaurant-search-result" placeholder="Nome, categoria ou código">
<small id="restaurant-search-result" class="hint" role="status">{{ $products->count() }} itens disponíveis</small>
</div>
    @if($products->pluck('group.name')->filter()->unique()->count() > 1)
    <div class="field">
<label for="restaurant-category">Categoria</label>
<select id="restaurant-category" data-product-category="restaurant-catalog">
<option value="">Todas as categorias</option>
@foreach($products->pluck('group.name')->filter()->unique()->sort() as $category)<option value="{{ $category }}">{{ $category }}</option>
@endforeach
</select>
<div class="operation-category-chips" data-category-chips="restaurant-category" aria-label="Filtrar por categoria" hidden>
<button type="button" class="operation-category-chip" data-category-value="" aria-pressed="true">Todas</button>
@foreach($products->pluck('group.name')->filter()->unique()->sort() as $category)<button type="button" class="operation-category-chip" data-category-value="{{ $category }}" aria-pressed="false">{{ $category }}</button>@endforeach
</div>
</div>
    @endif
    @if($products->isEmpty())<div class="operation-empty">Nenhum produto ativo no cardápio. Cadastre os produtos para iniciar os pedidos.</div>
@endif
    <div class="operation-catalog" id="restaurant-catalog">
    @foreach($products as $product)
    <form class="operation-product" data-category="{{ $product->group?->name }}" data-product-name="{{ mb_strtolower($product->name.' '.$product->sku.' '.$product->group?->name) }}" method="post" action="{{ route('restaurant.orders.items.add',['company'=>$tenant->slug,'order'=>$order->id]) }}">
@csrf
        <input type="hidden" name="version" value="{{ $order->version }}">
<input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
<input type="hidden" name="product_id" value="{{ $product->id }}">
        @include('components.product-image')
        @if($product->group?->name)<small class="hint">{{ $product->group->name }}</small>
@endif
        <h3>{{ $product->name }}</h3>
<span class="product-price">R$ {{ number_format($product->sale_price,2,',','.') }}</span>
        <div class="field">
<label for="product-quantity-{{ $product->id }}">Quantidade</label>
<input id="product-quantity-{{ $product->id }}" type="number" name="quantity" step="0.001" min="0.001" value="1" required inputmode="decimal">
</div>
        <div class="field">
<label for="product-station-{{ $product->id }}">Destino</label>
<select id="product-station-{{ $product->id }}" name="station">
<option value="kitchen">Cozinha</option>
<option value="bar">Bar</option>
</select>
</div>
        <details>
<summary>Observação de preparo</summary>
<label class="sr-only" for="product-notes-{{ $product->id }}">Observação de {{ $product->name }}</label>
<textarea id="product-notes-{{ $product->id }}" name="notes" maxlength="1000" rows="2" placeholder="Ex.: sem cebola">
</textarea>
</details>
        <button class="button secondary">Adicionar</button>
    </form>
    @endforeach
    </div>
</section>
@endif
</div>
<div class="operation-summary" id="order-summary">
<div class="operation-total">
<span>Total da comanda</span>
<strong>R$ {{ number_format($total,2,',','.') }}</strong>
<small>{{ $order->table?->name }} · {{ ['open'=>'Em atendimento','closed'=>'Encerrada','cancelled'=>'Cancelada'][$order->status] ?? $order->status }}</small>
</div>
<section class="operation-panel">
<h2>Pedidos da comanda</h2>
@if($order->items->isEmpty())<div class="operation-empty">Escolha os itens no cardápio para montar o pedido.</div>
@endif
@foreach($order->items as $item)
<article class="operation-line">
<div class="operation-line-top">
<strong>{{ $item->description }}</strong>
<strong class="amount">R$ {{ number_format(\App\Services\Decimal::round(bcmul($item->quantity,$item->unit_price,6),2),2,',','.') }}</strong>
</div>
<small>{{ number_format($item->quantity,3,',','.') }} × R$ {{ number_format($item->unit_price,2,',','.') }} · {{ $item->station==='bar'?'Bar':'Cozinha' }}</small>
<p>
<span class="operation-badge {{ $item->status }}">{{ $statuses[$item->status] ?? $item->status }}</span>
</p>
@if(filled($item->notes))<p class="operation-notes">{{ $item->notes }}</p>
@endif
@if($item->status==='cancelled')<small>Motivo: {{ $item->cancellation_reason }}</small>
@endif
@if($currentUser->allows('restaurant.kitchen') && $order->status==='open' && in_array($item->status,['queued','preparing'],true))
@php($nextStatus=['queued'=>'preparing','preparing'=>'ready','ready'=>'delivered'][$item->status])
<form method="post" action="{{ route('restaurant.items.status',['company'=>$tenant->slug,'order'=>$order->id,'item'=>$item->id]) }}">
@csrf
<input type="hidden" name="version" value="{{ $order->version }}">
<input type="hidden" name="status" value="{{ $nextStatus }}">
<button class="button secondary">{{ ['preparing'=>'Iniciar preparo','ready'=>'Marcar pronto','delivered'=>'Registrar entrega'][$nextStatus] }}</button>
</form>
@endif
@if($currentUser->allows('restaurant.deliver') && $order->status==='open' && $item->status==='ready')
<form method="post" action="{{ route('restaurant.items.deliver',['company'=>$tenant->slug,'order'=>$order->id,'item'=>$item->id]) }}">
@csrf
<input type="hidden" name="version" value="{{ $order->version }}">
<button class="button">Registrar entrega</button>
</form>
@endif
@if($currentUser->allows('restaurant.cancel') && $order->status==='open' && $item->status!=='cancelled')
<details class="operation-line-actions">
<summary>Cancelar item</summary>
<form method="post" action="{{ route('restaurant.items.cancel',['company'=>$tenant->slug,'order'=>$order->id,'item'=>$item->id]) }}">
@csrf
<input type="hidden" name="version" value="{{ $order->version }}">
<div class="field">
<label for="cancel-item-{{ $item->id }}">Motivo</label>
<input id="cancel-item-{{ $item->id }}" name="reason" minlength="3" maxlength="1000" required>
</div>
<button class="button secondary">Confirmar cancelamento</button>
</form>
</details>
@endif
</article>
@endforeach
@if($order->status==='open' && $order->items->contains('status','draft'))<form method="post" action="{{ route('restaurant.orders.send',['company'=>$tenant->slug,'order'=>$order->id]) }}">
@csrf
<input type="hidden" name="version" value="{{ $order->version }}">
<div class="actions">
<button class="button">Enviar pedido à produção</button>
</div>
</form>
@endif
</section>
@if($currentUser->allows('restaurant.print') && $productionBatches->count()>1)
<section class="operation-panel">
    <details>
        <summary>Reimprimir um envio anterior</summary>
        <form method="get" target="_blank" action="{{ route('restaurant.orders.ticket',['company'=>$tenant->slug,'order'=>$order->id]) }}">
            <div class="field"><label for="production-batch">Envio à produção</label><select id="production-batch" name="batch" required>@foreach($productionBatches as $batch)<option value="{{ $batch->id }}">#{{ $batch->id }} · {{ $batch->created_at->format('d/m/Y H:i') }}</option>@endforeach</select></div>
            <div class="field"><label for="production-reprint-station">Destino</label><select id="production-reprint-station" name="station"><option value="">Cozinha e bar</option><option value="kitchen">Cozinha</option><option value="bar">Bar</option></select></div>
            <div class="actions"><button class="button secondary">Abrir impressão</button></div>
        </form>
    </details>
</section>
@endif
@if($order->status==='open')
@php($billableItems=$order->items->where('status','<>','cancelled'))
@if($currentUser->allows('restaurant.checkout') && $billableItems->isNotEmpty() && $billableItems->every(fn($item)=>$item->status==='delivered'))
<details class="operation-panel operation-checkout" data-operation-checkout @if(old('payments') || $errors->has('payments.*') || $errors->hasAny(['payments','payment_option','paid','due_date','salesperson_id','card_brand'])) open @endif>
<summary>Fechar conta <kbd>F9</kbd>
</summary>
<form method="post" action="{{ route('restaurant.orders.close',['company'=>$tenant->slug,'order'=>$order->id]) }}" data-payment-total="{{ bcmul((string)$total,'100',0) }}">
@csrf
<input type="hidden" name="version" value="{{ $order->version }}">
@include('operations.payment-fields')<button class="button" type="submit">Confirmar recebimento e encerrar</button>
</form>
</details>
@elseif($billableItems->isNotEmpty())<p class="hint operation-status-message">Registre a entrega de todos os itens para fechar a conta.</p>
@endif
@if($currentUser->allows('restaurant.transfer'))
<section class="operation-panel operation-transfer">
    <details @if($errors->hasAny(['table_id','reason'])) open @endif>
        <summary>Transferir para outra mesa</summary>
        <form method="post" action="{{ route('restaurant.orders.transfer',['company'=>$tenant->slug,'order'=>$order->id]) }}">
            @csrf
            <input type="hidden" name="version" value="{{ $order->version }}">
            <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
            <div class="field"><label for="transfer-table">Mesa de destino</label><select id="transfer-table" name="table_id" required><option value="">Selecione uma mesa livre</option>@foreach($availableTables as $table)<option value="{{ $table->id }}" @selected((string)old('table_id')===(string)$table->id)>{{ $table->name }}</option>@endforeach</select></div>
            <div class="field"><label for="transfer-reason">Motivo da transferência</label><input id="transfer-reason" name="reason" minlength="3" maxlength="1000" value="{{ old('reason') }}" required></div>
            <button class="button secondary" @disabled($availableTables->isEmpty())>Confirmar transferência</button>
            @if($availableTables->isEmpty())<p class="hint">Nenhuma outra mesa livre neste momento.</p>@endif
        </form>
    </details>
</section>
@endif
@if($currentUser->allows('restaurant.cancel'))<section class="operation-panel">
<details>
<summary>Cancelar comanda</summary>
<form method="post" action="{{ route('restaurant.orders.cancel',['company'=>$tenant->slug,'order'=>$order->id]) }}">
@csrf
<input type="hidden" name="version" value="{{ $order->version }}">
<div class="field">
<label for="cancel-order">Motivo do cancelamento</label>
<textarea id="cancel-order" name="reason" required minlength="3" maxlength="1000">
</textarea>
</div>
<div class="actions">
<button class="button secondary">Confirmar cancelamento</button>
</div>
</form>
</details>
</section>
@endif
@elseif($order->sale_id)<section class="operation-panel">
<h2>Comanda encerrada</h2>
<p>Venda registrada #{{ $order->sale_id }}</p>
@if($currentUser->allows('restaurant.checkout') && (int)$order->sale?->user_id === (int)$currentUser->id)
<a class="button" href="{{ route('restaurant.orders.receipt',['company'=>$tenant->slug,'order'=>$order->id]) }}">Imprimir comprovante</a>
@endif
@if($currentUser->role==='admin' && $currentUser->allows('sales.view'))
<p><a href="{{ route('sale.show',['company'=>$tenant->slug,'id'=>$order->sale_id]) }}">Consultar venda na gestão</a></p>
@endif
</section>
@endif
</div>
</div>
@endsection
