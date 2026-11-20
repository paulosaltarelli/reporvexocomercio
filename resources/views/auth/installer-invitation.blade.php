<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Aceitar convite de implantação · Vendra</title>
    <link rel="stylesheet" href="/app.css"><link rel="stylesheet" href="/brand.css"><link rel="stylesheet" href="/company-setup.css?v={{ filemtime(public_path('company-setup.css')) }}">
</head>
<body>
<main class="panel installer-invitation">
    <img src="/brand/vendra-logo.svg" width="122" height="44" alt="Vendra">
    <h1>Aceitar convite de implantação</h1>
    <p>Crie sua senha para configurar a loja <strong>{{ $tenant->name }}</strong>.</p>
    @if($errors->any())<div class="notice error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form action="{{ route('installer.invite.accept',['company'=>$tenant->slug]) }}" method="post">
        @csrf
        <div class="field"><label for="invitation-email">E-mail que recebeu o convite</label><input id="invitation-email" type="email" name="email" value="{{ old('email') }}" required maxlength="180" autocomplete="username" autocapitalize="none" spellcheck="false"></div>
        <div class="field" id="invitation-code-field">
            <label for="invitation-code">Código do convite</label>
            <input id="invitation-code" type="password" name="token" required minlength="64" maxlength="64" pattern="[a-fA-F0-9]{64}" autocomplete="off" aria-describedby="invitation-code-help">
            <small class="hint" id="invitation-code-help">Abra o link recebido por e-mail para preencher o código ou cole os 64 caracteres do convite.</small>
        </div>
        <div class="field"><label for="invitation-password">Crie sua senha</label><input id="invitation-password" type="password" name="password" required minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="invitation-password-help"><small class="hint" id="invitation-password-help">Use pelo menos 12 caracteres.</small></div>
        <div class="field"><label for="invitation-confirm">Confirme sua senha</label><input id="invitation-confirm" type="password" name="password_confirmation" required minlength="12" maxlength="72" autocomplete="new-password"></div>
        <button class="primary">Aceitar convite e iniciar implantação</button>
    </form>
    <p><a href="{{ route('login',['company'=>$tenant->slug]) }}">Já aceitei o convite. Entrar na loja</a></p>
</main>
<script>
(() => {
    const password = document.getElementById('invitation-password');
    const confirmation = document.getElementById('invitation-confirm');
    const checkPasswords = () => {
        password.setCustomValidity(new TextEncoder().encode(password.value).length > 72 ? 'Use uma senha de até 72 bytes.' : '');
        confirmation.setCustomValidity(confirmation.value && confirmation.value !== password.value ? 'Digite a mesma senha nos dois campos.' : '');
    };
    password.addEventListener('input', checkPasswords);
    confirmation.addEventListener('input', checkPasswords);
    const token = new URLSearchParams(window.location.hash.slice(1)).get('token');
    if (token === null) return;
    history.replaceState(null, '', window.location.pathname + window.location.search);
    if (/^[a-f0-9]{64}$/i.test(token)) {
        document.getElementById('invitation-code').value = token;
        document.getElementById('invitation-code-field').hidden = true;
    }
})();
</script>
</body>
</html>
