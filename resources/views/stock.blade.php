
@extends('layout')
@section('title','Estoque')
@section('subtitle','Entradas atualizam o custo médio. Saídas preservam a média e nunca permitem saldo negativo.')
@section('content')
@if($currentUser->role==='admin')<form class="panel" method="post">
@csrf<input type="hidden" name="request_key" value="{{ old('request_key',\Illuminate\Support\Str::uuid()) }}"><h2>Registrar movimentação</h2><div class="form-grid"><div class="field"><label>Produto</label><select name="product_id" required><option value="">Selecione</option>
@foreach($products as $p)<option value="{{ $p->id }}" 
@selected(old('product_id')==$p->id)>{{ $p->sku }} · {{ $p->name }}</option>
@endforeach</select></div><div class="field"><label>Tipo</label><select name="type"><option value="in" 
@selected(old('type')==='in')>Entrada / compra / ajuste positivo</option><option value="out" 
@selected(old('type')==='out')>Saída / perda / ajuste negativo</option></select></div><div class="field"><label>Quantidade</label><input name="quantity" type="number" step="0.001" min="0.001" value="{{ old('quantity') }}" required></div><div class="field"><label>Custo unitário da entrada (R$)</label><input name="unit_cost" type="number" step="0.0001" min="0" value="{{ old('unit_cost',0) }}" required><small class="hint">Inclua frete e custos atribuídos à unidade. Na saída, o sistema usa o custo médio.</small></div><div class="field"><label>Motivo / documento</label><input name="reason" value="{{ old('reason') }}" required minlength="3" maxlength="500"></div></div><div class="actions"><button class="primary">Registrar movimentação</button></div><p class="hint">Para ajuste de inventário, registre a diferença entre o saldo contado e o saldo atual. Movimentações não são apagadas; correções devem gerar nova movimentação.</p></form>
@endif
<section class="panel"><h2>Histórico de movimentações</h2><div class="scroll"><table><thead><tr><th>Data</th><th>Produto</th><th>Tipo</th><th class="num">Quantidade</th><th class="num">Custo unitário</th><th class="num">Saldo após</th><th class="num">Custo médio após</th><th>Motivo</th></tr></thead><tbody>
@forelse($movements as $m)<tr><td>{{ $m->created_at->format('d/m/Y H:i') }}</td><td>{{ $m->product->name }}</td><td>{{ ['in'=>'Entrada','out'=>'Saída','sale'=>'Venda','return'=>'Estorno'][$m->type] }}</td><td class="num">{{ $m->quantity }}</td><td class="num">{{ $m->unit_cost }}</td><td class="num">{{ $m->balance }}</td><td class="num">{{ $m->average_cost }}</td><td>{{ $m->reason }}</td></tr>
@empty<tr><td colspan="8" class="empty">Nenhuma movimentação registrada.</td></tr>
@endforelse</tbody></table></div>{{ $movements->links() }}</section>
@endsection
