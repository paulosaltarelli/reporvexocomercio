@extends('layout')
@section('title','Compras')
@section('subtitle','Registre o recebimento e atualize estoque, custo médio e contas a pagar de uma só vez.')
@section('actions')
@if($currentUser->allows('purchases.manage'))<a class="button" href="{{ route('purchase.new',['company'=>$tenant->slug]) }}">+ Receber compra</a>@endif
@endsection
@section('content')
<section class="panel">
    <div class="scroll"><table>
        <thead><tr><th>Compra</th><th>Recebimento</th><th>Fornecedor</th><th>Referência</th><th class="num">Total</th></tr></thead>
        <tbody>
        @forelse($purchases as $purchase)
            <tr><td><a href="{{ route('purchase.show',['company'=>$tenant->slug,'id'=>$purchase->id]) }}" aria-label="Abrir compra #{{ $purchase->id }}">#{{ $purchase->id }}</a></td><td>{{ \Carbon\Carbon::parse($purchase->purchased_on)->format('d/m/Y') }}</td><td>{{ $purchase->supplier?->name ?? 'Não informado' }}</td><td>{{ $purchase->reference ?? '—' }}</td><td class="num">R$ {{ number_format($purchase->total,2,',','.') }}</td></tr>
        @empty
            <tr><td colspan="5" class="empty">Nenhuma compra recebida. Registre a primeira para abastecer o estoque.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $purchases->links() }}
</section>
@endsection
