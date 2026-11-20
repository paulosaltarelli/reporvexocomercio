@extends('auth.password-recovery-layout')
@section('title','Recuperar senha')
@section('content')
<p>Informe o e-mail que você usa para entrar nesta loja. Se houver uma conta habilitada, você receberá um link para criar uma nova senha.</p>
<form method="post" action="{{ route('password.email',['company'=>$tenant->slug]) }}">
    @csrf
    <div class="field"><label for="recovery-email">E-mail da conta</label>
        <input id="recovery-email" type="email" name="email" value="{{ old('email') }}" required maxlength="180" autocomplete="username" autocapitalize="none" spellcheck="false">
    </div>
    <button type="submit" class="primary">Solicitar link de recuperação</button>
</form>
@endsection
