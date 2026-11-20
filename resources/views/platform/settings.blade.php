@extends('platform.layout')
@section('title','Configurações')
@section('content')
@include('platform.settings-navigation')
<section class="panel form-panel">
    <h2>E-mail da Central</h2>
    @if($smtp===null)
        <p role="alert">A configuração privada de SMTP está indisponível. Verifique as permissões do arquivo e a chave da aplicação no servidor.</p>
    @else
        <p>Este servidor SMTP envia os convites de implantação e de ativação das lojas.</p>
        <p><strong>Configuração em uso</strong> {{ $smtp['saved']?'Salva na Central':'Padrão do ambiente' }}</p>
        <form method="post" action="{{ route('platform.settings.smtp.save') }}">
            @csrf
            <input type="hidden" name="version" value="{{ $smtp['version'] }}">
            <div class="form-grid">
                <div class="field"><label for="smtp-host">Servidor SMTP</label><input id="smtp-host" name="host" maxlength="253" required value="{{ old('host',$smtp['host']) }}"></div>
                <div class="field"><label for="smtp-port">Porta</label><input id="smtp-port" type="number" name="port" min="1" max="65535" required value="{{ old('port',$smtp['port']) }}"></div>
                <div class="field"><label for="smtp-security">Segurança da conexão</label><select id="smtp-security" name="security">
                    <option value="tls" @selected(old('security',$smtp['security'])==='tls')>TLS implícito (normalmente porta 465)</option>
                    <option value="starttls" @selected(old('security',$smtp['security'])==='starttls')>STARTTLS obrigatório (normalmente porta 587)</option>
                </select></div>
                <div class="field"><label for="smtp-user">Usuário SMTP</label><input id="smtp-user" name="username" maxlength="255" required autocomplete="off" value="{{ old('username',$smtp['username']) }}"></div>
                <div class="field"><label for="smtp-password">Senha SMTP</label><input id="smtp-password" type="password" name="password" maxlength="4096" autocomplete="new-password" @required(!$smtp['hasPassword']) aria-describedby="smtp-password-help">
                    <small id="smtp-password-help">{{ $smtp['hasPassword']?'Para manter a senha salva, deixe em branco. Se mudar servidor, porta, segurança ou usuário, informe a senha novamente.':'Informe a senha da conta de envio.' }}</small>
                </div>
                <div class="field"><label for="smtp-from-name">Nome do remetente</label><input id="smtp-from-name" name="from_name" maxlength="180" required value="{{ old('from_name',$smtp['from_name']) }}"></div>
                <div class="field"><label for="smtp-from">E-mail do remetente</label><input id="smtp-from" type="email" name="from_address" maxlength="254" required value="{{ old('from_address',$smtp['from_address']) }}"></div>
            </div>
            <p>O teste verifica a conexão TLS e a autenticação sem enviar e-mail. Salvar também testa os dados antes de aplicá-los.</p>
            <div class="actions">
                <button class="button" type="submit">Salvar configuração</button>
                <button class="button secondary" type="submit" formaction="{{ route('platform.settings.smtp.test') }}">Testar conexão</button>
            </div>
        </form>
    @endif
</section>
@endsection
