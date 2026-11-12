@extends('layout')
@section('title','Comissões por vendedor')
@section('subtitle','Conferência das vendas com comissão. A apuração não representa pagamento ao vendedor nem cria uma conta a pagar.')
@section('actions')<button class="button secondary no-print" type="button" onclick="window.print()">Imprimir / salvar PDF</button>@endsection
@section('content')
<p class="report-period">Vendas de {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} a {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }} · recebimentos acumulados até o momento da consulta</p>
<form class="panel toolbar filter-bar commission-filters" method="get">
    <div><label for="commission-from">Vendas de</label><input id="commission-from" type="date" name="from" value="{{ $from }}" required></div>
    <div><label for="commission-to">Vendas até</label><input id="commission-to" type="date" name="to" value="{{ $to }}" required></div>
    <div><label for="commission-seller">Vendedor</label><select id="commission-seller" name="salesperson_id"><option value="">Todos os vendedores</option>@foreach($sellers as $seller)<option value="{{ $seller->id }}" @selected((string)$sellerId===(string)$seller->id)>{{ $seller->name }}{{ $seller->active?'':' · inativo' }}</option>@endforeach</select></div>
    <button class="primary">Atualizar relatório</button>
</form>
<div class="cards">
    <div class="card"><small>Vendas líquidas com comissão</small><strong>R$ {{ number_format($totals['net_sales'],2,',','.') }}</strong><small>Descontos nessas vendas: R$ {{ number_format($totals['discounts'],2,',','.') }}</small></div>
    <div class="card"><small>Comissão apurada nas vendas</small><strong>R$ {{ number_format($totals['commission'],2,',','.') }}</strong><small>Regras registradas em cada venda</small></div>
    <div class="card featured"><small>Parcela proporcional recebida</small><strong>R$ {{ number_format($totals['eligible'],2,',','.') }}</strong><small>Considera o recebido acumulado</small></div>
    <div class="card"><small>Parcela sobre saldo a receber</small><strong>R$ {{ number_format($totals['awaiting'],2,',','.') }}</strong><small>Ainda depende do recebimento das vendas</small></div>
</div>
<div class="form-note">O período filtra a <strong>data da venda</strong>, apenas em vendas com comissão positiva. A parcela proporcional usa os recebimentos acumulados dessas vendas até agora, inclusive baixas fora do período selecionado. Nenhum valor abaixo significa comissão já paga ao vendedor.</div>
<section class="panel">
    <div class="section-heading"><h2>Vendas que compõem a apuração</h2><span class="hint">{{ $rows->total() }} {{ $rows->total()===1?'venda encontrada':'vendas encontradas' }}</span></div>
    <div class="scroll" role="region" aria-label="Apuração de comissões por venda" tabindex="0"><table>
        <thead><tr><th>Venda / data</th><th>Vendedor</th><th class="num">Venda líquida (R$)</th><th class="num">Descontos (R$)</th><th class="num">Comissão apurada (R$)</th><th class="num">Recebido acumulado (R$)</th><th class="num">Comissão proporcional recebida (R$)</th></tr></thead>
        <tbody>
        @forelse($rows as $sale)
            <tr><td><a href="{{ route('sale.show',['company'=>$tenant->slug,'id'=>$sale->id]) }}">#{{ $sale->id }}</a><small class="hint">{{ $sale->created_at->format('d/m/Y') }}</small></td><td class="product-name">{{ $sale->seller_name??'Não informado' }}</td><td class="num">{{ number_format($sale->total,2,',','.') }}</td><td class="num">{{ number_format($sale->discount_total??0,2,',','.') }}</td><td class="num">{{ number_format($sale->commission_total,2,',','.') }}</td><td class="num">{{ number_format($sale->settled_amount??0,2,',','.') }}</td><td class="num"><strong>{{ number_format($sale->eligible_commission,2,',','.') }}</strong></td></tr>
        @empty
            <tr><td colspan="7" class="empty">Nenhuma venda com comissão positiva encontrada para este período e vendedor.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $rows->links() }}
    <p class="hint">Os totais acima consideram todo o filtro, não apenas esta página. Vendas antigas sem comissão registrada não recebem comissão retroativa neste relatório.</p>
</section>
@endsection
