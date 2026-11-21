@extends('operations.layout')
@section('title','Cozinha e bar')
@section('navigation-section','Restaurante')
@section('subtitle','Acompanhe os itens enviados e registre cada etapa do preparo.')
@section('actions')<a class="button" href="{{ request()->fullUrl() }}">Atualizar pedidos</a>@endsection
@section('content')
<form class="operation-toolbar" method="get" action="{{ route('restaurant.production',['company'=>$tenant->slug]) }}">
<div class="field">
<label for="production-station">Destino do pedido</label>
<select id="production-station" name="station">
<option value="">Cozinha e bar</option>
<option value="kitchen" @selected($station==='kitchen')>Cozinha</option>
<option value="bar" @selected($station==='bar')>Bar</option>
</select>
</div>
<button class="button secondary">Filtrar</button>
</form>
@if($items->isEmpty())<section class="operation-panel">
<div class="operation-empty">Nenhum item aguardando preparo ou entrega neste destino.</div>
</section>
@endif
<div class="operation-production">
@foreach(['queued'=>'Na fila','preparing'=>'Em preparo','ready'=>'Prontos para servir'] as $status=>$label)
<section class="operation-production-column">
<div class="operation-heading">
<h2>{{ $label }}</h2>
<span class="operation-badge {{ $status }}">{{ $items->where('status',$status)->count() }}</span>
</div>
@foreach($items->where('status',$status) as $item)
<article class="operation-production-ticket">
    <div class="operation-heading">
<strong>{{ $item->order->table?->name ?? 'Atendimento' }}</strong>
<span class="operation-badge">{{ $item->station==='bar'?'Bar':'Cozinha' }}</span>
</div>
    <h3>{{ number_format($item->quantity,3,',','.') }} × {{ $item->description }}</h3>
    @if(filled($item->notes))<p class="operation-notes">{{ $item->notes }}</p>
@endif
    <p class="hint">Comanda #{{ $item->order->id }} · Enviado {{ $item->sent_at?->format('d/m H:i') }}</p>
    @if($item->sent_at)<p class="operation-ticket-time"><time data-operation-elapsed="{{ $item->sent_at->toIso8601String() }}" data-elapsed-prefix="Há" data-elapsed-suffix="desde o envio">Há {{ max(0,(int)$item->sent_at->diffInMinutes(now())) }} min desde o envio</time></p>@endif
    @if($status!=='ready')
    @php($nextStatus=['queued'=>'preparing','preparing'=>'ready','ready'=>'delivered'][$status])
    <form method="post" action="{{ route('restaurant.items.status',['company'=>$tenant->slug,'order'=>$item->order->id,'item'=>$item->id]) }}">
@csrf
<input type="hidden" name="version" value="{{ $item->order->version }}">
<input type="hidden" name="status" value="{{ $nextStatus }}">
<input type="hidden" name="return_to" value="production">
<button class="button {{ $status==='ready'?'secondary':'' }}">{{ ['preparing'=>'Iniciar preparo','ready'=>'Marcar pronto','delivered'=>'Registrar entrega'][$nextStatus] }}</button>
</form>
    @else<p class="hint">Aguardando entrega pelo atendimento.</p>
    @endif
    @if($currentUser->allows('restaurant.print'))<p><a target="_blank" rel="noopener" href="{{ route('restaurant.orders.ticket',['company'=>$tenant->slug,'order'=>$item->order->id,'station'=>$item->station]) }}">Imprimir último envio da comanda</a></p>@endif
    @if($currentUser->allows('restaurant.use'))<p>
<a href="{{ route('restaurant.orders.show',['company'=>$tenant->slug,'order'=>$item->order->id]) }}">Abrir comanda</a>
</p>@endif
</article>
@endforeach
</section>
@endforeach
</div>
@endsection
