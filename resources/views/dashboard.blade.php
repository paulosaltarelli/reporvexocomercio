
@extends('layout')
@section('title','Visão geral')
@section('subtitle','Os números da operação, com clareza para o próximo passo.')
@section('actions')<a class="button" href="{{ route('sale.new',['company'=>$tenant->slug]) }}">+ Nova venda</a>
@endsection
@section('content')
@if($currentUser->role==='admin')
<div class="cards"><div class="card featured"><small>Vendas concluídas · todo o período</small><strong>R$ {{ number_format($revenue,2,',','.') }}</strong><small>Cancelamentos excluídos</small></div><div class="card"><small>A receber</small><strong>R$ {{ number_format($receivable,2,',','.') }}</strong><small>Vendas com pagamento pendente</small></div><div class="card"><small>Estoque a custo médio</small><strong>R$ {{ number_format($stockValue,2,',','.') }}</strong><small>Saldo × custo médio atual</small></div><div class="card"><small>Resultado bruto das vendas</small><strong>R$ {{ number_format($revenue-$cost,2,',','.') }}</strong><small>Antes de impostos e despesas</small></div></div>
@else
<div class="cards two"><div class="card quick-card"><h2>Atendimento de balcão</h2><p class="muted">Registre produtos e serviços na mesma venda, com baixa automática do estoque.</p><a class="button" href="{{ route('sale.new',['company'=>$tenant->slug]) }}">Registrar venda</a></div><div class="card quick-card"><h2>Consulte antes de vender</h2><p class="muted">Confira preço e saldo dos produtos para orientar o atendimento.</p><a class="button secondary" href="{{ route('products',['company'=>$tenant->slug]) }}">Consultar catálogo</a></div></div>
@endif
<div class="split"><section class="panel"><h2>Últimas vendas</h2><div class="scroll"><table><thead><tr><th>Venda</th><th>Cliente</th><th>Situação</th><th class="num">Total</th></tr></thead><tbody>
@forelse($recent as $s)<tr><td><a href="{{ route('sale.show',['company'=>$tenant->slug,'id'=>$s->id]) }}">#{{ $s->id }}</a></td><td>{{ $s->customer?->name ?? 'Consumidor final' }}</td><td><span class="badge {{ $s->status==='cancelled'?'bad':(!$s->paid?'warn':'') }}">{{ $s->status==='cancelled'?'Cancelada':($s->paid?'Recebida':'A receber') }}</span></td><td class="num">R$ {{ number_format($s->total,2,',','.') }}</td></tr>
@empty<tr><td colspan="4" class="empty">Suas vendas aparecerão aqui.</td></tr>
@endforelse</tbody></table></div></section><section class="panel"><h2>Atenção ao estoque</h2>
@forelse($lowStock as $p)<p><strong>{{ $p->name }}</strong><br><span class="muted">Saldo {{ number_format($p->quantity,3,',','.') }} {{ $p->unit }} · mínimo {{ $p->minimum_stock }}</span></p>
@empty<p class="muted">Nenhum produto abaixo do estoque mínimo.</p>
@endforelse</section></div>
@endsection
