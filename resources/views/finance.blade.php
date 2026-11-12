
@extends('layout')
@section('title','Contas e recebimentos')
@section('subtitle','Controle manual de contas e vendas a receber. Esta versão não substitui a conciliação bancária nem um livro-caixa completo.')
@section('content')<div class="cards"><div class="card"><small>Contas a pagar pendentes</small><strong>R$ {{ number_format($payable,2,',','.') }}</strong></div><div class="card"><small>Vendas a receber</small><strong>R$ {{ number_format($pendingSales->sum('total'),2,',','.') }}</strong></div><div class="card"><small>Outras receitas pendentes</small><strong>R$ {{ number_format($otherReceivable,2,',','.') }}</strong></div></div><form class="panel" method="post">
@csrf<input type="hidden" name="request_key" value="{{ old('request_key',\Illuminate\Support\Str::uuid()) }}"><h2>Nova conta</h2><div class="form-grid"><div><label>Tipo</label><select name="direction"><option value="out">A pagar</option><option value="in">A receber (outras receitas)</option></select></div><div><label>Descrição</label><input name="description" maxlength="180" value="{{ old('description') }}" required></div><div><label>Categoria</label><input name="category" maxlength="100" value="{{ old('category') }}" placeholder="Aluguel, compras, energia..." required></div><div><label>Valor (R$)</label><input name="amount" type="number" step="0.01" min="0.01" value="{{ old('amount') }}" required></div><div><label>Vencimento</label><input name="due_date" type="date" value="{{ old('due_date') }}" required></div><div><label>Fornecedor (opcional)</label><select name="supplier_id"><option value="">Não informado</option>
@foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>
@endforeach</select></div></div><div class="actions"><button class="primary">Registrar conta</button></div></form><section class="panel"><h2>Contas registradas</h2><div class="scroll"><table><thead><tr><th>Descrição</th><th>Tipo</th><th>Categoria</th><th>Vencimento</th><th class="num">Valor</th><th>Situação</th><th></th></tr></thead><tbody>
@forelse($entries as $e)<tr><td>{{ $e->description }}</td><td>{{ $e->direction==='out'?'A pagar':'A receber' }}</td><td>{{ $e->category }}</td><td>{{ \Carbon\Carbon::parse($e->due_date)->format('d/m/Y') }}</td><td class="num">{{ number_format($e->amount,2,',','.') }}</td><td>{{ $e->paid_at?'Liquidada':'Pendente' }}</td><td>
@if(!$e->paid_at)<form method="post" action="{{ route('finance.pay',['company'=>$tenant->slug,'id'=>$e->id]) }}" onsubmit="return confirm('Confirmar que esta conta foi paga ou recebida integralmente?');">
@csrf<button class="text-button">Registrar baixa</button></form>
@endif</td></tr>
@empty<tr><td colspan="7" class="empty">Nenhuma conta registrada.</td></tr>
@endforelse</tbody></table></div>{{ $entries->links() }}</section><section class="panel"><h2>Vendas a receber</h2><div class="scroll"><table><thead><tr><th>Venda</th><th>Cliente</th><th>Vencimento</th><th class="num">Total</th></tr></thead><tbody>
@forelse($pendingSales as $s)<tr><td><a href="{{ route('sale.show',['company'=>$tenant->slug,'id'=>$s->id]) }}">#{{ $s->id }}</a></td><td>{{ $s->customer?->name??'Consumidor final' }}</td><td>{{ $s->due_date??'—' }}</td><td class="num">{{ number_format($s->total,2,',','.') }}</td></tr>
@empty<tr><td colspan="4" class="empty">Nenhuma venda com recebimento pendente.</td></tr>
@endforelse</tbody></table></div></section>
@endsection
