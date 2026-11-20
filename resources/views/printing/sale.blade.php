@php
    $profile = $companyProfile ?? null;
    $name = $profile?->trade_name ?: ($profile?->legal_name ?: $tenant->name);
    $address = implode(', ',array_filter([$profile?->street,$profile?->number,$profile?->district]));
    $city = implode(' / ',array_filter([$profile?->city,$profile?->state]));
    $money = static fn ($value) => 'R$ '.number_format((float)$value,2,',','.');
    $discount = (string)($sale->discount_total ?? '0');
    $subtotal = $sale->subtotal ?? bcsub(bcadd((string)$sale->total,$discount,2),$passedCardFee,2);
    $hasEntries = (int)$receiptLedger->entry_count > 0;
    $cancelled = $sale->status === 'cancelled';
    $settlementKnown = $hasEntries || bccomp((string)$sale->total,'0',2) === 0;
@endphp
<header class="receipt-company">
    @if($profile?->logo_path)
        <img class="receipt-logo" src="{{ route('business.logo',['company'=>$tenant->slug,'v'=>$profile->logoVersion()]) }}" alt="{{ $name }}">
    @endif
    <h1>{{ $name }}</h1>
    @if($profile?->legal_name && $profile->legal_name !== $name)<p>{{ $profile->legal_name }}</p>@endif
    @if($profile?->document)<p>{{ strlen(preg_replace('/\D/','',$profile->document)) === 14 ? 'CNPJ' : 'Documento' }} {{ $profile->document }}</p>@endif
    @if($address)<p>{{ $address }}</p>@endif
    @if($city || $profile?->postal_code)<p>{{ implode(' · ',array_filter([$city,$profile?->postal_code])) }}</p>@endif
    @if($profile?->phone)<p>Tel. {{ $profile->phone }}</p>@elseif($profile?->email)<p>{{ $profile->email }}</p>@endif
</header>
<section class="block receipt-id">
    <p class="receipt-nonfiscal">Documento não fiscal</p>
    <h2>Recibo de venda #{{ $sale->id }}</h2>
    <p>{{ $sale->created_at->format('d/m/Y H:i') }}</p>
    @if($cancelled)<p><strong>Venda cancelada</strong></p>@endif
</section>
<div class="block">
    <p>Cliente: {{ $sale->customer?->name ?? 'Consumidor final' }}</p>
    @if(filled($sale->customer?->phone))<p>Telefone do cliente: {{ $sale->customer->phone }}</p>@endif
    @if($sale->seller_name)<p>Vendedor: {{ $sale->seller_name }}</p>@endif
</div>
<section class="block receipt-items">
    <h2>Itens da venda</h2>
    <div class="row item-detail"><span>Qtd. × preço unitário</span><span>Total do item</span></div>
    @foreach($sale->items as $item)
        <div class="entry">
            <strong class="item-title">{{ $item->description }}</strong>
            @if($item->product_id || $item->service_id)
                <p class="item-detail">{{ $item->product_id ? 'Cód. do catálogo' : 'Cód. do serviço' }} {{ $item->product_id ?? $item->service_id }}</p>
            @endif
            <div class="row item-values">
                <span>{{ number_format($item->quantity,3,',','.') }} × {{ $money($item->unit_price) }}</span>
                <span class="amount">{{ $money($item->total) }}</span>
            </div>
            @if(bccomp((string)($item->discount_amount ?? 0),'0',2)>0)
                <p class="item-detail">Desconto no item: {{ $money($item->discount_amount) }}</p>
            @endif
        </div>
    @endforeach
</section>
<section class="block receipt-totals" aria-label="Totais da venda">
    @if(bccomp($discount,'0',2)>0 || bccomp($passedCardFee,'0',2)>0)
        <div class="row"><span>Subtotal</span><span class="amount">{{ $money($subtotal) }}</span></div>
    @endif
    @if(bccomp($discount,'0',2)>0)
        <div class="row"><span>Descontos</span><span class="amount">− {{ $money($discount) }}</span></div>
    @endif
    @if(bccomp($passedCardFee,'0',2)>0)
        <div class="row"><span>Acréscimo do cartão</span><span class="amount">{{ $money($passedCardFee) }}</span></div>
    @endif
    <div class="row total"><strong>Total da venda</strong><strong class="amount">{{ $money($sale->total) }}</strong></div>
</section>
<section class="block receipt-payments">
    <h2>Condições de pagamento na venda</h2>
    @forelse($paymentPlan as $part)
        <div class="entry">
            <div class="row"><strong>{{ $part['label'] }}</strong><span class="amount">{{ $money($part['amount']) }}</span></div>
            @if($part['brand_name'] ?? null)<p>{{ $part['brand_name'] }} · {{ $part['card_installments'] }}×</p>@endif
            @if($part['paid'])
                <p>Recebido na venda</p>
            @else
                <p>A prazo · {{ $part['installments'] }} {{ (int)$part['installments'] === 1 ? 'parcela' : 'parcelas' }}@if(!empty($part['due_date'])), a partir de {{ \Carbon\Carbon::parse($part['due_date'])->format('d/m/Y') }}@endif</p>
            @endif
            @if(isset($part['cash_received']))<div class="row"><span>Dinheiro entregue</span><span class="amount">{{ $money($part['cash_received']) }}</span></div>@endif
            @if(bccomp((string)($part['change'] ?? 0),'0',2)>0)
                <div class="row"><span>Troco</span><span class="amount">{{ $money($part['change']) }}</span></div>
            @endif
        </div>
    @empty
        <p>{{ app(\App\Services\PaymentMethods::class)->labels('sale_payment_method',[$sale->id])[$sale->id] ?? \App\Services\PaymentMethods::TYPES[$sale->payment_method] ?? $sale->payment_method }}</p>
        @if($sale->due_date)<p>Vencimento: {{ \Carbon\Carbon::parse($sale->due_date)->format('d/m/Y') }}</p>@endif
    @endforelse
</section>
<section class="block receipt-settlement">
    <h2>Situação financeira</h2>
    @if($settlementKnown)
        <div class="row"><span>Recebido</span><span class="amount">{{ $money($received) }}</span></div>
        <div class="row"><strong>Saldo a receber</strong><strong class="amount">{{ $money($cancelled ? 0 : $receiptLedger->outstanding) }}</strong></div>
    @elseif($cancelled)
        <p>Venda cancelada · sem saldo a receber.</p>
    @else
        <p>{{ $sale->paid ? 'Venda registrada como recebida.' : 'Venda registrada como a receber.' }} Sem detalhamento de baixas.</p>
    @endif
</section>
<footer class="receipt-footer">Vendra por Vexo</footer>
