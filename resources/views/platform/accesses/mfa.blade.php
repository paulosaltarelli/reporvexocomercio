@if(\App\Platform\PlatformMfa::needsChallenge((int)session('platform_mfa_at')))
    <label for="{{ $fieldId }}">Código do autenticador ou de recuperação</label>
    <input id="{{ $fieldId }}" type="password" name="mfa_code" maxlength="32" required autocomplete="one-time-code">
@endif
