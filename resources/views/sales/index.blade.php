
@extends('layout')
@section('title','Vendas e recebimentos')
@section('subtitle','Produtos e serviços no mesmo atendimento, com estoque integrado.')
@section('actions')<a class="button" href="{{ route('sale.new',['company'=>$tenant->slug]) }}">+ Nova venda</a>
@endsection
@section('content')<section class="panel"><div class="scroll"><table><thead><tr><th>Venda</th><th>Data</th><th>Cliente</th><th>Situação</th><th>Vencimento</th><th class="num">Total</th><th></th></tr></thead><tbody>
@forelse($sales as $s)<tr><td>#{{ $s->id }}</td><td>{{ $s->created_at->format('d/m/Y H:i') }}</td><td>{{ $s->customer?->name??'Consumidor final' }}</td><td><span class="badge {{ $s->status==='cancelled'?'bad':(!$s->paid?'warn':'') }}">{{ $s->status==='cancelled'?'Cancelada':($s->paid?'Recebida':'A receber') }}</span></td><td>{{ $s->due_date?\Carbon\Carbon::parse($s->due_date)->format('d/m/Y'):'—' }}</td><td class="num">R$ {{ number_format($s->total,2,',','.') }}</td><td><a href="{{ route('sale.show',['company'=>$tenant->slug,'id'=>$s->id]) }}">Detalhes</a></td></tr>
@empty<tr><td colspan="7" class="empty">Registre sua primeira venda.</td></tr>
@endforelse</tbody></table></div>{{ $sales->links() }}</section>
@endsection
