
@extends('layout')
@section('title','Venda #'.$sale->id)
@section('subtitle','Comprovante interno. Este documento não é uma nota fiscal.')
@section('actions')<button class="button secondary no-print" onclick="window.print()">Imprimir</button>
@endsection
@section('content')<section class="panel"><div class="split"><div><h2>{{ $tenant->name }}</h2><p>{{ $sale->customer?->name??'Consumidor final' }}</p><p class="muted">{{ $sale->created_at->format('d/m/Y H:i') }} · {{ ['cash'=>'Dinheiro','pix'=>'Pix','credit'=>'Crédito','debit'=>'Débito','transfer'=>'Transferência','invoice'=>'A prazo'][$sale->payment_method] }}</p></div><div><span class="badge {{ $sale->status==='cancelled'?'bad':(!$sale->paid?'warn':'') }}">{{ $sale->status==='cancelled'?'Cancelada':($sale->paid?'Recebida':'A receber') }}</span>
@if($sale->due_date)<p>Vencimento: {{ \Carbon\Carbon::parse($sale->due_date)->format('d/m/Y') }}</p>
@endif</div></div><p class="hint"><strong>Vendedor creditado:</strong> {{ $sale->seller_name??'Não informado' }} · <strong>Registrado por:</strong> {{ $sale->creator?->name??'Não informado' }}</p>
<div class="scroll"><table><thead><tr><th>Descrição</th><th class="num">Quantidade</th><th class="num">Preço bruto unitário (R$)</th><th class="num">Bruto (R$)</th><th class="num">Desconto (R$)</th><th class="num">Líquido (R$)</th></tr></thead><tbody>
@foreach($sale->items as $item)
<tr><td class="product-name">{{ $item->description }}</td><td class="num">{{ number_format($item->quantity,3,',','.') }}</td><td class="num">{{ number_format($item->unit_price,2,',','.') }}</td><td class="num">{{ number_format($item->gross_total??$item->total,2,',','.') }}</td><td class="num">{{ number_format($item->discount_amount??0,2,',','.') }}@if(bccomp((string)($item->discount_amount??'0'),'0',2)>0)<small class="hint">{{ number_format($item->discount_percent??0,4,',','.') }}% total</small>@endif</td><td class="num"><strong>{{ number_format($item->total,2,',','.') }}</strong></td></tr>
@endforeach
</tbody></table></div>
<div class="receipt-totals"><span>Subtotal bruto</span><strong>R$ {{ number_format($sale->subtotal??bcadd((string)$sale->total,(string)($sale->discount_total??'0'),2),2,',','.') }}</strong><span>Descontos</span><strong>R$ {{ number_format($sale->discount_total??0,2,',','.') }}</strong><span class="receipt-net">Total líquido</span><strong class="receipt-net">R$ {{ number_format($sale->total,2,',','.') }}</strong></div>
<p>{{ $sale->notes }}</p>
@if($currentUser->role==='admin')
<details class="disclosure compact no-print" style="margin-top:24px"><summary>Comissão interna desta venda</summary><div class="disclosure-content"><p><strong>Apurada:</strong> R$ {{ number_format($sale->commission_total??0,2,',','.') }} · <strong>Vendedor:</strong> {{ $sale->seller_name??'Não informado' }}</p><div class="scroll"><table><thead><tr><th>Item</th><th class="num">Alíquota de comissão</th><th class="num">Comissão apurada (R$)</th></tr></thead><tbody>@foreach($sale->items as $item)<tr><td>{{ $item->description }}</td><td class="num">{{ number_format($item->commission_rate??0,2,',','.') }}%</td><td class="num">{{ number_format($item->commission_amount??0,2,',','.') }}</td></tr>@endforeach</tbody></table></div><p class="hint">Informação interna, excluída da impressão do comprovante. Apuração não significa pagamento ao vendedor; vendas canceladas não compõem o relatório de comissões.</p><a href="{{ route('commissions',['company'=>$tenant->slug,'salesperson_id'=>$sale->salesperson_id,'from'=>$sale->created_at->toDateString(),'to'=>$sale->created_at->toDateString()]) }}">Conferir relatório de comissões</a></div></details>
@endif
@if($sale->status==='completed' && $currentUser->role==='admin')
<div class="form-note no-print"><strong>Recebido:</strong> R$ {{ number_format($received,2,',','.') }} · <strong>Saldo:</strong> R$ {{ number_format(max(0,bcsub((string)$sale->total,(string)$received,2)),2,',','.') }}</div>
<div class="actions no-print">
    <a class="button secondary" href="{{ route('fiscal.sale',['company'=>$tenant->slug,'id'=>$sale->id]) }}">Conferir dados fiscais</a>
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
<div class="form-note no-print"><strong>Situação fiscal</strong>@forelse($fiscalDocuments as $fiscalDocument)<p>NFC-e {{ $fiscalDocument->number }} / série {{ $fiscalDocument->series }} · {{ ['pending'=>'Na fila','sent'=>'Enviada, aguardando confirmação','uncertain'=>'Consulta necessária','authorized'=>'Autorizada em homologação','rejected'=>'Rejeitada','cancelled'=>'Cancelada'][$fiscalDocument->state]??$fiscalDocument->state }} @if($currentUser->role==='admin')<a href="{{ route('fiscal.document',['company'=>$tenant->slug,'id'=>$fiscalDocument->id]) }}">Acompanhar documento</a>@endif</p>@empty<p>Nenhuma emissão solicitada.</p>@endforelse<p>Cancelar a venda não cancela automaticamente a NFC-e. O cancelamento fiscal é solicitado separadamente.</p></div><p class="hint">Este comprovante interno não substitui nota fiscal. Não realiza cobranças ou reembolsos automáticos.</p></section>
@endsection
