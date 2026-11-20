
@extends('layout')
@section('title',($document->type==='quote'?'Orçamento #':'Ordem de serviço #').$document->id)
@section('subtitle',$document->type==='work_order'?'Acompanhe o atendimento, inicie o serviço e finalize quando estiver pronto.':'Documento interno. Aprovação registrada manualmente pela equipe.')
@section('actions')<button type="button" class="button secondary no-print" onclick="window.print()">{{ $document->type==='work_order'?'Imprimir OS':'Imprimir' }}</button>
@endsection
@section('content')
@php
    $documentSalesperson = $document->salesperson;
    $hasActiveSalesperson = $document->salesperson_id && $documentSalesperson && $documentSalesperson->active;
    $canAssignSeller = $currentUser->allows('documents.seller') && ! in_array($document->status, ['invoiced','cancelled'], true);
    $sections = $sections ?? collect(['all' => collect($document->items)]);
    $documentGross = collect($document->items)->reduce(fn ($total, $item) => bcadd($total, (string) ($item['gross_total'] ?? $item['total']), 2), '0.00');
    $documentDiscount = collect($document->items)->reduce(fn ($total, $item) => bcadd($total, (string) ($item['discount_amount'] ?? '0'), 2), '0.00');
@endphp
@if($document->type==='work_order')
<section class="panel no-print"><h2>Etapas da ordem de serviço</h2><p>Aberta → Em serviço → Finalizada → Recebimento no caixa.</p><p class="hint">Salvar ou concluir a OS não reserva nem baixa estoque. O movimento de estoque e os títulos financeiros são gerados ao confirmar a venda.</p>
@if(in_array($document->status,['draft','approved','in_progress'],true) && $currentUser->allows('documents.edit'))<a class="button secondary" href="{{ route('document.edit',['company'=>$tenant->slug,'id'=>$document->id]) }}">Editar atendimento e itens da OS</a>@endif
@if(empty($document->items))<div class="notice">@if($document->status==='completed')Serviço finalizado sem itens para cobrança.@else Você pode iniciar e finalizar o serviço sem itens. Para cobrar, registre os produtos e serviços antes de finalizar.@endif</div>@endif
</section>
@endif
<section class="panel work-order-copy">
@if($document->type === 'work_order')
    @include('documents.work-order-print-info')
@endif
<div class="document-screen-facts"><h2 class="no-print">{{ $tenant->name }}</h2><p><strong>Cliente:</strong> {{ $document->customer?->name??'Não informado' }}</p><p><strong>Responsável pelo atendimento:</strong> {{ $document->seller_name??'Não informado' }}<span class="no-print"> · <strong>Registrado por:</strong> {{ $document->creator?->name??'Não informado' }}</span></p><p><strong>Etapa:</strong> {{ $document->type==='work_order'?['draft'=>'Aberta','approved'=>'Aberta','in_progress'=>'Em serviço','completed'=>'Finalizada','invoiced'=>'Venda gerada','cancelled'=>'Cancelada'][$document->status]:['draft'=>'Aberto','approved'=>'Aprovado','in_progress'=>'Em execução','completed'=>'Concluído','invoiced'=>'Venda gerada','cancelled'=>'Cancelado'][$document->status] }}</p><p><strong>Equipamento:</strong> {{ $document->asset??'—' }} · <strong>Técnico:</strong> {{ $document->technician??'—' }}</p><p><strong>{{ $document->type==='work_order'?'Previsão de entrega':'Validade' }}:</strong> {{ $document->valid_until??'—' }}</p><p style="white-space:pre-wrap">{{ $document->notes }}</p></div>
@foreach(['product' => 'Produtos', 'service' => 'Serviços', 'all' => 'Itens'] as $type => $label)
    @if(($section = $sections->get($type)) && $section->isNotEmpty())
        @php($hasSectionValues = $document->type !== 'work_order' || $section->contains(fn ($item) => bccomp((string)($item['gross_total'] ?? $item['total']), '0', 2) !== 0))
        @php($hasSectionDiscount = $section->contains(fn ($item) => bccomp((string)($item['discount_amount'] ?? '0'), '0', 2) !== 0))
        <h3>{{ $label }}</h3>
