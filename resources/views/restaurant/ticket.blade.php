@extends('printing.thermal')
@section('title','Pedido de produção da comanda #'.$order->id)
@section('content')
@include('restaurant.print-header')
<section class="block receipt-id">
    <p class="receipt-nonfiscal">Documento não fiscal · Produção</p>
    <h2>{{ ['kitchen'=>'Cozinha','bar'=>'Bar'][$station] ?? 'Cozinha e bar' }}</h2>
    <p><strong>{{ $order->table?->name }} · Comanda #{{ $order->id }}</strong></p>
    @if($order->customer)<p>Cliente {{ $order->customer->name }}</p>@endif
    @if(filled($order->customer?->phone))<p>Telefone do cliente {{ $order->customer->phone }}</p>@endif
    @if($batch)<p>Lote #{{ $batch->id }} · {{ $batch->created_at->format('d/m/Y H:i') }}</p>@endif
    @if($order->status === 'cancelled')<p><strong>Comanda cancelada</strong></p>@endif
</section>
<section class="block receipt-items">
    @forelse($items as $item)
        <div class="entry">
            <strong class="item-title">{{ number_format($item->quantity,3,',','.') }} · {{ $item->description }}</strong>
            @foreach($item->commercial_terms['_restaurant_catalog']['addons'] ?? [] as $addon)<p><strong>{{ $addon['group_name'] }}</strong> · {{ $addon['name'] }}</p>@endforeach
            @unless($station)<p>{{ ['kitchen'=>'Cozinha','bar'=>'Bar'][$item->station] }}</p>@endunless
            @if(filled($item->notes))<p><strong>Observação</strong> {{ $item->notes }}</p>@endif
            @if($item->status === 'cancelled')<p><strong>ITEM CANCELADO</strong></p><p>{{ $item->cancellation_reason }}</p>@endif
        </div>
    @empty<p>Nenhum item enviado à produção neste lote e destino.</p>@endforelse
</section>
<footer class="block receipt-footer"><p>Reimpressão conserva o lote original.</p><p>Vendra por Vexo</p></footer>
@endsection
