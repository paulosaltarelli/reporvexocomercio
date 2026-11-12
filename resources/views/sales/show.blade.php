
@extends('layout')
@section('title','Venda #'.$sale->id)
@section('subtitle','Comprovante interno. Este documento não é uma nota fiscal.')
@section('actions')<button class="button secondary no-print" onclick="window.print()">Imprimir</button>
@endsection
@section('content')<section class="panel"><div class="split"><div><h2>{{ $tenant->name }}</h2><p>{{ $sale->customer?->name??'Consumidor final' }}</p><p class="muted">{{ $sale->created_at->format('d/m/Y H:i') }} · {{ ['cash'=>'Dinheiro','pix'=>'Pix','credit'=>'Crédito','debit'=>'Débito','transfer'=>'Transferência','invoice'=>'A prazo'][$sale->payment_method] }}</p></div><div><span class="badge {{ $sale->status==='cancelled'?'bad':(!$sale->paid?'warn':'') }}">{{ $sale->status==='cancelled'?'Cancelada':($sale->paid?'Recebida':'A receber') }}</span>
@if($sale->due_date)<p>Vencimento: {{ \Carbon\Carbon::parse($sale->due_date)->format('d/m/Y') }}</p>
@endif</div></div><div class="scroll"><table><thead><tr><th>Descrição</th><th class="num">Quantidade</th><th class="num">Preço unitário</th><th class="num">Total</th></tr></thead><tbody>
@foreach($sale->items as $i)<tr><td>{{ $i->description }}</td><td class="num">{{ number_format($i->quantity,3,',','.') }}</td><td class="num">{{ number_format($i->unit_price,2,',','.') }}</td><td class="num">{{ number_format($i->total,2,',','.') }}</td></tr>
@endforeach</tbody></table></div><h2 class="num" style="margin-top:22px">Total R$ {{ number_format($sale->total,2,',','.') }}</h2><p>{{ $sale->notes }}</p>
@if($sale->status==='completed' && $currentUser->role==='admin')<div class="actions no-print">
@if(!$sale->paid)<form method="post" action="{{ route('sale.receive',['company'=>$tenant->slug,'id'=>$sale->id]) }}">
@csrf<button class="primary">Confirmar recebimento</button></form>
@endif<form method="post" action="{{ route('sale.cancel',['company'=>$tenant->slug,'id'=>$sale->id]) }}" onsubmit="return confirm('Cancelar esta venda e devolver os produtos ao estoque? O eventual reembolso deve ser realizado separadamente.');">
@csrf<button class="button danger">Cancelar e estornar estoque</button></form></div>
@endif<p class="hint">Cancelamento registra o estorno do estoque. Reembolsos financeiros devem ser realizados e conferidos fora do sistema nesta versão.</p></section>
@endsection
