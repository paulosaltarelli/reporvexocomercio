
@extends('layout')
@section('title',($document->type==='quote'?'Orçamento #':'Ordem de serviço #').$document->id)
@section('subtitle','Documento interno. Aprovações registradas manualmente pela equipe; não há assinatura digital nesta versão.')
@section('actions')<button class="button secondary no-print" onclick="window.print()">Imprimir</button>
@endsection
@section('content')
@php($documentSalesperson=$document->salesperson)
@php($hasActiveSalesperson=$document->salesperson_id && $documentSalesperson && $documentSalesperson->active)
@php($canAssignSeller=$currentUser->role==='admin' && !in_array($document->status,['invoiced','cancelled'],true))
<section class="panel"><h2>{{ $tenant->name }}</h2><p><strong>Cliente:</strong> {{ $document->customer?->name??'Não informado' }}</p><p><strong>Vendedor creditado:</strong> {{ $document->seller_name??'Não informado' }} · <strong>Registrado por:</strong> {{ $document->creator?->name??'Não informado' }}</p><p><strong>Etapa:</strong> {{ ['draft'=>'Aberto','approved'=>'Aprovado','in_progress'=>'Em execução','completed'=>'Concluído','invoiced'=>'Venda gerada','cancelled'=>'Cancelado'][$document->status] }}</p><p><strong>Bem atendido:</strong> {{ $document->asset??'—' }} · <strong>Responsável:</strong> {{ $document->technician??'—' }}</p><p><strong>Validade / previsão:</strong> {{ $document->valid_until??'—' }}</p><p style="white-space:pre-wrap">{{ $document->notes }}</p><div class="scroll"><table><thead><tr><th>Item</th><th class="num">Quantidade</th><th class="num">Preço bruto unitário (R$)</th><th class="num">Bruto (R$)</th><th class="num">Desconto (R$)</th><th class="num">Líquido (R$)</th></tr></thead><tbody>
@php($documentGross='0.00')
@php($documentDiscount='0.00')
@foreach($document->items as $item)
@php($lineGross=(string)($item['gross_total']??$item['total']))
@php($lineDiscount=(string)($item['discount_amount']??'0.00'))
@php($documentGross=bcadd($documentGross,$lineGross,2))
@php($documentDiscount=bcadd($documentDiscount,$lineDiscount,2))
<tr><td class="product-name">{{ $item['description'] }}</td><td class="num">{{ number_format($item['quantity'],3,',','.') }}</td><td class="num">{{ number_format($item['unit_price'],2,',','.') }}</td><td class="num">{{ number_format($lineGross,2,',','.') }}</td><td class="num">{{ number_format($lineDiscount,2,',','.') }}</td><td class="num"><strong>{{ number_format($item['total'],2,',','.') }}</strong></td></tr>
@endforeach
</tbody></table></div><div class="receipt-totals"><span>Subtotal bruto</span><strong>R$ {{ number_format($documentGross,2,',','.') }}</strong><span>Descontos</span><strong>R$ {{ number_format($documentDiscount,2,',','.') }}</strong><span class="receipt-net">Total líquido</span><strong class="receipt-net">R$ {{ number_format($document->total,2,',','.') }}</strong></div>
@if(!in_array($document->status,['invoiced','cancelled'],true) && !$hasActiveSalesperson)
    <div class="notice no-print" role="status">@if($currentUser->role==='admin')O documento precisa de um vendedor ativo antes de gerar a venda. <a href="#document-seller-assignment" data-open-disclosure="document-seller-assignment">Vincular ou alterar o vendedor</a>.@else
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
@if($currentUser->role==='admin')
    <details class="disclosure compact no-print" style="margin-top:20px"><summary>Histórico de atribuição do vendedor</summary><div class="disclosure-content scroll" role="region" aria-label="Histórico do vendedor creditado" tabindex="0"><table><thead><tr><th>Quando</th><th>Vendedor anterior</th><th>Vendedor atribuído</th><th>Quem registrou</th><th>Motivo</th></tr></thead><tbody>
    @forelse($assignments as $assignment)<tr><td>{{ $assignment->created_at->format('d/m/Y H:i') }}</td><td class="product-name">{{ $assignment->previous_seller_name??'Sem vendedor' }}</td><td class="product-name">{{ $assignment->new_seller_name??'Não informado' }}</td><td class="product-name">{{ $assignment->actor_name??'Não informado' }}</td><td class="seller-audit-reason">{{ $assignment->reason?:'—' }}</td></tr>@empty<tr><td colspan="5" class="empty">Nenhuma atribuição registrada no histórico disponível.</td></tr>@endforelse
    </tbody></table></div></details>
@endif
@if(!in_array($document->status,['invoiced','cancelled']))<div class="actions no-print">
@foreach(match($document->status){'draft'=>['approved'=>'Registrar aprovação'],'approved'=>$document->type==='work_order'?['in_progress'=>'Iniciar execução']:[],'in_progress'=>['completed'=>'Concluir serviço'],default=>[]} as $v=>$label)<form method="post" action="{{ route('document.status',['company'=>$tenant->slug,'id'=>$document->id]) }}">
@csrf<input type="hidden" name="status" value="{{ $v }}"><button class="primary">{{ $label }}</button></form>
@endforeach<form method="post" action="{{ route('document.status',['company'=>$tenant->slug,'id'=>$document->id]) }}" onsubmit="return confirm('Cancelar este documento?');">
@csrf<input type="hidden" name="status" value="cancelled"><button class="button secondary">Cancelar documento</button></form></div>
@endif
@if($hasActiveSalesperson && (($document->type==='quote'&&$document->status==='approved')||($document->type==='work_order'&&$document->status==='completed')))<form class="no-print" method="post" action="{{ route('document.invoice',['company'=>$tenant->slug,'id'=>$document->id]) }}">
@csrf<h2 style="margin-top:30px">Gerar venda</h2><div class="form-grid">
<div class="field"><label for="document-invoice-seller">Vendedor creditado
</label><input id="document-invoice-seller" value="{{ $document->seller_name??'Não informado' }}" readonly><small class="hint">A venda herda o vendedor deste documento. Quem confirmar a operação será registrado separadamente.</small></div><div><label>Forma de pagamento</label><select name="payment_method">
@foreach(['cash'=>'Dinheiro','pix'=>'Pix','credit'=>'Crédito','debit'=>'Débito','transfer'=>'Transferência','invoice'=>'A prazo'] as $v=>$l)<option value="{{ $v }}">{{ $l }}</option>
@endforeach</select></div><div><label>Recebimento</label><select name="paid"><option value="1">Já recebido</option><option value="0">A receber</option></select></div><div><label>Número de parcelas</label><input name="installments" type="number" min="1" max="24" step="1" value="{{ old('installments',1) }}" required><small class="hint">Parcelas mensais para vendas a receber.</small></div><div><label>Primeiro vencimento se pendente</label><input type="date" name="due_date"></div></div><div class="actions"><button class="primary">Confirmar venda e baixar estoque</button></div><p class="hint">Não emite nota fiscal nem realiza cobrança automática.</p></form>
@endif
@if($document->sale_id)<p><a href="{{ route('sale.show',['company'=>$tenant->slug,'id'=>$document->sale_id]) }}">Abrir venda #{{ $document->sale_id }}</a></p>
@endif</section>
@endsection
