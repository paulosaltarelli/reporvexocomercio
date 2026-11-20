@extends('layout')
@section('title','Compra #'.$purchase->id)
@section('subtitle','Recebimento registrado. Os produtos já foram incorporados ao estoque e ao custo médio.')
@section('actions')<button class="button secondary no-print" onclick="window.print()">Imprimir</button>@endsection
@section('content')
<section class="panel">
    <div class="split"><div><h2>{{ $purchase->supplier?->name ?? 'Fornecedor não informado' }}</h2><p>Recebida em {{ \Carbon\Carbon::parse($purchase->purchased_on)->format('d/m/Y') }}</p><p class="muted">Referência: {{ $purchase->reference ?? 'Não informado' }}</p></div><div><span class="badge">Recebida</span><p class="hint">Consulte as parcelas e baixas no financeiro para conferir o pagamento atual.</p></div></div>
    @if($invoice ?? null)
        <h2>Nota {{ $invoice['number'] }} · série {{ $invoice['series'] }}</h2>
        <p>Emitida em {{ \Carbon\Carbon::parse($invoice['issued_on'])->format('d/m/Y') }} por {{ $invoice['supplier_name'] }} {{ $invoice['supplier_document'] }}.</p>
        @if($invoice['access_key'])<p style="overflow-wrap:anywhere">Chave NF-e {{ $invoice['access_key'] }}</p>@endif
        <p>Mercadorias R$ {{ number_format($invoice['gross'],2,',','.') }} · Valor de aquisição R$ {{ number_format($invoice['totals']['acquisition'],2,',','.') }} · Créditos R$ {{ number_format($invoice['totals']['credit'],2,',','.') }} · Despesas do período R$ {{ number_format($invoice['totals']['expense'],2,',','.') }}</p>
        @php($fiscalLines=collect($invoice['lines'])->keyBy('item_id'))
    @endif
    <div class="scroll"><table><thead><tr><th>Produto</th><th class="num">Quantidade</th><th class="num">Valor unitário (R$)</th><th class="num">Total (R$)</th></tr></thead><tbody>
    @foreach($purchase->items as $item)
        <tr><td>{{ $item->description }}</td><td class="num">{{ number_format($item->quantity,3,',','.') }}</td><td class="num">{{ number_format($item->unit_cost,4,',','.') }}</td><td class="num">{{ number_format($item->total,2,',','.') }}</td></tr>
        @if(isset($fiscalLines[$item->id]))
            @php($line=$fiscalLines[$item->id])
            <tr><td colspan="4">
                Valor bruto R$ {{ number_format($line['gross'],2,',','.') }} · Custo de aquisição R$ {{ number_format($line['acquisition'],2,',','.') }}
                @foreach(\App\Services\PurchaseInvoice::CHARGES as $key=>$name)
                    @if(bccomp($line[$key],'0',2)>0)
                        <div>{{ $name }} R$ {{ number_format($line[$key],2,',','.') }} (rateado R$ {{ number_format($line['allocated'][$key],2,',','.') }})</div>
                    @endif
                @endforeach
                @foreach($line['taxes'] as $code=>$tax)
                    <div>{{ \App\Services\PurchaseInvoice::TAXES[$code] }} R$ {{ number_format($tax['amount'],2,',','.') }} · {{ $tax['composition']==='added'?'Acrescido':'Incluído' }} · {{ \App\Services\PurchaseInvoice::TREATMENTS[$tax['treatment']] }}</div>
                @endforeach
            </td></tr>
        @endif
    @endforeach
    </tbody></table></div>
    <div class="total-bar"><span>Total da compra</span><strong>R$ {{ number_format($purchase->total,2,',','.') }}</strong></div>
    @if($purchase->notes)<p class="preserve-lines">{{ $purchase->notes }}</p>@endif
    <div class="actions no-print"><a class="button" href="{{ route('finance',['company'=>$tenant->slug]) }}">Ver contas a pagar</a><a href="{{ route('purchases',['company'=>$tenant->slug]) }}">Voltar para compras</a></div>
    <p class="hint">Este é um comprovante interno. A compra não pode ser cancelada por esta tela; correções no estoque devem ser registradas como movimentações identificadas.</p>
</section>
@endsection
