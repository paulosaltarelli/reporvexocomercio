@extends('operations.layout')
@section('title','Mesas e comandas')
@section('navigation-section','Restaurante')
@section('subtitle','Abra um atendimento, registre os pedidos e acompanhe a produção.')
@section('content')
@include('operations.activity',['activityScope'=>'index'])
<div class="operation-grid">
<div>
<section class="operation-panel">
<div class="operation-heading">
<h2>Mesas</h2>
<span class="operation-badge">{{ $tables->count() }} cadastradas</span>
</div>
@if($tables->isEmpty())<div class="operation-empty">Cadastre as mesas para organizar os atendimentos do salão.</div>
@else
<div class="operation-cards">
@foreach($tables as $table)
@php($tableOrders=$orders->where('restaurant_table_id',$table->id))
<article class="operation-card">
<strong>{{ $table->name }}</strong>
<span class="operation-badge {{ $tableOrders->isEmpty()?'ready':'' }}">{{ $tableOrders->isEmpty()?'Livre':'Ocupada' }}</span>
@foreach($tableOrders as $tableOrder)
@if($tableOrder->created_at)<small class="hint"><time data-operation-elapsed="{{ $tableOrder->created_at->toIso8601String() }}" data-elapsed-prefix="Em atendimento há">Em atendimento há {{ max(0,(int)$tableOrder->created_at->diffInMinutes(now())) }} min</time></small>@endif
<a href="{{ route('restaurant.orders.show',['company'=>$tenant->slug,'order'=>$tableOrder->id]) }}">Comanda #{{ $tableOrder->id }} · R$ {{ number_format($tableOrder->items->where('status','<>','cancelled')->reduce(fn($sum,$item)=>bcadd($sum,\App\Services\Decimal::round(bcmul($item->quantity,$item->unit_price,6),2),2),'0.00'),2,',','.') }}</a>
@endforeach
@if($tableOrders->isEmpty())<form method="post" action="{{ route('restaurant.orders.open',['company'=>$tenant->slug]) }}">
@csrf
<input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
<input type="hidden" name="table_id" value="{{ $table->id }}">
<button class="button secondary">Abrir comanda</button>
</form>
@endif
</article>
@endforeach
</div>
@endif
</section>
<section class="operation-panel">
<h2>Comandas em atendimento</h2>
@if($orders->isEmpty())<div class="operation-empty">Nenhum atendimento aberto.</div>
@else
<div class="scroll">
<table>
<thead>
<tr>
<th>Comanda</th>
<th>Mesa / atendimento</th>
<th>Cliente</th>
<th class="num">Total</th>
</tr>
</thead>
<tbody>
@foreach($orders as $openOrder)<tr>
<td>
<a href="{{ route('restaurant.orders.show',['company'=>$tenant->slug,'order'=>$openOrder->id]) }}">#{{ $openOrder->id }}</a>
</td>
<td>{{ $openOrder->table?->name ?? 'Balcão' }}</td>
<td>{{ $openOrder->customer?->name ?? 'Não informado' }}</td>
<td class="num">R$ {{ number_format($openOrder->items->where('status','<>','cancelled')->reduce(fn($sum,$item)=>bcadd($sum,\App\Services\Decimal::round(bcmul($item->quantity,$item->unit_price,6),2),2),'0.00'),2,',','.') }}</td>
</tr>
@endforeach
</tbody>
</table>
</div>
@endif
</section>
</div>
<div class="operation-summary">
<section class="operation-panel">
<h2>Novo atendimento</h2>
<form method="post" action="{{ route('restaurant.orders.open',['company'=>$tenant->slug]) }}">
@csrf
<input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
<div class="field">
<label for="restaurant-table">Mesa</label>
<select id="restaurant-table" name="table_id" required>
<option value="">Selecione uma mesa livre</option>
@foreach($tables as $table)<option value="{{ $table->id }}" @disabled($orders->contains('restaurant_table_id',$table->id)) @selected((string)old('table_id')===(string)$table->id)>{{ $table->name }}</option>
@endforeach
</select>
</div>
<div class="field">
<label for="restaurant-customer">Cliente (opcional)</label>
<select id="restaurant-customer" name="customer_id">
<option value="">Não informado</option>
@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((string)old('customer_id')===(string)$customer->id)>{{ $customer->name }}</option>
@endforeach
</select>
</div>
<div class="actions">
<button class="button">Abrir atendimento</button>
</div>
</form>
</section>
@if($currentUser->allows('restaurant.manage'))<section class="operation-panel">
<h2>Cadastrar mesa</h2>
<form method="post" action="{{ route('restaurant.table.store',['company'=>$tenant->slug]) }}">
@csrf
<div class="field">
<label for="restaurant-table-name">Nome ou número</label>
<input id="restaurant-table-name" name="name" maxlength="80" value="{{ old('name') }}" placeholder="Mesa 01" required>
</div>
<div class="actions">
<button class="button secondary">Cadastrar mesa</button>
</div>
</form>
</section>
@endif
</div>
</div>
@endsection
