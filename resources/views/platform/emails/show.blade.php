@extends('platform.layout')
@section('title','Detalhes do envio')
@section('actions')<a class="button secondary" href="{{ route('platform.settings.emails.index',request()->only(['recipient','status','page'])) }}">Voltar aos envios</a>@endsection
@section('content')
@include('platform.settings-navigation')
<section class="panel">
    <h2>{{ $attempt->subject }}</h2>
    <dl class="detail-grid">
        <div><dt>Destinatário</dt><dd>{{ $attempt->recipient }}</dd></div>
        <div><dt>Empresa</dt><dd>{{ $attempt->company_name }}</dd></div>
        <div><dt>Tipo</dt><dd>{{ $type }}</dd></div>
        <div><dt>Situação</dt><dd>{{ $status }}</dd></div>
        <div><dt>Início do envio</dt><dd>{{ $attempt->created_at->format('d/m/Y H:i:s') }}</dd></div>
        @if($attempt->finished_at)
            <div><dt>Conclusão da tentativa</dt><dd>{{ $attempt->finished_at->format('d/m/Y H:i:s') }}</dd></div>
        @endif
    </dl>
</section>
<section class="panel">
    <h2>Prévia do e-mail</h2>
    @if($preview !== null)
        <p>Esta prévia usa o modelo atual. Links de acesso e códigos estão ocultos.</p>
        <iframe title="Prévia do e-mail" sandbox referrerpolicy="no-referrer" style="width:100%;height:42rem;border:0" srcdoc="{{ $preview }}"></iframe>
    @else
        <p>Prévia indisponível para este tipo de e-mail.</p>
    @endif
</section>
@endsection
