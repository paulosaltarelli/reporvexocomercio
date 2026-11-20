@extends('auth.password-recovery-layout')
@section('title','Redefinir senha')
@section('content')
<p>Informe o e-mail da sua conta e escolha uma nova senha. O link recebido por e-mail vale por 30 minutos e pode ser usado uma vez.</p>
<form method="post" action="{{ route('password.update',['company'=>$tenant->slug]) }}" data-password-form>
    @csrf
    <div class="field" data-token-field>
        <label for="reset-token">Código de recuperação</label>
        <input id="reset-token" name="token" type="password" required minlength="64" maxlength="64" pattern="[a-fA-F0-9]{64}" autocomplete="off" aria-describedby="reset-token-help">
        <small id="reset-token-help">Reabra o link enviado por e-mail para preencher este campo. Se o link expirou, <a href="{{ route('password.request',['company'=>$tenant->slug]) }}">solicite outro</a>.</small>
    </div>
    <div class="field"><label for="reset-email">E-mail da conta</label>
        <input id="reset-email" name="email" type="email" value="{{ old('email') }}" required maxlength="180" autocomplete="username" autocapitalize="none" spellcheck="false">
    </div>
    <div class="field"><label for="reset-password">Nova senha</label>
        <div class="password-control"><input id="reset-password" name="password" type="password" minlength="12" maxlength="72" required autocomplete="new-password" aria-describedby="reset-help">
            @include('auth.password-toggle',['fieldId'=>'reset-password','fieldLabel'=>'nova senha'])
        </div>
        <small id="reset-help">Use pelo menos 12 caracteres.</small>
    </div>
    <div class="field"><label for="reset-confirm">Confirme a nova senha</label>
        <div class="password-control"><input id="reset-confirm" name="password_confirmation" type="password" minlength="12" maxlength="72" required autocomplete="new-password">
            @include('auth.password-toggle',['fieldId'=>'reset-confirm','fieldLabel'=>'confirmação da nova senha'])
        </div>
    </div>
    <button type="submit" class="primary">Salvar nova senha</button>
</form>
@endsection
@push('scripts')
<script defer src="/password-visibility.js?v={{ filemtime(public_path('password-visibility.js')) }}"></script>
<script defer src="/password-form.js?v={{ filemtime(public_path('password-form.js')) }}"></script>
@endpush
