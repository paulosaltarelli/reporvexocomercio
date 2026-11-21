@extends('printing.thermal')
@section('title','Pré-conta da comanda #'.$order->id)
@section('content')
@include('restaurant.print-header')
<section class="block receipt-id">
    <p class="receipt-nonfiscal">Documento não fiscal</p>
    <h2>Pré-conta #{{ $order->id }}</h2>
    <p><strong>{{ $order->table?->name }}</strong></p>
    <p>Abertura {{ $order->created_at->format('d/m/Y H:i') }}</p>
    <p>{{ ['open'=>'Comanda em atendimento','closed'=>'Comanda encerrada','cancelled'=>'Comanda cancelada'][$order->status] ?? $order->status }}</p>
</section>
<section class="block">
    <p>Cliente {{ $order->customer?->name ?? 'Não identificado' }}</p>
    @if(filled($order->customer?->phone))<p>Telefone do cliente {{ $order->customer->phone }}</p>@endif
</section>
<section class="block receipt-items">
    <h2>Consumo da comanda</h2>
    @forelse($items as $item)
        <div class="entry">
            <strong class="item-title">{{ $item->description }}</strong>
            <div class="row item-values"><span>{{ number_format($item->quantity,3,',','.') }} × R$ {{ number_format($item->unit_price,2,',','.') }}</span><span class="amount">R$ {{ number_format($item->commercial_terms['total'],2,',','.') }}</span></div>
        </div>
    @empty<p>Nenhum item lançado para cobrança.</p>@endforelse
</section>
<section class="block receipt-totals"><div class="row total"><span>Total do consumo</span><span class="amount">R$ {{ number_format($total,2,',','.') }}</span></div></section>
<section class="block"><p>Conferência de consumo. Consulte o comprovante da venda após o fechamento e o recebimento.</p></section>
<footer class="block receipt-footer"><p>Vendra por Vexo</p></footer>
@endsection
