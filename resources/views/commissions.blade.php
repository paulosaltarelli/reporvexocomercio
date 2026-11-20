@extends('layout')
@section('title',($personal??false)?'Minhas comissões':'Comissões da equipe')
@section('subtitle',($personal??false)?'Acompanhe somente suas vendas, comissões e pagamentos.':'Confira a apuração, feche comissões e acompanhe o pagamento de cada vendedor.')
@section('actions')<button class="button secondary no-print" type="button" onclick="window.print()">Imprimir / salvar PDF</button>@endsection
@section('content')
<div class="actions commission-period-shortcuts no-print">
@foreach(['Este mês'=>[now()->startOfMonth()->toDateString(),now()->toDateString()],'Mês anterior'=>[now()->subMonthNoOverflow()->startOfMonth()->toDateString(),now()->subMonthNoOverflow()->endOfMonth()->toDateString()],'Últimos 30 dias'=>[now()->subDays(29)->toDateString(),now()->toDateString()]] as $label=>$dates)
<a class="button secondary" href="{{ route($personal?'commissions.mine':'commissions',['company'=>$tenant->slug,'from'=>$dates[0],'to'=>$dates[1]]+(!$personal&&$sellerId?['salesperson_id'=>$sellerId]:[])) }}">{{ $label }}</a>
@endforeach
</div>
<p class="report-period">Vendas de {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} a {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }} · recebimentos acumulados até o momento da consulta</p>
<form class="panel toolbar filter-bar commission-filters" method="get">
    <div><label for="commission-from">Vendas de</label><input id="commission-from" type="date" name="from" value="{{ $from }}" required></div>
    <div><label for="commission-to">Vendas até</label><input id="commission-to" type="date" name="to" value="{{ $to }}" required></div>
    @if(!($personal??false))<div><label for="commission-seller">Vendedor</label><select id="commission-seller" name="salesperson_id"><option value="">Todos os vendedores</option>@foreach($sellers as $seller)<option value="{{ $seller->id }}" @selected((string)$sellerId===(string)$seller->id)>{{ $seller->name }}{{ $seller->active?'':' · inativo' }}</option>@endforeach</select></div>@endif
    <button type="submit" class="button secondary">Atualizar relatório</button>
</form>
@if($personal)
@include('commissions.personal-summary')
@else
<div class="cards">
    <div class="card"><small>Vendas líquidas com comissão</small><strong>R$ {{ number_format($totals['net_sales'],2,',','.') }}</strong><small>Descontos nessas vendas: R$ {{ number_format($totals['discounts'],2,',','.') }}</small></div>
    <div class="card"><small>Comissão apurada nas vendas</small><strong>R$ {{ number_format($totals['commission'],2,',','.') }}</strong><small>Regras registradas em cada venda</small></div>
    <div class="card featured"><small>Comissão elegível pelo regime</small><strong>R$ {{ number_format($totals['eligible'],2,',','.') }}</strong><small>Caixa: proporcional recebido · competência: integral</small></div>
    <div class="card"><small>Comissão ainda não elegível</small><strong>R$ {{ number_format($totals['awaiting'],2,',','.') }}</strong><small>Ainda depende do recebimento das vendas</small></div>
</div>
@endif
<div class="form-note">O período filtra a <strong>data da venda</strong>, apenas em vendas com comissão positiva. O regime é o registrado na venda: caixa usa os recebimentos acumulados até agora, inclusive baixas fora do período; competência considera a comissão integral. A apuração das vendas não representa pagamento ao vendedor; os pagamentos efetivos aparecem no histórico de fechamentos.</div>
@if(!($personal??false)&&$currentUser->allows('commissions.close'))
<section class="panel"><h2>Fechar comissões</h2><p>Escolha o vendedor para conferir o saldo liberado até a data final. Fechar cria uma conta a pagar; a baixa será registrada quando o pagamento acontecer.</p><form method="get" action="{{ route('commission.preview',['company'=>$tenant->slug]) }}" class="form-grid"><input type="hidden" name="from" value="{{ $from }}"><input type="hidden" name="to" value="{{ $to }}"><div class="field"><label for="closure-seller">Vendedor do fechamento</label><select id="closure-seller" name="salesperson_id" required><option value="">Selecione</option>@foreach($sellers as $seller)<option value="{{ $seller->id }}" @selected((string)$sellerId===(string)$seller->id)>{{ $seller->name }}{{ $seller->active?'':' · inativo' }}</option>@endforeach</select></div><div class="actions"><button type="submit" class="button secondary">Conferir fechamento</button></div></form></section>
@endif
@include('commissions.history')
<section class="panel">
    <div class="section-heading"><h2>Vendas que compõem a apuração</h2><span class="hint">{{ $rows->total() }} {{ $rows->total()===1?'venda encontrada':'vendas encontradas' }}</span></div>
    <div class="scroll" role="region" aria-label="Apuração de comissões por venda" tabindex="0"><table>
        <thead><tr><th>Venda / data</th><th>Vendedor / regime</th><th class="num">Venda líquida (R$)</th><th class="num">Descontos (R$)</th><th class="num">Comissão apurada (R$)</th><th class="num">Recebido acumulado (R$)</th><th class="num">Comissão elegível (R$)</th></tr></thead>
        <tbody>
        @forelse($rows as $sale)
            <tr><td><a href="{{ route('sale.show',['company'=>$tenant->slug,'id'=>$sale->id]) }}">#{{ $sale->id }}</a><small class="hint">{{ $sale->created_at->format('d/m/Y') }}</small></td><td class="product-name">{{ $sale->seller_name??'Não informado' }}<small class="hint">{{ $sale->commission_basis==='accrual'?'Competência':'Caixa' }}</small></td><td class="num">{{ number_format($sale->total,2,',','.') }}</td><td class="num">{{ number_format($sale->discount_total??0,2,',','.') }}</td><td class="num">{{ number_format($sale->commission_total,2,',','.') }}</td><td class="num">{{ number_format($sale->settled_amount??0,2,',','.') }}</td><td class="num"><strong>{{ number_format($sale->eligible_commission,2,',','.') }}</strong></td></tr>
        @empty
            <tr><td colspan="7" class="empty">Nenhuma venda com comissão positiva encontrada para este período e vendedor.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $rows->links() }}
    <p class="hint">Os totais acima consideram todo o filtro, não apenas esta página. Vendas antigas sem comissão registrada não recebem comissão retroativa neste relatório.</p>
</section>
@endsection
