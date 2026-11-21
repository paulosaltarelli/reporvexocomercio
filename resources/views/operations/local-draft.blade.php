@if(\Illuminate\Support\Facades\Route::has('supermarket.drafts.import'))
<details class="operation-panel operation-local-draft" data-offline-draft data-draft-scope="{{ $tenant->id }}:{{ $currentUser->id }}:{{ $cart->id }}" data-draft-cart="{{ $cart->id }}" data-draft-version="{{ $cart->version }}" data-draft-url="{{ route('supermarket.drafts.import',['company'=>$tenant->slug,'cart'=>$cart->id]) }}" data-draft-login="{{ route('login',['company'=>$tenant->slug]) }}" hidden>
    <summary>Leituras locais <span class="operation-badge" data-draft-count>0</span></summary>
    <p class="hint">Códigos e quantidades guardados apenas nesta aba. Mantenha-a aberta até confirmar o envio. Preços, estoque e permissões serão conferidos pelo servidor ao enviar. Nenhuma venda ou pagamento é concluído sem conexão.</p>
    <p role="status" data-draft-status></p>
    <a data-draft-login-link href="{{ route('login',['company'=>$tenant->slug]) }}" hidden>Entrar novamente</a>
    <ol class="operation-draft-lines" data-draft-lines></ol>
    <button class="button secondary" type="button" data-draft-online-only hidden>Continuar online sem recuperação local</button>
    <div class="actions"><button class="button" type="button" data-draft-send>Enviar para conferência</button><button class="button secondary" type="button" data-draft-clear>Descartar leituras locais</button></div>
</details>
@once
@push('scripts')<script src="/operation-local-draft.js?v={{ filemtime(public_path('operation-local-draft.js')) }}" defer></script>@endpush
@endonce
@endif
