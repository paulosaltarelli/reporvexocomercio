@php
    $status = $installment->status;
    $chargeStatus = $charge['status'] ?? null;
    $money = static fn ($value) => 'R$ '.number_format((float)$value,2,',','.');
    $statusLabels = ['paid'=>'Paga','cancelled'=>'Cancelada','overdue'=>'Em atraso','partial'=>'Pagamento parcial','pending'=>'Em aberto'];
@endphp
<div class="license-payment">
    <section class="payment-summary" aria-label="Situação da parcela">
        <div>
            <span class="payment-label">Saldo a pagar</span>
            <strong class="payment-amount">{{ $money($installment->balance) }}</strong>
            <span class="payment-state" data-tone="{{ match($status){'paid'=>'positive','overdue'=>'warning','cancelled'=>'muted',default=>'info'} }}">{{ $statusLabels[$status] ?? 'Em análise' }}</span>
        </div>
        <dl class="payment-meta">
            <div><dt>Vencimento</dt><dd>{{ $installment->due_date->format('d/m/Y') }}</dd></div>
            <div><dt>Competência</dt><dd>{{ $installment->period->format('m/Y') }}</dd></div>
        </dl>
    </section>
    <div class="payment-grid">
        <section class="payment-card" aria-labelledby="payment-pix-title">
            <div class="payment-card-heading">
                <svg class="payment-icon" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="m3 10 7-7a3 3 0 0 1 4 0l7 7a3 3 0 0 1 0 4l-7 7a3 3 0 0 1-4 0l-7-7a3 3 0 0 1 0-4Z"/><path d="M3 9h4l4 4a1.5 1.5 0 0 0 2 0l4-4h4M3 15h4l4-4a1.5 1.5 0 0 1 2 0l4 4h4"/></svg>
                <h2 id="payment-pix-title">Pagamento por Pix</h2>
            </div>
            @if($sandbox)<p class="payment-notice">Homologação C6 · Cobrança de teste no ambiente de demonstração.</p>@endif
            @if($charge)
                <p>{{ ['pending'=>'Aguardando confirmação do banco.', 'paid'=>'Pagamento confirmado pelo banco.', 'expired'=>'O prazo para pagar esta cobrança terminou.', 'review'=>'A cobrança está em conferência.'][$chargeStatus] ?? 'Aguardando atualização da cobrança.' }}</p>
            @elseif($status === 'paid')
                <p>A parcela está paga.</p>
            @elseif($status === 'cancelled')
                <p>Esta parcela foi cancelada.</p>
            @elseif($canGenerate)
                <p>Gere a cobrança para pagar pelo aplicativo do seu banco.</p>
            @endif
            @if($showCode)
                <div class="payment-instructions">
                    @if(!empty($charge['qr_png']))<img class="payment-qr" src="data:image/png;base64,{{ $charge['qr_png'] }}" width="240" height="240" alt="QR Code Pix para pagar esta parcela">@endif
                    <div class="payment-code">
                        <label for="{{ $prefix }}-pix-code">Pix copia e cola</label>
                        <p>Escaneie o QR Code ou cole o código no aplicativo do banco.</p>
                        <textarea readonly id="{{ $prefix }}-pix-code" rows="3" spellcheck="false">{{ $charge['code'] }}</textarea>
                        <button class="button secondary" type="button" id="{{ $prefix }}-pix-copy" data-copy-pix="{{ $prefix }}-pix-code" aria-describedby="{{ $prefix }}-pix-feedback" hidden>Copiar código Pix</button>
                        <span id="{{ $prefix }}-pix-feedback" role="status"></span>
                    </div>
                </div>
                @if(!empty($charge['expires_at']))<p class="payment-notice">Válida até {{ \Carbon\Carbon::parse($charge['expires_at'],'UTC')->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i') }} (Brasília).</p>@endif
                <script defer src="/license-payment.js?v={{ filemtime(public_path('license-payment.js')) }}"></script>
            @endif
            @if(!empty($charge['end_to_end_id']))<p class="payment-notice">Identificador do recebimento <code>{{ $charge['end_to_end_id'] }}</code></p>@endif
            {{ $slot }}
        </section>
        <section class="payment-card" aria-labelledby="payment-breakdown-title">
            <h2 id="payment-breakdown-title">Resumo da parcela</h2>
            <dl class="payment-breakdown">
                <div><dt>Valor da parcela</dt><dd>{{ $money($installment->amount) }}</dd></div>
                <div><dt>Valor recebido</dt><dd>{{ $money($installment->paid_amount) }}</dd></div>
                <div><dt>Saldo a pagar</dt><dd>{{ $money($installment->balance) }}</dd></div>
            </dl>
            @if($restrictionStarts)
                <div class="payment-notice"><strong>Restrição por atraso</strong><p>A partir de {{ $restrictionStarts->format('d/m/Y') }}, se ainda houver saldo em aberto.</p></div>
            @endif
        </section>
    </div>
</div>
