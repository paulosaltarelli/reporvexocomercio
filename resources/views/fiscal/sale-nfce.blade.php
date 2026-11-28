<section id="nfce" class="form-note no-print" aria-label="Documentos fiscais da venda">
    <h2>Documentos fiscais</h2>
    @php
        $canManageNfce = $currentUser->allows('fiscal.manage');
    @endphp
    @forelse($fiscalDocuments as $document)
        @php
            $isNfce = (int) $document->model === 65;
            $actions = ['company' => $tenant->slug, 'id' => $document->id];
            $track = $isNfce && $canManageNfce;
        @endphp
        <div
            @if($track)
                data-nfce-status="{{ route('fiscal.status', $actions) }}"
                data-nfce-state="{{ $document->state }}"
                data-nfce-auto-open="{{ (int) session('nfce_auto_open') === (int) $document->id ? '1' : '0' }}"
            @endif
        >
            <p aria-live="polite">
                <strong>{{ $isNfce ? 'NFC-e' : 'NF-e' }} {{ $document->number }} · série {{ $document->series }}</strong>
                · <span data-nfce-label>{{ $document->statusLabel() }}</span>
                <span data-nfce-code>{{ $document->official_code ? ' · SEFAZ '.$document->official_code : '' }}</span>
                <span data-nfce-protocol>{{ $document->protocol ? ' · Protocolo '.$document->protocol : '' }}</span>
                <span data-nfce-message>{{ $document->operator_message }}</span>
            </p>
            @if($canManageNfce)
                <div class="actions">
                    @if($track)
                        <a class="button secondary" data-nfce-print href="{{ route('fiscal.thermal', $actions) }}" target="_blank" rel="noopener" @if($document->state !== 'authorized') hidden style="display:none" @endif>Imprimir DANFE térmico</a>
                    @endif
                    <a href="{{ route($isNfce ? 'fiscal.document' : 'nfe.document', $actions) }}">Detalhes fiscais</a>
                </div>
            @endif
        </div>
    @empty
        <p>Nenhuma emissão solicitada.</p>
    @endforelse
    @if($canManageNfce && $sale->status === 'completed' && !$fiscalDocuments->contains(fn ($doc) => (int) $doc->model === 65))
        <details class="disclosure" @if($errors->any() && old('return_to_sale')) open @endif>
            <summary>Emitir NFC-e em homologação</summary>
            <div class="disclosure-content">
                @include('fiscal.nfce-form', ['returnToSale' => true])
            </div>
        </details>
    @endif
    @if($canManageNfce)
        <dialog id="nfce-print-dialog" aria-labelledby="nfce-print-title" style="width:min(680px, 94vw); max-height:92vh">
            <div class="actions">
                <h2 id="nfce-print-title">DANFE térmico</h2>
                <form method="dialog"><button class="button secondary">Voltar à venda</button></form>
            </div>
            <p>Use o botão de impressão do PDF. <a data-nfce-open-pdf target="_blank" rel="noopener">Abrir PDF em outra aba</a></p>
            <iframe title="Prévia do DANFE térmico" style="display:block;width:100%;height:65vh;border:0"></iframe>
        </dialog>
        <script src="/nfce-sale-print.js" defer></script>
    @endif
    <p class="hint">Cancelar a venda e cancelar o documento fiscal são ações separadas.</p>
</section>