<div class="scroll"><table><thead><tr><th>Item</th><th class="num">Quantidade</th>@if($hasSectionValues)<th class="num">Preço unitário (R$)</th>@if($hasSectionDiscount)<th class="num">Bruto (R$)</th><th class="num">Desconto (R$)</th>@endif<th class="num">Total (R$)</th>@endif</tr></thead><tbody>
@foreach($section as $item)
@php($lineGross=(string)($item['gross_total']??$item['total']))
@php($lineDiscount=(string)($item['discount_amount']??'0.00'))
<tr><td class="product-name">{{ $item['description'] }}</td><td class="num">{{ number_format($item['quantity'],3,',','.') }}</td>@if($hasSectionValues)<td class="num" @if(bccomp((string)$item['unit_price'],'0',2) === 0) data-zero-value @endif><span>{{ number_format($item['unit_price'],2,',','.') }}</span></td>@if($hasSectionDiscount)<td class="num" @if(bccomp($lineGross,'0',2) === 0) data-zero-value @endif><span>{{ number_format($lineGross,2,',','.') }}</span></td><td class="num" @if(bccomp($lineDiscount,'0',2) === 0) data-zero-value @endif><span>{{ number_format($lineDiscount,2,',','.') }}</span></td>@endif<td class="num" @if(bccomp((string)$item['total'],'0',2) === 0) data-zero-value @endif><strong>{{ number_format($item['total'],2,',','.') }}</strong></td>@endif</tr>
@endforeach
</tbody></table></div>        @if($type !== 'all' && $hasSectionValues)
            @php($sectionTotal = $section->reduce(fn ($total, $item) => bcadd($total, (string) $item['total'], 2), '0.00'))
            <p @class(['no-print' => $document->type === 'work_order' && bccomp($sectionTotal,'0',2) === 0])><strong>Subtotal de {{ mb_strtolower($label) }}: R$ {{ number_format($sectionTotal,2,',','.') }}</strong></p>
        @endif
    @endif
@endforeach
<div class="document-settlement">
@if($document->type !== 'work_order' || bccomp($documentGross, '0', 2) !== 0 || bccomp((string)$document->total, '0', 2) !== 0)
<div class="receipt-totals">
    @if(bccomp($documentDiscount, '0', 2) !== 0)
        <span>Subtotal bruto</span><strong>R$ {{ number_format($documentGross,2,',','.') }}</strong>
        <span>Descontos</span><strong>R$ {{ number_format($documentDiscount,2,',','.') }}</strong>
    @endif
    <span class="receipt-net">Total</span><strong class="receipt-net" @if($document->type === 'work_order' && bccomp((string)$document->total,'0',2) === 0) data-no-charge @endif><span>R$ {{ number_format($document->total,2,',','.') }}</span></strong>
</div>
@endif
@if($document->type === 'work_order')
<div class="work-order-signatures">
    <div><div class="signature-line"></div><strong>Assinatura do cliente</strong><span>{{ $document->customer?->name }}</span></div>
    <div><div class="signature-line"></div><strong>Técnico ou vendedor responsável</strong><span>{{ $document->technician ?: $document->seller_name }}</span></div>
</div>
@endif
</div>

@if(!in_array($document->status,['invoiced','cancelled'],true) && !$hasActiveSalesperson)
    <div class="notice no-print" role="status">@if($currentUser->allows('documents.seller'))O documento precisa de um vendedor ativo antes de gerar a venda. <a href="#document-seller-assignment" data-open-disclosure="document-seller-assignment">Vincular ou alterar o vendedor</a>.@else
