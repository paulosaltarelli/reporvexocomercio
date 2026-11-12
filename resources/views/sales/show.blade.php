
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
@if($sale->status==='completed' && $currentUser->role==='admin')
<div class="form-note no-print"><strong>Recebido:</strong> R$ {{ number_format($received,2,',','.') }} · <strong>Saldo:</strong> R$ {{ number_format(max(0,bcsub((string)$sale->total,(string)$received,2)),2,',','.') }}</div>
<div class="actions no-print">
    @if(!$sale->paid)
        <a class="button" href="{{ route('finance',['company'=>$tenant->slug,'sale'=>$sale->id]) }}">Registrar recebimento</a>
    @endif
</div>
<details class="disclosure compact no-print" style="margin-top:24px">
    <summary>Cancelar venda e devolver os produtos ao estoque</summary>
    <form class="disclosure-content" method="post" action="{{ route('sale.cancel',['company'=>$tenant->slug,'id'=>$sale->id]) }}" onsubmit="return confirm('Confirma o cancelamento desta venda e o estorno do estoque?');">
        @csrf
        @if(bccomp((string)$received,'0',2)>0)
            <div class="form-note">Esta venda tem R$ {{ number_format($received,2,',','.') }} recebidos. O sistema registra o estorno financeiro, mas não transfere o dinheiro de volta ao cliente.</div>
            <input type="hidden" name="refund_confirmed" value="0">
            <label class="checkbox-line"><input type="checkbox" name="refund_confirmed" value="1" required> Confirmo que o reembolso de R$ {{ number_format($received,2,',','.') }} já foi realizado e conferido pelos mesmos meios dos pagamentos originais, fora do sistema.</label>
        @endif
        <div class="actions"><button class="button danger">Confirmar cancelamento</button></div>
    </form>
</details>
@endif
<p class="hint">Comprovante interno. Esta venda não emite nota fiscal nem realiza cobranças ou reembolsos automáticos.</p></section>
@endsection
