@extends('layout')
@section('title','Compra #'.$purchase->id)
@section('subtitle','Recebimento registrado. Os produtos já foram incorporados ao estoque e ao custo médio.')
@section('actions')<button class="button secondary no-print" onclick="window.print()">Imprimir</button>@endsection
@section('content')
<section class="panel">
    <div class="split"><div><h2>{{ $purchase->supplier?->name ?? 'Fornecedor não informado' }}</h2><p>Recebida em {{ \Carbon\Carbon::parse($purchase->purchased_on)->format('d/m/Y') }}</p><p class="muted">Documento: {{ $purchase->reference ?? 'Não informado' }}</p></div><div><span class="badge">Recebida</span><p class="hint">Consulte as parcelas e baixas no financeiro para conferir o pagamento atual.</p></div></div>
    <div class="scroll"><table><thead><tr><th>Produto</th><th class="num">Quantidade</th><th class="num">Custo unitário (R$)</th><th class="num">Total (R$)</th></tr></thead><tbody>
    @foreach($purchase->items as $item)
        <tr><td>{{ $item->description }}</td><td class="num">{{ number_format($item->quantity,3,',','.') }}</td><td class="num">{{ number_format($item->unit_cost,4,',','.') }}</td><td class="num">{{ number_format($item->total,2,',','.') }}</td></tr>
    @endforeach
    </tbody></table></div>
    <div class="total-bar"><span>Total da compra</span><strong>R$ {{ number_format($purchase->total,2,',','.') }}</strong></div>
    @if($purchase->notes)<p class="preserve-lines">{{ $purchase->notes }}</p>@endif
    <div class="actions no-print"><a class="button" href="{{ route('finance',['company'=>$tenant->slug]) }}">Ver contas a pagar</a><a href="{{ route('purchases',['company'=>$tenant->slug]) }}">Voltar para compras</a></div>
    <p class="hint">Este é um comprovante interno. A compra não pode ser cancelada por esta tela; correções no estoque devem ser registradas como movimentações identificadas.</p>
</section>
@endsection
