@extends('layout')
@section('title','Vendas e recebimentos')
@section('subtitle','Produtos e serviços no mesmo atendimento, com estoque integrado e vendedor identificado.')
@section('actions')@if($currentUser->allows('sales.create'))<a class="button" href="{{ route('sale.new',['company'=>$tenant->slug]) }}">+ Nova venda</a>@endif @endsection
@section('content')
<section class="panel">
    <form class="filter-bar no-print" method="get" aria-label="Filtrar vendas">
        <div><label for="sales-filter-search">Buscar venda</label><input id="sales-filter-search" name="q" value="{{ $search??'' }}" maxlength="100" placeholder="Número ou cliente"></div>
        <div><label for="sales-filter-seller">Vendedor</label><select id="sales-filter-seller" name="salesperson_id"><option value="">Todos os vendedores</option>@foreach($sellers as $seller)<option value="{{ $seller->id }}" @selected((string)$sellerId===(string)$seller->id)>{{ $seller->name }}{{ $seller->active?'':' · inativo' }}</option>@endforeach</select></div>
        <div><label for="sales-filter-status">Venda</label><select id="sales-filter-status" name="status"><option value="">Todas</option>@foreach(['completed'=>'Concluídas','cancelled'=>'Canceladas'] as $value=>$label)<option value="{{ $value }}" @selected(($statusFilter??'')===$value)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="sales-filter-payment">Recebimento</label><select id="sales-filter-payment" name="payment_status"><option value="">Todos</option><option value="paid" @selected(($paymentStatus??'')==='paid')>Recebidas</option><option value="pending" @selected(($paymentStatus??'')==='pending')>A receber</option></select></div>
        <div><label for="sales-filter-from">Venda a partir de</label><input id="sales-filter-from" type="date" name="from" value="{{ $from??'' }}"></div>
        <div><label for="sales-filter-to">Venda até</label><input id="sales-filter-to" type="date" name="to" value="{{ $to??'' }}"></div>
        <button class="primary">Aplicar filtros</button>
        @if(request()->filled('q') || request()->filled('status') || request()->filled('payment_status') || request()->filled('from') || request()->filled('to') || $sellerId)<a href="{{ route('sales',['company'=>$tenant->slug]) }}">Limpar filtros</a>@endif
    </form>
    <p class="hint">{{ $sales->total() }} {{ $sales->total()===1?'venda encontrada':'vendas encontradas' }} · Datas pela realização da venda. Recebimentos e estornos podem ocorrer em outro período.</p>
    <div class="scroll"><table><thead><tr><th>Venda</th><th>Data</th><th>Cliente</th><th>Vendedor creditado</th><th>Situação</th><th>Vencimento</th><th class="num">Total</th><th></th></tr></thead><tbody>
    @forelse($sales as $sale)
        <tr><td>#{{ $sale->id }}</td><td>{{ $sale->created_at->format('d/m/Y H:i') }}</td><td class="product-name">{{ $sale->customer?->name??'Consumidor final' }}</td><td class="product-name">{{ $sale->seller_name??'Não informado' }}</td><td><span class="badge {{ $sale->status==='cancelled'?'bad':(!$sale->paid?'warn':'') }}">{{ $sale->status==='cancelled'?'Cancelada':($sale->paid?'Recebida':'A receber') }}</span></td><td>{{ $sale->due_date?\Carbon\Carbon::parse($sale->due_date)->format('d/m/Y'):'—' }}</td><td class="num">R$ {{ number_format($sale->total,2,',','.') }}</td><td><a href="{{ route('sale.show',['company'=>$tenant->slug,'id'=>$sale->id]) }}">Detalhes</a></td></tr>
    @empty
        <tr><td colspan="8" class="empty">Nenhuma venda encontrada para este filtro.</td></tr>
    @endforelse
    </tbody></table></div>{{ $sales->links() }}
</section>
@endsection
