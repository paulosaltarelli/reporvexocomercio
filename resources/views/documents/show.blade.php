
@extends('layout')
@section('title',($document->type==='quote'?'Orçamento #':'Ordem de serviço #').$document->id)
@section('subtitle','Documento interno. Aprovações registradas manualmente pela equipe; não há assinatura digital nesta versão.')
@section('actions')<button class="button secondary no-print" onclick="window.print()">Imprimir</button>
@endsection
@section('content')<section class="panel"><h2>{{ $tenant->name }}</h2><p><strong>Cliente:</strong> {{ $document->customer?->name??'Não informado' }}</p><p><strong>Etapa:</strong> {{ ['draft'=>'Aberto','approved'=>'Aprovado','in_progress'=>'Em execução','completed'=>'Concluído','invoiced'=>'Venda gerada','cancelled'=>'Cancelado'][$document->status] }}</p><p><strong>Bem atendido:</strong> {{ $document->asset??'—' }} · <strong>Responsável:</strong> {{ $document->technician??'—' }}</p><p><strong>Validade / previsão:</strong> {{ $document->valid_until??'—' }}</p><p style="white-space:pre-wrap">{{ $document->notes }}</p><div class="scroll"><table><thead><tr><th>Item</th><th class="num">Quantidade</th><th class="num">Preço</th><th class="num">Total</th></tr></thead><tbody>
@foreach($document->items as $i)<tr><td>{{ $i['description'] }}</td><td class="num">{{ $i['quantity'] }}</td><td class="num">{{ number_format($i['unit_price'],2,',','.') }}</td><td class="num">{{ number_format($i['total'],2,',','.') }}</td></tr>
@endforeach</tbody></table></div><h2 class="num" style="margin-top:20px">R$ {{ number_format($document->total,2,',','.') }}</h2>
@if(!in_array($document->status,['invoiced','cancelled']))<div class="actions no-print">
@foreach(match($document->status){'draft'=>['approved'=>'Registrar aprovação'],'approved'=>$document->type==='work_order'?['in_progress'=>'Iniciar execução']:[],'in_progress'=>['completed'=>'Concluir serviço'],default=>[]} as $v=>$label)<form method="post" action="{{ route('document.status',['company'=>$tenant->slug,'id'=>$document->id]) }}">
@csrf<input type="hidden" name="status" value="{{ $v }}"><button class="primary">{{ $label }}</button></form>
@endforeach<form method="post" action="{{ route('document.status',['company'=>$tenant->slug,'id'=>$document->id]) }}" onsubmit="return confirm('Cancelar este documento?');">
@csrf<input type="hidden" name="status" value="cancelled"><button class="button secondary">Cancelar documento</button></form></div>
@endif
@if(($document->type==='quote'&&$document->status==='approved')||($document->type==='work_order'&&$document->status==='completed'))<form class="no-print" method="post" action="{{ route('document.invoice',['company'=>$tenant->slug,'id'=>$document->id]) }}">
@csrf<h2 style="margin-top:30px">Gerar venda</h2><div class="form-grid"><div><label>Forma de pagamento</label><select name="payment_method">
@foreach(['cash'=>'Dinheiro','pix'=>'Pix','credit'=>'Crédito','debit'=>'Débito','transfer'=>'Transferência','invoice'=>'A prazo'] as $v=>$l)<option value="{{ $v }}">{{ $l }}</option>
@endforeach</select></div><div><label>Recebimento</label><select name="paid"><option value="1">Já recebido</option><option value="0">A receber</option></select></div><div><label>Número de parcelas</label><input name="installments" type="number" min="1" max="24" step="1" value="{{ old('installments',1) }}" required><small class="hint">Parcelas mensais para vendas a receber.</small></div><div><label>Primeiro vencimento se pendente</label><input type="date" name="due_date"></div></div><div class="actions"><button class="primary">Confirmar venda e baixar estoque</button></div><p class="hint">Não emite nota fiscal nem realiza cobrança automática.</p></form>
@endif
@if($document->sale_id)<p><a href="{{ route('sale.show',['company'=>$tenant->slug,'id'=>$document->sale_id]) }}">Abrir venda #{{ $document->sale_id }}</a></p>
@endif</section>
@endsection
