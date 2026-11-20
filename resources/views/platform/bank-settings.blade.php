@extends('platform.layout')
@section('title','Integrações bancárias')
@section('content')
@include('platform.settings-navigation')
<p>Os controles abaixo dependem da configuração privada de cada ambiente no servidor. Salvar não envia solicitações ao banco.</p>
@if($issuancePaused)<p class="notice" role="status">A emissão está pausada no servidor.</p>@endif
@foreach($banks as $bank => $name)
    <div class="bank-environments">
    @foreach($environments as $environment => $label)
        @php
            $profile = $profiles->get($bank.':'.$environment);
            $editingBank = old('bank_form') === $bank.'.'.$environment;
        @endphp
        <section class="panel form-panel central-bank">
            <h2>{{ $name }} · {{ $label }}</h2>
            <p class="central-source">{{ $profile ? 'Configuração salva na Central' : 'Usando configuração do servidor' }}</p>
            @unless($ready[$bank][$environment] ?? false)
                <p role="status">Aguardando habilitação bancária e configuração privada no servidor.</p>
            @endunless
            <form method="post" action="{{ route('platform.settings.banks.save', [$bank, $environment]) }}">
                @csrf
                @php
                    $connection = $connections[$bank][$environment] ?? [];
                    if ($editingBank) {
                        foreach (['api_url', 'token_url', 'scope', 'auth_method', 'credential_set'] as $field) {
                            $value = old($field);
                            if (in_array($field, ['api_url', 'token_url'], true) && is_string($value) && !\App\Platform\Billing\C6Bank::validEndpoint($value, $environment)) continue;
                            if (is_string($value) && !$errors->has($field)) $connection[$field] = $value;
                            if ($value === null) unset($connection[$field]);
                        }
                    }
                @endphp
                <details class="central-bank-connection" @if($editingBank) open @endif>
                    <summary>Conexão e credenciais</summary>
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
                </details>
                @foreach(['enabled' => 'Ambiente habilitado', 'issue' => 'Permitir que a loja gere Pix para pagar a licença', 'reconcile' => 'Conciliar pagamentos de licença automaticamente', 'auto_issue' => 'Gerar Pix automaticamente para parcelas de licença'] as $field => $caption)
                    @if($field !== 'auto_issue' || $environment === 'production')
                        <input type="hidden" name="{{ $field }}" value="0">
                        <div class="field bank-toggle"><label><input type="checkbox" name="{{ $field }}" value="1" @checked($editingBank ? old($field) === '1' : ($profile?->{$field} ?? match($field) {
                            'reconcile' => config('licensing_worker.reconcile'),
                            'auto_issue' => config('licensing_worker.issue'),
                            default => $ready[$bank][$environment] ?? false,
                        }))> {{ $caption }}</label></div>
                    @else
                        <input type="hidden" name="{{ $field }}" value="0">
                    @endif
                @endforeach
                <button class="button" type="submit">Salvar {{ $label }}</button>
            </form>
        </section>
    @endforeach
    </div>
@endforeach
@endsection
