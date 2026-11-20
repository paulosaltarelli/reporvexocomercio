@extends('platform.layout')
@section('title','Integrações bancárias')
@section('content')
@include('platform.settings-navigation')
<p>Os controles abaixo dependem da configuração privada de cada ambiente no servidor. Salvar não envia solicitações ao banco.</p>
@foreach($banks as $bank => $name)
    @foreach($environments as $environment => $label)
        @php($profile = $profiles->get($bank.':'.$environment))
        <section class="panel form-panel">
            <h2>{{ $name }} · {{ $label }}</h2>
            <p>{{ $profile ? 'Configuração salva na Central' : 'Usando configuração do servidor' }}</p>
            @unless($ready[$bank][$environment] ?? false)
                <p role="status">Aguardando habilitação bancária e configuração privada no servidor.</p>
            @endunless
            @if($issuancePaused)<p role="status">A emissão está pausada no servidor.</p>@endif
            <form method="post" action="{{ route('platform.settings.banks.save', [$bank, $environment]) }}">
                @csrf
                @foreach(['enabled' => 'Ambiente habilitado', 'issue' => 'Permitir emissão na loja', 'reconcile' => 'Conciliar automaticamente', 'auto_issue' => 'Emitir automaticamente'] as $field => $caption)
                    @if($field !== 'auto_issue' || $environment === 'production')
                        <input type="hidden" name="{{ $field }}" value="0">
                        <div class="field"><label><input type="checkbox" name="{{ $field }}" value="1" @checked($profile?->{$field} ?? match($field) {
                            'reconcile' => config('licensing_worker.reconcile'),
                            'auto_issue' => config('licensing_worker.issue'),
                            default => $ready[$bank][$environment] ?? false,
                        })> {{ $caption }}</label></div>
                    @else
                        <input type="hidden" name="{{ $field }}" value="0">
                    @endif
                @endforeach
                <button class="button" type="submit">Salvar {{ $label }}</button>
            </form>
        </section>
    @endforeach
@endforeach
@endsection
