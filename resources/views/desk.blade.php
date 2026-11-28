@extends('layout')
@section('title','Frente de caixa')
@section('subtitle','Busque os itens, confira o total e finalize a venda.')
@section('actions')
<div class="actions pos-shortcuts">@if($currentUser->allows('sales.create'))
@include('components.sales.operator-screen')
@endif
@if($operationConfig['quotes'] && $currentUser->allows('documents.create'))<a class="button secondary" href="{{ route('document.new',['company'=>$tenant->slug,'type'=>'quote','salesperson_id'=>$selectedSalespersonId]) }}">Orçamento</a>@endif @if($operationConfig['work_orders'] && $currentUser->allows('documents.create'))<a class="button secondary" href="{{ route('document.new',['company'=>$tenant->slug,'type'=>'work_order','salesperson_id'=>$selectedSalespersonId]) }}">Ordem de serviço</a>@endif</div>
@endsection
@section('content')

@if(!$canOperate)
<div class="notice error" role="alert">Ative ou selecione um vendedor para registrar vendas. @if($currentUser->role==='admin')<a href="{{ route('sellers',['company'=>$tenant->slug]) }}">Conferir vendedores</a>.@endif</div>
@endif
@if($currentUser->allows('sales.create'))
@include('components.sales.checkout-form')
@else
<div class="notice">Seu perfil não libera o registro de vendas. Consulte o administrador para ajustar suas funções.</div>
@endif
@if($currentUser->allows('documents.view'))
<details class="panel pos-documents no-print" @if($errors->has('document_number')) open @endif><summary>{{ $currentUser->allows('documents.invoice') ? 'Consultar OS ou receber documento' : 'Consultar e imprimir OS' }}</summary><form method="get" action="{{ route('desk',['company'=>$tenant->slug]) }}"><div class="field"><label for="document-number">Número do documento</label><input id="document-number" type="number" name="document_number" value="{{ old('document_number') }}" min="1" required></div>@if($currentUser->allows('documents.invoice'))<button class="button secondary">Abrir pagamento</button>@endif <button class="button secondary" name="action" value="print_os" formtarget="_blank">Imprimir OS</button></form>
@if($currentUser->allows('documents.invoice') && $readyDocuments->isNotEmpty())<div class="scroll"><table><thead><tr><th>Documento</th><th>Cliente</th><th class="num">Total</th><th></th></tr></thead><tbody>@foreach($readyDocuments as $ready)<tr><td>{{ $ready->type==='quote'?'Orçamento':'OS' }} #{{ $ready->id }}</td><td>{{ $ready->customer?->name??'Não informado' }}</td><td class="num">R$ {{ number_format($ready->total,2,',','.') }}</td><td><a href="{{ route('document.show',['company'=>$tenant->slug,'id'=>$ready->id]) }}#document-checkout">Receber no caixa</a> @if($ready->type==='work_order' && $currentUser->allows('documents.view')) @include('components.work-order-print',['orderId'=>$ready->id]) @endif</td></tr>@endforeach</tbody></table></div>@endif
</details>
@endif

<details class="pos-history no-print"><summary>Atendimentos e vendas do dia</summary><div class="pos-history-content"><div class="actions">@if($currentUser->allows('sales.view'))<a href="{{ route('sales',['company'=>$tenant->slug,'salesperson_id'=>$selectedSalespersonId]) }}">Consultar vendas</a>@endif @if($currentUser->allows('documents.view'))<a href="{{ route('documents',['company'=>$tenant->slug,'salesperson_id'=>$selectedSalespersonId]) }}">Consultar orçamentos e OS</a>@endif</div><p class="hint">Vendas de hoje deste vendedor: {{ $metrics['sales_count'] }} · R$ {{ number_format($metrics['sales_total'],2,',','.') }}.</p>
@if($operationConfig['quotes'] && $currentUser->allows('documents.view') && $myQuotes->isNotEmpty())<h2>Orçamentos para continuar</h2><div class="scroll"><table><thead><tr><th>Orçamento</th><th>Cliente</th><th class="num">Total</th><th></th></tr></thead><tbody>@foreach($myQuotes as $quote)<tr><td>#{{ $quote->id }}</td><td>{{ $quote->customer?->name??'Consumidor final' }}</td><td class="num">R$ {{ number_format($quote->total,2,',','.') }}</td><td><a href="{{ route('document.show',['company'=>$tenant->slug,'id'=>$quote->id]) }}">Abrir</a></td></tr>@endforeach</tbody></table></div>@endif
</div></details>
@endsection
