@extends('layout')
@section('title','Parcela de '.$installment->period->format('m/Y'))
@section('subtitle','Licença Vendra · '.$tenant->name.' · contrato #'.$installment->contract_id)
@section('actions')<a class="button secondary" href="{{ route('license.index',['company'=>$tenant->slug]) }}">Voltar à minha licença</a>@endsection
@section('content')
<section class="panel">
    <h2>Detalhes da parcela</h2>
    @include('license.installment-status',['status'=>$installment->status])
    <dl><dt>Vencimento</dt><dd>{{ $installment->due_date->format('d/m/Y') }}</dd>
        <dt>Valor da parcela</dt><dd>R$ {{ number_format((float)$installment->amount,2,',','.') }}</dd>
        <dt>Valor recebido</dt><dd>R$ {{ number_format((float)$installment->paid_amount,2,',','.') }}</dd>
        <dt>Saldo a pagar</dt><dd><strong>R$ {{ number_format((float)$installment->balance,2,',','.') }}</strong></dd>
    </dl>
</section>
@php($open = !in_array($installment->status,['paid','cancelled'],true))
@php($chargeStatus = $charge['status'] ?? null)
@if($open || $charge)
<section class="panel"><h2>Pagamento por Pix</h2>
    @if(($charge['environment'] ?? ($tenant->is_demonstration ? 'sandbox' : 'production')) === 'sandbox')<p class="hint">Cobrança de teste no ambiente de demonstração do C6.</p>@endif
    @if($charge)
        <p>{{ ['pending'=>'Aguardando confirmação do banco.', 'paid'=>'Pagamento confirmado pelo banco.', 'expired'=>'O prazo para pagar esta cobrança terminou.', 'review'=>'A cobrança está em conferência.'][$chargeStatus] ?? 'Aguardando atualização da cobrança.' }}</p>
        @if($open && $chargeStatus === 'pending' && !empty($charge['code']))
            <p>Leia o QR Code no aplicativo do banco ou use o Pix copia e cola.</p>
            @if(!empty($charge['qr_png']))<img src="data:image/png;base64,{{ $charge['qr_png'] }}" width="240" height="240" alt="QR Code Pix para pagar esta parcela" style="max-width:100%;height:auto">@endif
            <div class="field"><label for="license-pix-code">Pix copia e cola</label><textarea readonly id="license-pix-code" rows="4">{{ $charge['code'] }}</textarea></div>
            <button class="secondary" type="button" id="license-pix-copy" hidden>Copiar código Pix</button><span role="status" id="license-pix-feedback"></span>
            @if(!empty($charge['expires_at']))<p class="hint">Válida até {{ \Carbon\Carbon::parse($charge['expires_at'],'UTC')->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i') }} (Brasília).</p>@endif
        @endif
        @if(!empty($charge['end_to_end_id']))<p>Identificador do recebimento: <code>{{ $charge['end_to_end_id'] }}</code></p>@endif
    @endif
    <div class="actions">
    @if($open && !$pixAvailable)
        <p class="hint">A emissão de Pix está indisponível para esta empresa. <a href="{{ route('license.help',['company'=>$tenant->slug]) }}">Consultar ajuda da licença</a></p>
    @elseif($open && (!$charge || $chargeStatus === 'expired' || ($chargeStatus === 'pending' && empty($charge['code']))))
        <form method="post" action="{{ route('license.installments.issue',['company'=>$tenant->slug,'installment'=>$installment->id]) }}">@csrf<button class="primary">{{ $chargeStatus === 'expired' ? 'Gerar novo Pix' : ($charge ? 'Retomar geração do Pix' : 'Gerar Pix para pagamento') }}</button></form>
    @endif
    @if($open && $charge)
        <form method="post" action="{{ route('license.installments.refresh',['company'=>$tenant->slug,'installment'=>$installment->id]) }}">@csrf<button class="secondary">Consultar pagamento</button></form>
    @endif
    </div>
</section>
@endif
@endsection
@push('scripts')
<script>
(() => {
    const button = document.getElementById('license-pix-copy');
    if (!button || !navigator.clipboard) return;
    button.hidden = false;
    button.addEventListener('click', async () => {
        const code = document.getElementById('license-pix-code');
        const feedback = document.getElementById('license-pix-feedback');
        try {
            await navigator.clipboard.writeText(code.value);
            feedback.textContent = 'Código Pix copiado.';
        } catch {
            code.focus();
            code.select();
            feedback.textContent = 'Código selecionado. Use a opção de copiar do dispositivo.';
        }
    });
})();
</script>
@endpush
