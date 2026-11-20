@extends('platform.layout')
@section('title','Mensalidade de '.$company->name)
@section('subtitle','Contrato #'.$installment->contract_id.' · Competência '.$installment->period->format('m/Y'))
@section('actions')<a class="button secondary" href="{{ route('platform.license.index', [$company->id]) }}">Voltar à licença</a>@endsection
@section('content')
@php($canGenerate = $canIssue && (!$charge || $charge['status'] === 'expired' || ($charge['status'] === 'pending' && !$charge['code'])))
@component('license.payment-detail', [
    'installment'=>$installment, 'charge'=>$charge, 'canGenerate'=>$canGenerate,
    'showCode'=>!empty($charge['code']), 'sandbox'=>($charge['environment'] ?? ($company->is_demonstration ? 'sandbox' : 'production')) === 'sandbox',
    'prefix'=>'central', 'restrictionStarts'=>null,
])
    @if($canGenerate)
        <div class="payment-actions"><form method="post" action="{{ route('platform.license.installments.issue', [$company->id, $installment->id]) }}">
            @csrf<button class="button primary" type="submit">{{ $charge ? 'Gerar ou retomar cobrança Pix' : 'Gerar cobrança Pix' }}</button>
        </form></div>
    @elseif(!$canIssue && !in_array($installment->status, ['paid','cancelled'], true))
        <p class="payment-notice">A emissão está indisponível para esta parcela.</p>
    @endif
@endcomponent
@endsection
