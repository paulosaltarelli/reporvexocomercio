<header>
    <h1>{{ $tenant->name }}</h1>
    <h2>{{ $closure ? 'Fechamento de caixa' : 'Conferência de caixa · em aberto' }}</h2>
    <p>{{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</p>
</header>
<div class="block">
    <h2>Dinheiro físico</h2>
    @foreach(['opening_balance'=>'Abertura', 'cash_in'=>'Entradas', 'cash_out'=>'Saídas', 'expected_balance'=>'Esperado'] as $key => $label)
        <div class="row"><span>{{ $label }}</span><span class="amount">R$ {{ number_format($summary[$key], 2, ',', '.') }}</span></div>
    @endforeach
    @if($closure)
        <div class="row total"><span>Contado</span><span class="amount">R$ {{ number_format($closure->counted_balance, 2, ',', '.') }}</span></div>
        <div class="row"><span>Diferença</span><span class="amount">R$ {{ number_format($closure->difference, 2, ',', '.') }}</span></div>
    @endif
</div>
<div class="block">
    <h2>Movimentos por forma de pagamento</h2>
    @forelse($summary['totals_by_method'] as $method => $totals)
        <div class="entry">
            <strong>{{ \App\Services\PaymentMethods::TYPES[$method] ?? $method }}</strong>
            <div class="row"><span>Entradas</span><span class="amount">R$ {{ number_format($totals['in'], 2, ',', '.') }}</span></div>
            <div class="row"><span>Saídas / reembolsos</span><span class="amount">R$ {{ number_format($totals['out'], 2, ',', '.') }}</span></div>
            <div class="row"><span>Líquido</span><span class="amount">R$ {{ number_format($totals['net'], 2, ',', '.') }}</span></div>
            @isset($summary['counted_methods'][$method])
                <div class="row"><span>Conferido</span><span class="amount">R$ {{ number_format($summary['counted_methods'][$method], 2, ',', '.') }}</span></div>
                <div class="row"><span>Diferença</span><span class="amount">R$ {{ number_format($summary['differences_by_method'][$method], 2, ',', '.') }}</span></div>
            @endisset
        </div>
    @empty
        <p>Nenhuma baixa registrada.</p>
    @endforelse
</div>
@if(!empty($summary['by_name']))
    <div class="block">
        <h2>Formas cadastradas</h2>
        @foreach($summary['by_name'] as $row)
            <div class="entry">
                <strong>{{ $row['label'] }}</strong>
                <div class="row"><span>Entradas</span><span class="amount">R$ {{ number_format($row['in'], 2, ',', '.') }}</span></div>
                <div class="row"><span>Saídas</span><span class="amount">R$ {{ number_format($row['out'], 2, ',', '.') }}</span></div>
            </div>
        @endforeach
    </div>
@endif
@if($closure)
    <div class="block">
        <p>Fechado em {{ $closure->closed_at->format('d/m/Y H:i') }}</p>
        <p>Responsável: {{ $cashUsers[$closure->user_id] ?? 'Usuário #'.$closure->user_id }}</p>
    </div>
@endif
