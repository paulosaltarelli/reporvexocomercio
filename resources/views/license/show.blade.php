@extends('layout')
@section('navigation-section','Minha licença')
@section('title','Parcela de '.$installment->period->format('m/Y'))
@section('subtitle',$tenant->name.' · Contrato #'.$installment->contract_id)
@section('actions')<a class="button secondary" href="{{ route('license.index',['company'=>$tenant->slug]) }}">Voltar à minha licença</a>@endsection
@section('content')
@php
    $open = !in_array($installment->status,['paid','cancelled'],true);
    $chargeStatus = $charge['status'] ?? null;
    $canGenerate = $open && !$graceEnds && $pixAvailable && (!$charge || $chargeStatus === 'expired' || ($chargeStatus === 'pending' && empty($charge['code'])));
@endphp
@component('license.payment-detail', [
    'installment'=>$installment, 'charge'=>$charge, 'canGenerate'=>$canGenerate,
    'showCode'=>$open && !$graceEnds && $chargeStatus === 'pending' && !empty($charge['code']),
    'sandbox'=>($charge['environment'] ?? ($tenant->is_demonstration ? 'sandbox' : 'production')) === 'sandbox',
    'prefix'=>'license', 'restrictionStarts'=>$open ? $restrictionStarts : null,
])
    @if($open && $graceEnds)
        <p class="payment-notice">A empresa está em implantação sem cobrança. O Pix poderá ser gerado a partir de {{ $graceEnds->format('d/m/Y') }}.</p>
    @elseif($open && !$pixAvailable)
        <p class="payment-notice">A emissão de Pix está indisponível para esta empresa. <a href="{{ route('license.help',['company'=>$tenant->slug]) }}">Consultar ajuda da licença</a></p>
    @endif
    @if($canGenerate || ($open && $charge))
        <div class="payment-actions">
            @if($canGenerate)
                <form method="post" action="{{ route('license.installments.issue',['company'=>$tenant->slug,'installment'=>$installment->id]) }}">@csrf<button type="submit" class="button primary">{{ $chargeStatus === 'expired' ? 'Gerar novo Pix' : ($charge ? 'Retomar geração do Pix' : 'Gerar Pix para pagamento') }}</button></form>
            @endif
            @if($open && $charge)
                <form method="post" action="{{ route('license.installments.refresh',['company'=>$tenant->slug,'installment'=>$installment->id]) }}">@csrf<button type="submit" class="button secondary">Consultar pagamento</button></form>
            @endif
        </div>
    @endif
@endcomponent
@endsection
