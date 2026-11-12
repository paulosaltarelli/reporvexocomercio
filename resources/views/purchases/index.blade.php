@extends('layout')
@section('title','Compras')
@section('subtitle','Registre o recebimento e atualize estoque, custo médio e contas a pagar de uma só vez.')
@section('actions')
<a class="button" href="{{ route('purchase.new',['company'=>$tenant->slug]) }}">+ Receber compra</a>
@endsection
@section('content')
<section class="panel">
    <div class="scroll"><table>
        <thead><tr><th>Compra</th><th>Recebimento</th><th>Fornecedor</th><th>Documento</th><th class="num">Total</th><th></th></tr></thead>
        <tbody>
        @forelse($purchases as $purchase)
            <tr><td>#{{ $purchase->id }}</td><td>{{ \Carbon\Carbon::parse($purchase->purchased_on)->format('d/m/Y') }}</td><td>{{ $purchase->supplier?->name ?? 'Não informado' }}</td><td>{{ $purchase->reference ?? '—' }}</td><td class="num">R$ {{ number_format($purchase->total,2,',','.') }}</td><td><a href="{{ route('purchase.show',['company'=>$tenant->slug,'id'=>$purchase->id]) }}">Detalhes</a></td></tr>
        @empty
            <tr><td colspan="6" class="empty">Nenhuma compra recebida. Registre a primeira para abastecer o estoque.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $purchases->links() }}
</section>
@endsection
