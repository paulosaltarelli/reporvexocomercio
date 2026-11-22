@php
    $store = $companyProfile?->trade_name ?: ($companyProfile?->legal_name ?: $tenant->name);
    $message = \App\Services\QuoteSharing::message($document, $store);
    $phone = $currentUser->allows('customers.view') ? \App\Services\QuoteSharing::phone($document->customer?->phone) : null;
    // Keep long quotations intact without exceeding common URL limits.
    $fitsLink = strlen(rawurlencode($message)) <= 1800;
@endphp
<details class="panel disclosure no-print" id="quote-sharing">
    <summary>Compartilhar orçamento por WhatsApp</summary>
    <div class="disclosure-content">
        <form method="get" action="https://wa.me/{{ $phone }}" target="_blank" rel="noopener noreferrer">
            <div class="field">
                <label for="quote-message">Mensagem para o cliente</label>
                <textarea id="quote-message" rows="7" readonly @if($fitsLink) name="text" @endif>{{ $message }}</textarea>
                <small class="hint">@if($phone)Conversa com +{{ $phone }}.@else Escolha o contato no WhatsApp.@endif
                    @if($fitsLink)Você poderá revisar e enviar a mensagem na conversa.@else Copie a mensagem completa e cole na conversa para enviar.@endif
                </small>
            </div>
            <div class="actions">
                <button class="button primary" type="submit">Abrir WhatsApp</button>
                <button class="button secondary" type="button" id="copy-quote" hidden>Copiar mensagem</button>
            </div>
            <span class="hint" id="quote-copy-feedback" role="status"></span>
        </form>
    </div>
</details>
@push('scripts')
<script src="/quote-sharing.js?v={{ filemtime(public_path('quote-sharing.js')) }}"></script>
@endpush
