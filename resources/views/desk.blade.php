@extends('layout')
@section('title','Frente de caixa')
@section('subtitle','Busque os itens, confira o total e finalize a venda.')
@section('actions')<div class="actions pos-shortcuts">@if($operationConfig['quotes'])<a class="button secondary" href="{{ route('document.new',['company'=>$tenant->slug,'type'=>'quote','salesperson_id'=>$selectedSalespersonId]) }}">Orçamento</a>@endif @if($operationConfig['work_orders'])<a class="button secondary" href="{{ route('document.new',['company'=>$tenant->slug,'type'=>'work_order','salesperson_id'=>$selectedSalespersonId]) }}">Ordem de serviço</a>@endif</div>@endsection
@section('content')
@if(!$canOperate)
<div class="notice error" role="alert">Ative ou selecione um vendedor para registrar vendas. @if($currentUser->role==='admin')<a href="{{ route('sellers',['company'=>$tenant->slug]) }}">Conferir vendedores</a>.@endif</div>
@endif
@include('components.sales.checkout-form')
<details class="pos-history no-print"><summary>Atendimentos e vendas do dia</summary><div class="pos-history-content"><div class="actions"><a href="{{ route('sales',['company'=>$tenant->slug,'salesperson_id'=>$selectedSalespersonId]) }}">Consultar vendas</a><a href="{{ route('documents',['company'=>$tenant->slug,'salesperson_id'=>$selectedSalespersonId]) }}">Consultar orçamentos e OS</a></div><p class="hint">Vendas de hoje deste vendedor: {{ $metrics['sales_count'] }} · R$ {{ number_format($metrics['sales_total'],2,',','.') }}.</p>
@if($operationConfig['quotes'] && $myQuotes->isNotEmpty())<h2>Orçamentos para continuar</h2><div class="scroll"><table><thead><tr><th>Orçamento</th><th>Cliente</th><th class="num">Total</th><th></th></tr></thead><tbody>@foreach($myQuotes as $quote)<tr><td>#{{ $quote->id }}</td><td>{{ $quote->customer?->name??'Consumidor final' }}</td><td class="num">R$ {{ number_format($quote->total,2,',','.') }}</td><td><a href="{{ route('document.show',['company'=>$tenant->slug,'id'=>$quote->id]) }}">Abrir</a></td></tr>@endforeach</tbody></table></div>@endif
</div></details>
@endsection
