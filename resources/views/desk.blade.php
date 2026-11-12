@extends('layout')
@section('title','PDV — Frente de caixa')
@section('subtitle','Produtos, serviços, cliente e pagamento no mesmo atendimento.')
@section('content')
@php($deskContext=['company'=>$tenant->slug,'salesperson_id'=>$salesperson?->id,'customer_id'=>$selectedCustomer?->id])
<section class="panel desk-owner-panel">
    <div><span class="eyebrow">VENDEDOR CREDITADO</span><h2>{{ $salesperson?->name??'Nenhum vendedor ativo' }}</h2><p class="hint">Operação registrada por {{ $currentUser->name }}. O crédito permanece com o vendedor vinculado.</p></div>
    @if($currentUser->role==='admin')
        <form class="desk-owner-filter" method="get" action="{{ route('desk',['company'=>$tenant->slug]) }}"><div><label for="desk-salesperson">Operar com o vendedor</label><select id="desk-salesperson" name="salesperson_id" required><option value="">Selecione</option>@foreach($sellers as $seller)<option value="{{ $seller->id }}" @selected((string)$salesperson?->id===(string)$seller->id)>{{ $seller->name }}</option>@endforeach</select></div>@if($selectedCustomer)<input type="hidden" name="customer_id" value="{{ $selectedCustomer->id }}">@endif<button class="button secondary">Aplicar</button></form>
    @endif
</section>
@if(!$canOperate)
<div class="notice error" role="alert">É necessário um vendedor ativo para iniciar o atendimento. @if($currentUser->role==='admin')<a href="{{ route('sellers',['company'=>$tenant->slug]) }}">Confira os perfis de vendedores</a>.@else
Peça ao administrador para conferir o perfil vinculado ao seu acesso.@endif</div>
@endif
@if($currentUser->role==='admin' && ($unassignedQuotesCount??0)>0)
<div class="notice no-print" role="status">Há {{ $unassignedQuotesCount }} orçamentos sem vendedor. Vincule um responsável para organizá-los no painel. <a href="{{ route('documents',['company'=>$tenant->slug]) }}">Conferir documentos</a>.</div>
@endif
<section class="panel desk-customer-panel" aria-labelledby="desk-customer-title">
    <div class="section-heading"><div><h2 id="desk-customer-title">Quem será atendido?</h2><p class="hint">Selecione um cliente ou comece sem identificação para consumidor final.</p></div>@if($selectedCustomer)<span class="badge">Cliente selecionado</span>@endif</div>
    @if($selectedCustomer)
        <div class="desk-selected-customer"><div><strong>{{ $selectedCustomer->name }}</strong><span>{{ $selectedCustomer->phone??$selectedCustomer->email??'Sem contato informado' }}</span></div><a href="{{ route('desk',['company'=>$tenant->slug,'salesperson_id'=>$salesperson?->id]) }}">Limpar seleção</a></div>
    @endif
    <form class="desk-customer-search" method="get" action="{{ route('desk',['company'=>$tenant->slug]) }}"><input type="hidden" name="salesperson_id" value="{{ $salesperson?->id }}">@if($selectedCustomer)<input type="hidden" name="customer_id" value="{{ $selectedCustomer->id }}">@endif<div><label for="desk-customer-search">Buscar cliente por nome, telefone ou e-mail</label><input id="desk-customer-search" name="q" value="{{ $search }}" maxlength="100" placeholder="Digite para buscar um cliente" autocomplete="off"></div><button class="button secondary">Buscar cliente</button></form>
    @if($search!=='')
        <div class="desk-customer-results" role="region" aria-label="Clientes encontrados">
        @forelse($customerMatches as $customer)<a href="{{ route('desk',['company'=>$tenant->slug,'salesperson_id'=>$salesperson?->id,'customer_id'=>$customer->id,'q'=>$search]) }}" class="desk-customer-result"><span><strong>{{ $customer->name }}</strong><small>{{ $customer->phone??$customer->email??'Sem contato informado' }}</small></span><span>Selecionar</span></a>@empty<p class="hint">Nenhum cliente encontrado. Revise a busca ou inicie como consumidor final.</p>@endforelse
        </div>
    @endif
