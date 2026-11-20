@extends('platform.layout')
@section('title','Mensalidade de '.$company->name)
@section('actions')
<a class="button secondary" href="{{ route('platform.license.index', [$company->id]) }}">Voltar à licença</a>
@endsection
@section('content')
<section class="panel">
    <h2>Parcela de {{ $installment->period->format('m/Y') }}</h2>
    @include('license.installment-status', ['status' => $installment->status])
    <dl>
        <dt>Vencimento</dt><dd>{{ $installment->due_date->format('d/m/Y') }}</dd>
        <dt>Saldo a pagar</dt><dd>R$ {{ number_format((float) $installment->balance, 2, ',', '.') }}</dd>
    </dl>
</section>
<section class="panel">
    <h2>Pagamento por Pix</h2>
    @if($company->is_demonstration)<p>Homologação C6</p>@endif
    @if($charge)
        <p>{{ ['pending' => 'Aguardando pagamento', 'paid' => 'Pagamento confirmado pelo banco',
            'expired' => 'Cobrança expirada', 'review' => 'Cobrança em conferência'][$charge['status']] }}</p>
        @if($charge['code'])
            <img src="data:image/png;base64,{{ $charge['qr_png'] }}" width="240" height="240" alt="QR Code Pix da mensalidade">
            <div class="field"><label for="central-pix-code">Pix Copia e Cola</label>
                <textarea readonly id="central-pix-code" rows="3">{{ $charge['code'] }}</textarea>
            </div>
            <p>Válido até {{ \Carbon\Carbon::parse($charge['expires_at'])->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i') }} (Brasília).</p>
        @endif
    @endif
    @if($canIssue && (!$charge || $charge['status'] === 'expired' || ($charge['status'] === 'pending' && !$charge['code'])))
        <form method="post" action="{{ route('platform.license.installments.issue', [$company->id, $installment->id]) }}">
            @csrf
            <button class="button" type="submit">{{ $charge ? 'Gerar ou retomar cobrança Pix' : 'Gerar cobrança Pix' }}</button>
        </form>
    @elseif(!$canIssue && !in_array($installment->status, ['paid', 'cancelled'], true))
        <p>A emissão está indisponível para esta parcela.</p>
    @endif
</section>
@endsection
