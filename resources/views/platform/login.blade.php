@extends('platform.layout')
@section('title','Acesso à Central Vexo')
@section('subtitle','Administração da plataforma e das empresas atendidas.')
@section('content')
<section class="panel login-panel"><h2>Entrar na central</h2><p class="muted">Use sua conta administrativa da Vexo. As contas dos clientes pertencem ao acesso do ERP.</p><form method="post" action="{{ route('platform.login') }}">@csrf<div class="field"><label for="central-email">E-mail administrativo</label><input id="central-email" name="email" type="email" value="{{ old('email') }}" maxlength="180" autocomplete="username" required autofocus></div><div class="field"><label for="central-password">Senha</label><input id="central-password" name="password" type="password" autocomplete="current-password" required></div><button class="button">Entrar na Central Vexo</button></form></section>
@endsection
