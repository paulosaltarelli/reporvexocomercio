@push('styles')
    <link rel="stylesheet" href="/supermarket-shift.css?v={{ filemtime(public_path('supermarket-shift.css')) }}">
@endpush
@php
    $money = static function ($amount) {
        [$units, $cents] = explode('.', bcadd((string) $amount, '0', 2));
        return 'R$ '.preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $units).','.$cents;
    };
    $closeReasons = ['lunch' => 'Intervalo para almoço', 'end' => 'Fim do expediente', 'other' => 'Outro motivo'];
@endphp

@if($shift)
    <details class="operation-panel market-shift" id="supermarket-shift-close" @if(old('_shift_action') === 'close') open @endif>
        <summary>
            <span><strong>Caixa {{ $shift->terminal_number }}</strong> · {{ $currentUser->name }}</span>
            <span>Aberto às {{ $shift->opened_at->format('H:i') }}</span>
            <span class="market-shift-action">Conferir e encerrar turno</span>
        </summary>
        <div class="market-shift-body">
            <h2>Fechamento do meu turno</h2>
            <dl class="operation-money-summary">
                <div><dt>Vendas concluídas</dt><dd>{{ $shift->sale_count }}</dd></div>
                <div><dt>Fundo de troco inicial</dt><dd>{{ $money($shift->opening_balance) }}</dd></div>
                @foreach($shift->received_by_method ?? [] as $method => $amount)
                    @if(bccomp((string) $amount, '0', 2) !== 0)
                        <div><dt>Recebido em {{ \App\Services\PaymentMethods::TYPES[$method] ?? $method }}</dt><dd>{{ $money($amount) }}</dd></div>
                    @endif
                @endforeach
                <div><dt>Dinheiro esperado na gaveta</dt><dd>{{ $money($expectedBalance) }}</dd></div>
            </dl>
            @if($carts->contains(fn ($item) => $item->status === 'active' && !empty($item->items)))
                <p class="notice">Conclua ou suspenda a venda em atendimento antes de encerrar o turno.</p>
            @else
                <form method="post" action="{{ route('supermarket.shifts.close', ['company' => $tenant->slug, 'shift' => $shift->id]) }}" class="market-shift-fields">
                    @csrf
                    <input type="hidden" name="_shift_action" value="close">
                    <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                    <div class="field">
                        <label for="shift-counted-balance">Dinheiro contado na gaveta (R$)</label>
                        <input type="number" id="shift-counted-balance" name="counted_balance" min="0" step="0.01" inputmode="decimal" value="{{ old('_shift_action') === 'close' ? old('counted_balance') : '' }}" required>
                    </div>
                    <div class="field">
                        <label for="shift-close-reason">Motivo do encerramento</label>
                        <select id="shift-close-reason" name="reason" required>
                            <option value="">Selecione o motivo</option>
                            @foreach($closeReasons as $value => $label)
                                <option value="{{ $value }}" @selected(old('_shift_action') === 'close' && old('reason') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="button secondary">Encerrar meu turno</button>
                </form>
            @endif
        </div>
    </details>
    @push('scripts')
        <script src="/supermarket-shift.js?v={{ filemtime(public_path('supermarket-shift.js')) }}" defer></script>
    @endpush
@else
    <div class="market-shift-opening">
        <section class="operation-panel" aria-labelledby="shift-open-heading">
            <h2 id="shift-open-heading">Abertura do caixa</h2>
            <p>Operador · <strong>{{ $currentUser->name }}</strong></p>
            <form method="post" action="{{ route('supermarket.shifts.open', ['company' => $tenant->slug]) }}" class="market-shift-fields">
                @csrf
                <input type="hidden" name="_shift_action" value="open">
                <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                <div class="field">
                    <label for="shift-terminal-number">Número do caixa</label>
                    <input id="shift-terminal-number" type="number" name="terminal_number" min="1" max="999" step="1" inputmode="numeric" placeholder="1 a 999" value="{{ old('terminal_number') }}" required @if(!$errors->any()) autofocus @endif>
                </div>
                <div class="field">
                    <label for="shift-opening-balance">Fundo de troco inicial (R$)</label>
                    <input id="shift-opening-balance" type="number" name="opening_balance" min="0" step="0.01" inputmode="decimal" placeholder="0,00" value="{{ old('opening_balance') }}" required>
                </div>
                <button type="submit" class="button">Abrir caixa</button>
            </form>
        </section>
        @if($lastShift)
            <section class="operation-panel market-last-shift" aria-labelledby="last-shift-heading">
                <h2 id="last-shift-heading">Meu último fechamento</h2>
                <p>Caixa {{ $lastShift->terminal_number }} · {{ $lastShift->closed_at->format('d/m/Y H:i') }} · {{ $closeReasons[$lastShift->closing_reason] ?? '' }}</p>
                <dl class="operation-money-summary">
                    <div><dt>Dinheiro esperado</dt><dd>{{ $money($lastShift->expected_balance) }}</dd></div>
                    <div><dt>Dinheiro contado</dt><dd>{{ $money($lastShift->counted_balance) }}</dd></div>
                    <div><dt>Diferença</dt><dd>{{ $money($lastShift->difference) }}</dd></div>
                </dl>
            </section>
        @endif
    </div>
@endif
