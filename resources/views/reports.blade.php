@extends('layout')
@section('title','Relatórios')
@section('subtitle','Compare a operação e o movimento financeiro sem confundir vendas com recebimentos.')
@section('actions')<button type="button" class="button secondary no-print" onclick="window.print()">Imprimir / salvar PDF</button>@endsection
@section('content')
<p class="report-period">Período: {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} a {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}</p>
<form class="panel toolbar" method="get"><div><label for="from">De</label><input id="from" name="from" type="date" value="{{ $from }}" required></div><div><label for="to">Até</label><input id="to" name="to" type="date" value="{{ $to }}" required></div><button class="primary">Atualizar período</button></form>
<div class="cards"><div class="card featured"><small>Vendas concluídas no período</small><strong>R$ {{ number_format($revenue,2,',','.') }}</strong><small>{{ $saleCount }} vendas · cancelamentos excluídos</small></div><div class="card"><small>Custo histórico dos itens vendidos</small><strong>R$ {{ number_format($cost,2,',','.') }}</strong><small>Produtos e custos dos serviços</small></div><div class="card"><small>Resultado bruto da operação</small><strong>R$ {{ number_format($revenue-$cost,2,',','.') }}</strong><small>Antes de despesas e tributos</small></div><div class="card"><small>Estoque valorizado atual</small><strong>R$ {{ number_format($stockValue,2,',','.') }}</strong><small>Saldo atual, independente do período</small></div></div>
<section class="panel"><h2>Movimento financeiro por data de baixa</h2><div class="cards"><div class="card"><small>Entradas registradas</small><strong>R$ {{ number_format($cashIn,2,',','.') }}</strong></div><div class="card"><small>Saídas e reembolsos registrados</small><strong>R$ {{ number_format($cashOut,2,',','.') }}</strong></div><div class="card"><small>Saldo dos movimentos no período</small><strong>R$ {{ number_format($cashIn-$cashOut,2,',','.') }}</strong></div></div><p class="hint">Considera baixas e estornos registrados manualmente, pela data financeira. Não é saldo bancário conciliado nem saldo de abertura de caixa.</p></section>
<section class="panel"><h2>Itens mais vendidos no período</h2><div class="scroll"><table><thead><tr><th>Produto ou serviço</th><th class="num">Quantidade</th><th class="num">Total vendido</th></tr></thead><tbody>
@forelse($top as $item)<tr><td>{{ $item->description }}</td><td class="num">{{ number_format($item->quantity,3,',','.') }}</td><td class="num">R$ {{ number_format($item->total,2,',','.') }}</td></tr>
@empty<tr><td colspan="3" class="empty">Nenhuma venda concluída neste período.</td></tr>
@endforelse</tbody></table></div></section>
<section class="panel"><h2>Exportar para conferência</h2><p class="muted">Arquivos CSV compatíveis com planilhas. O estoque é sempre atual; os demais arquivos respeitam as datas acima.</p><div class="actions" style="flex-wrap:wrap">
@foreach(['stock'=>'Estoque valorizado','movements'=>'Histórico do estoque','sales'=>'Vendas','payments'=>'Baixas e reembolsos','entries'=>'Títulos por vencimento'] as $kind=>$label)<a class="button secondary" href="{{ route('report.csv',['company'=>$tenant->slug,'kind'=>$kind,'from'=>$from,'to'=>$to]) }}">{{ $label }}</a>
@endforeach</div></section>
@endsection
