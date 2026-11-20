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
                @php($connection = $connections[$bank][$environment] ?? [])
                <div class="form-grid">
                    @foreach(['api_url' => 'URL base da API Pix', 'token_url' => 'URL de autenticação', 'scope' => 'Escopos OAuth'] as $field => $title)
                        <div class="field">
                            <label for="{{ $bank }}-{{ $environment }}-{{ $field }}">{{ $title }}</label>
                            <input id="{{ $bank }}-{{ $environment }}-{{ $field }}" name="{{ $field }}"
                                type="{{ $field === 'scope' ? 'text' : 'url' }}" value="{{ $connection[$field] ?? '' }}"
                                maxlength="{{ $field === 'scope' ? 255 : 512 }}" placeholder="Usar configuração do servidor">
                        </div>
                    @endforeach
                    <div class="field">
                        <label for="{{ $bank }}-{{ $environment }}-auth">Autenticação OAuth</label>
                        <select id="{{ $bank }}-{{ $environment }}-auth" name="auth_method">
                            <option value="">Usar configuração do servidor</option>
                            <option value="client_secret_basic" @selected(($connection['auth_method'] ?? '') === 'client_secret_basic')>HTTP Basic</option>
                            <option value="client_secret_post" @selected(($connection['auth_method'] ?? '') === 'client_secret_post')>Credenciais no corpo</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="{{ $bank }}-{{ $environment }}-credentials">Vínculo de credenciais e certificado</label>
                        <select id="{{ $bank }}-{{ $environment }}-credentials" name="credential_set">
                            <option value="">Usar credenciais padrão do servidor</option>
                            @if(isset($connection['credential_set']) && !in_array($connection['credential_set'], $credentialNames[$bank][$environment] ?? [], true))
                                <option value="{{ $connection['credential_set'] }}" selected>Vínculo indisponível</option>
                            @endif
                            @foreach($credentialNames[$bank][$environment] ?? [] as $credentialName)
                                <option value="{{ $credentialName }}" @selected(($connection['credential_set'] ?? '') === $credentialName)>{{ $credentialName }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
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
