@extends('layout')
@section('title','Vendas e recebimentos')
@section('subtitle','Produtos e serviços no mesmo atendimento, com estoque integrado e vendedor identificado.')
@section('actions')<a class="button" href="{{ route('sale.new',['company'=>$tenant->slug]) }}">+ Nova venda</a>@endsection
@section('content')
<section class="panel">
    <form class="toolbar seller-list-filter" method="get"><div><label for="sales-filter-seller">Vendedor</label><select id="sales-filter-seller" name="salesperson_id"><option value="">Todos os vendedores</option>@foreach($sellers as $seller)<option value="{{ $seller->id }}" @selected((string)$sellerId===(string)$seller->id)>{{ $seller->name }}{{ $seller->active?'':' · inativo' }}</option>@endforeach</select></div><button class="primary">Filtrar vendas</button>@if($sellerId)<a href="{{ route('sales',['company'=>$tenant->slug]) }}">Limpar filtro</a>@endif</form>
    <div class="scroll"><table><thead><tr><th>Venda</th><th>Data</th><th>Cliente</th><th>Vendedor creditado</th><th>Situação</th><th>Vencimento</th><th class="num">Total</th><th></th></tr></thead><tbody>
    @forelse($sales as $sale)
        <tr><td>#{{ $sale->id }}</td><td>{{ $sale->created_at->format('d/m/Y H:i') }}</td><td class="product-name">{{ $sale->customer?->name??'Consumidor final' }}</td><td class="product-name">{{ $sale->seller_name??'Não informado' }}</td><td><span class="badge {{ $sale->status==='cancelled'?'bad':(!$sale->paid?'warn':'') }}">{{ $sale->status==='cancelled'?'Cancelada':($sale->paid?'Recebida':'A receber') }}</span></td><td>{{ $sale->due_date?\Carbon\Carbon::parse($sale->due_date)->format('d/m/Y'):'—' }}</td><td class="num">R$ {{ number_format($sale->total,2,',','.') }}</td><td><a href="{{ route('sale.show',['company'=>$tenant->slug,'id'=>$sale->id]) }}">Detalhes</a></td></tr>
    @empty
        <tr><td colspan="8" class="empty">Nenhuma venda encontrada para este filtro.</td></tr>
    @endforelse
    </tbody></table></div>{{ $sales->links() }}
</section>
@endsection