Peça ao administrador para vincular um vendedor ativo antes de gerar a venda. O usuário que executa a operação não assume o crédito automaticamente.@endif</div>
@endif
@if($canAssignSeller)
    <details class="disclosure compact no-print" id="document-seller-assignment" style="margin-top:24px" @if(!$hasActiveSalesperson || $errors->hasAny(['salesperson_id','reason'])) open @endif>
        <summary>{{ $document->salesperson_id?'Alterar vendedor creditado':'Vincular vendedor ao documento' }}</summary>
        <form class="disclosure-content" method="post" action="{{ route('document.seller',['company'=>$tenant->slug,'id'=>$document->id]) }}">
            @csrf
            <p class="hint">Responsável registrado: {{ $document->seller_name??'Não informado' }}. A troca exige motivo e fica no histórico. Depois de gerar a venda, o vínculo não pode ser alterado por esta tela.</p>
            <div class="form-grid">
                <div class="field"><label for="document-salesperson">Vendedor ativo</label><select id="document-salesperson" name="salesperson_id" required><option value="">Selecione o vendedor</option>@foreach($sellers as $seller)<option value="{{ $seller->id }}" @selected((string)old('salesperson_id')===(string)$seller->id)>{{ $seller->name }}</option>@endforeach</select></div>
                <div class="field full"><label for="document-seller-reason">Motivo da vinculação ou alteração</label><textarea id="document-seller-reason" name="reason" minlength="3" maxlength="1000" required>{{ old('reason') }}</textarea></div>
            </div>
            <div class="actions"><button class="primary">{{ $document->salesperson_id?'Confirmar alteração do vendedor':'Vincular vendedor' }}</button></div>
        </form>
    </details>
@endif
@if($currentUser->allows('documents.seller'))
    <details class="disclosure compact no-print" style="margin-top:20px"><summary>Histórico de atribuição do vendedor</summary><div class="disclosure-content scroll" role="region" aria-label="Histórico do vendedor creditado" tabindex="0"><table><thead><tr><th>Quando</th><th>Vendedor anterior</th><th>Vendedor atribuído</th><th>Quem registrou</th><th>Motivo</th></tr></thead><tbody>
    @forelse($assignments as $assignment)<tr><td>{{ $assignment->created_at->format('d/m/Y H:i') }}</td><td class="product-name">{{ $assignment->previous_seller_name??'Sem vendedor' }}</td><td class="product-name">{{ $assignment->new_seller_name??'Não informado' }}</td><td class="product-name">{{ $assignment->actor_name??'Não informado' }}</td><td class="seller-audit-reason">{{ $assignment->reason?:'—' }}</td></tr>@empty<tr><td colspan="5" class="empty">Nenhuma atribuição registrada no histórico disponível.</td></tr>@endforelse
    </tbody></table></div></details>
@endif
@if($currentUser->allows('documents.status') && !in_array($document->status,['invoiced','cancelled']))<div class="actions no-print">
@foreach(match($document->status){'draft'=>$document->type==='work_order'?['in_progress'=>'Iniciar serviço']:['approved'=>'Registrar aprovação'],'approved'=>$document->type==='work_order'?['in_progress'=>'Iniciar serviço']:[],'in_progress'=>['completed'=>'Finalizar serviço'],default=>[]} as $v=>$label)<form method="post" action="{{ route('document.status',['company'=>$tenant->slug,'id'=>$document->id]) }}">
@csrf<input type="hidden" name="status" value="{{ $v }}"><button class="primary" @disabled($document->type==='work_order' && $v==='approved' && empty($document->items))>{{ $label }}</button></form>
@endforeach<form method="post" action="{{ route('document.status',['company'=>$tenant->slug,'id'=>$document->id]) }}" onsubmit="return confirm('Cancelar este documento?');">
@csrf<input type="hidden" name="status" value="cancelled"><button class="button secondary">Cancelar documento</button></form></div>
@endif
@if($currentUser->allows('documents.invoice') && !empty($document->items) && $hasActiveSalesperson && (($document->type==='quote'&&$document->status==='approved')||($document->type==='work_order'&&$document->status==='completed')))
@include('documents.checkout')
@endif
@if($document->sale_id && $currentUser->allows('sales.view'))<p class="no-print"><a href="{{ route('sale.show',['company'=>$tenant->slug,'id'=>$document->sale_id]) }}">Abrir venda #{{ $document->sale_id }}</a></p>
@endif</section>
@endsection

@if($document->type==='work_order' && request()->boolean('imprimir'))
@push('scripts')
<script>window.addEventListener('load', () => window.print(), {once: true});</script>
@endpush
@endif
