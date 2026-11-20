@extends('layout')
@section('title','Pix Sicoob')
@section('content')
<section class="panel">
    <h2>Cobrança do título #{{ $id }}</h2>
    <p>{{ $charge['status'] }} · R$ {{ number_format($charge['amount'], 2, ',', '.') }}</p>
    <p>Identificador da cobrança (txid) <code>{{ $charge['txid'] }}</code></p>
    @if($image)
        <img alt="QR Code Pix desta cobrança" src="data:image/png;base64,{{ $image }}" style="max-width:100%;height:auto">
        <div class="field"><label for="pix-code">Pix copia e cola</label><textarea id="pix-code" rows="4" readonly>{{ $charge['code'] }}</textarea></div>
        <p>Válida até {{ $charge['expires']->format('d/m/Y H:i T') }}.</p>
    @endif
    @isset($charge['endToEndId'])
        <p>Identificador do recebimento {{ $charge['endToEndId'] }} · Data financeira {{ \Carbon\Carbon::parse($charge['paid_on'])->format('d/m/Y') }}</p>
    @endisset
    <form method="post" action="{{ route('finance.pix', ['company'=>$tenant->slug,'id'=>$id,'operation'=>'consultar']) }}">
        @csrf <button class="primary">Consultar e conciliar recebimento</button>
        <a href="{{ route('finance', ['company'=>$tenant->slug]) }}">Voltar ao financeiro</a>
    </form>
</section>
@endsection
