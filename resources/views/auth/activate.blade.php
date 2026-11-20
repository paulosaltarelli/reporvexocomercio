<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ativar conta · Vendra</title><link rel="stylesheet" href="/app.css"><link rel="stylesheet" href="/brand.css"></head>
<body><main class="login-form"><h1>Ativar conta de administrador</h1><p>{{ $tenant->name }}</p>
@if($errors->any())<div role="alert" class="notice error">{{ $errors->first() }}</div>@endif
<form method="post">@csrf
<div class="field"><label for="email">E-mail que recebeu o convite</label><input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username"></div>
<div class="field"><label for="code">Código de ativação</label><input id="code" name="activation_code" maxlength="32" required autocomplete="one-time-code" spellcheck="false"></div>
<div class="field"><label for="pass">Nova senha (mínimo de 12 caracteres)</label><input id="pass" name="password" type="password" required minlength="12" maxlength="72" autocomplete="new-password"></div>
<div class="field"><label for="confirm">Confirme a senha</label><input id="confirm" name="password_confirmation" type="password" required autocomplete="new-password"></div>
<button class="primary">Ativar conta</button></form>
<p><a href="{{ route('login',['company'=>$tenant->slug]) }}">Voltar ao login</a></p></main></body></html>
