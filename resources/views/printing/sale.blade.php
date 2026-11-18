<header>
    <h1>{{ $tenant->name }}</h1>
    <h2>Recibo de venda #{{ $sale->id }}</h2>
    <p><strong>Documento não fiscal</strong></p>
    <p>{{ $sale->created_at->format('d/m/Y H:i') }}</p>
    <p>{{ $sale->status === 'cancelled' ? 'Venda cancelada' : ($sale->paid ? 'Venda recebida' : 'Venda com saldo a receber') }}</p>
</header>
<div class="block">
    <p>Cliente: {{ $sale->customer?->name ?? 'Consumidor final' }}</p>
    <p>Vendedor: {{ $sale->seller_name ?? 'Não informado' }}</p>
</div>
<div class="block">
    <h2>Itens</h2>
    @foreach($sale->items as $item)
        <div class="entry">
            <strong>{{ $item->description }}</strong>
            <div class="row">
                <span>{{ number_format($item->quantity, 3, ',', '.') }} × R$ {{ number_format($item->unit_price, 2, ',', '.') }}</span>
                <span class="amount">R$ {{ number_format($item->total, 2, ',', '.') }}</span>
            </div>
            @if((float) $item->discount_amount > 0)
                <p>Desconto: R$ {{ number_format($item->discount_amount, 2, ',', '.') }}</p>
            @endif
        </div>
    @endforeach
</div>
<div class="block">
    <div class="row"><span>Subtotal</span><span class="amount">R$ {{ number_format($sale->subtotal ?? bcadd((string) $sale->total, (string) ($sale->discount_total ?? 0), 2), 2, ',', '.') }}</span></div>
    <div class="row"><span>Descontos</span><span class="amount">R$ {{ number_format($sale->discount_total ?? 0, 2, ',', '.') }}</span></div>
    @if(bccomp($passedCardFee, '0', 2) > 0)
        <div class="row"><span>Acréscimo do cartão</span><span class="amount">R$ {{ number_format($passedCardFee, 2, ',', '.') }}</span></div>
    @endif
    <div class="row total"><span>Total</span><span class="amount">R$ {{ number_format($sale->total, 2, ',', '.') }}</span></div>
</div>
<div class="block">
    <h2>Pagamento na venda</h2>
    @forelse($paymentPlan as $part)
        <div class="entry">
            <div class="row"><span>{{ $part['label'] }}@if($part['brand_name'] ?? null) · {{ $part['brand_name'] }} {{ $part['card_installments'] }}x @endif</span><span class="amount">R$ {{ number_format($part['amount'], 2, ',', '.') }}</span></div>
            <p>{{ $part['paid'] ? 'Recebido na venda' : $part['installments'].' parcela(s), a partir de '.\Carbon\Carbon::parse($part['due_date'])->format('d/m/Y') }}</p>
            @if((float) $part['change'] > 0)
                <p>Troco: R$ {{ number_format($part['change'], 2, ',', '.') }}</p>
            @endif
        </div>
    @empty
        <p>{{ app(\App\Services\PaymentMethods::class)->labels('sale_payment_method', [$sale->id])[$sale->id] ?? \App\Services\PaymentMethods::TYPES[$sale->payment_method] ?? $sale->payment_method }}</p>
        @if($sale->due_date)
            <p>Vencimento: {{ \Carbon\Carbon::parse($sale->due_date)->format('d/m/Y') }}</p>
        @endif
    @endforelse
</div>