</section>
@if($canOperate)
<section aria-label="Venda no PDV" id="pdv-checkout">
    @include('components.sales.checkout-form')
</section>
@endif
<div class="actions"><a class="button secondary" href="{{ route('document.new',array_merge($deskContext,['type'=>'quote'])) }}">Preparar orçamento</a></div>
<div class="desk-metrics" aria-label="Resumo do vendedor selecionado"><div><span>Vendas de hoje</span><strong>{{ $metrics['sales_count'] }}</strong></div><div><span>Valor vendido hoje</span><strong>R$ {{ number_format($metrics['sales_total'],2,',','.') }}</strong></div><div><span>Orçamentos em andamento</span><strong>{{ $metrics['quotes_count'] }}</strong></div></div>
<p class="hint desk-metrics-note">Resumo exclusivo do vendedor selecionado. Valores vendidos não representam dinheiro já recebido.</p>
<section class="panel">
    <div class="section-heading"><h2>Orçamentos para continuar</h2><a href="{{ route('documents',['company'=>$tenant->slug,'salesperson_id'=>$salesperson?->id]) }}">Ver documentos</a></div>
    <div class="scroll"><table><thead><tr><th>Orçamento</th><th>Cliente</th><th>Etapa</th><th class="num">Total</th><th></th></tr></thead><tbody>
    @forelse($myQuotes as $quote)<tr><td>#{{ $quote->id }}</td><td class="product-name">{{ $quote->customer?->name??'Não informado' }}</td><td><span class="badge {{ $quote->status==='approved'?'':'warn' }}">{{ $quote->status==='approved'?'Aprovado':'Aberto' }}</span></td><td class="num">R$ {{ number_format($quote->total,2,',','.') }}</td><td><a href="{{ route('document.show',['company'=>$tenant->slug,'id'=>$quote->id]) }}">{{ $quote->status==='approved'?'Abrir e gerar venda':'Abrir orçamento' }}</a></td></tr>@empty<tr><td colspan="5" class="empty">Nenhum orçamento aberto ou aprovado deste vendedor.</td></tr>@endforelse
    </tbody></table></div>
</section>
<section class="panel">
    <div class="section-heading"><h2>Últimas vendas de hoje</h2><a href="{{ route('sales',['company'=>$tenant->slug,'salesperson_id'=>$salesperson?->id]) }}">Ver vendas</a></div>
    <div class="scroll"><table><thead><tr><th>Venda</th><th>Horário</th><th>Cliente</th><th>Situação</th><th class="num">Total</th><th></th></tr></thead><tbody>
    @forelse($recentSales as $sale)<tr><td>#{{ $sale->id }}</td><td>{{ $sale->created_at->format('H:i') }}</td><td class="product-name">{{ $sale->customer?->name??'Consumidor final' }}</td><td><span class="badge {{ $sale->status==='cancelled'?'bad':(!$sale->paid?'warn':'') }}">{{ $sale->status==='cancelled'?'Cancelada':($sale->paid?'Recebida':'A receber') }}</span></td><td class="num">R$ {{ number_format($sale->total,2,',','.') }}</td><td><a href="{{ route('sale.show',['company'=>$tenant->slug,'id'=>$sale->id]) }}">Detalhes</a></td></tr>@empty<tr><td colspan="6" class="empty">Nenhuma venda deste vendedor hoje.</td></tr>@endforelse
    </tbody></table></div>
</section>
@if($canOperate)<div class="actions desk-secondary-actions"><a href="{{ route('document.new',array_merge($deskContext,['type'=>'work_order'])) }}">Registrar ordem de serviço</a><a href="{{ route('products',['company'=>$tenant->slug]) }}">Consultar catálogo e saldo</a></div>@endif
@endsection
