<div class="os-print-facts" hidden>
    <p class="os-print-dates">
        @if($document->created_at)<span>Abertura <strong>{{ $document->created_at->format('d/m/Y') }}</strong></span>@endif
        @if($document->valid_until)<span>Previsão de entrega <strong>{{ \Carbon\Carbon::parse($document->valid_until)->format('d/m/Y') }}</strong></span>@endif
    </p>
    <dl>
        <div><dt>Cliente</dt><dd>{{ $document->customer?->name ?: 'Não informado' }}</dd></div>
        @if(filled($document->customer?->phone))<div><dt>Telefone do cliente</dt><dd>{{ $document->customer->phone }}</dd></div>@endif
        <div><dt>Situação</dt><dd>{{ ['draft'=>'Aberta','approved'=>'Aberta','in_progress'=>'Em serviço','completed'=>'Finalizada','invoiced'=>'Venda gerada','cancelled'=>'Cancelada'][$document->status] }}</dd></div>
        @if($document->asset)<div><dt>Equipamento</dt><dd>{{ $document->asset }}</dd></div>@endif
        @if($document->technician)<div><dt>Técnico</dt><dd>{{ $document->technician }}</dd></div>@endif
        @if($document->seller_name && $document->seller_name !== $document->technician)<div><dt>Responsável pelo atendimento</dt><dd>{{ $document->seller_name }}</dd></div>@endif
    </dl>
    @if(filled($document->notes))
        <h3>Relato e diagnóstico</h3>
        <p class="os-print-notes">{{ $document->notes }}</p>
    @endif
</div>
