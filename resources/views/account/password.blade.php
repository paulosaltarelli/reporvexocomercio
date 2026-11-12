@extends('layout')
@section('title','Minha senha')
@section('subtitle','Proteja seu acesso. Sua nova senha será usada nas próximas entradas.')
@section('content')
@if($currentUser->must_change_password)<div class="notice">Para continuar, substitua a senha inicial por uma senha só sua.</div>@endif
<form class="panel" method="post" action="{{ route('account.password.save',['company'=>$tenant->slug]) }}" style="max-width:620px">@csrf
<div class="field"><label for="current_password">Senha atual</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required maxlength="128"></div>
<div class="field" style="margin-top:18px"><label for="new_password">Nova senha</label><input id="new_password" name="password" type="password" autocomplete="new-password" required minlength="12" maxlength="128"><small class="hint">Pelo menos 12 caracteres. Use uma senha diferente das suas outras contas.</small></div>
<div class="field" style="margin-top:18px"><label for="password_confirmation">Repita a nova senha</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="12" maxlength="128"></div>
<div class="actions"><button class="primary">Salvar nova senha</button></div></form>
@endsection
