
@extends('layout')
@section('title','Estoque')
@section('subtitle',$currentUser->role==='admin'?'Entradas atualizam o custo médio. Saídas preservam a média e nunca permitem saldo negativo.':'Consulte o saldo e o histórico de movimentações dos produtos.')
@section('actions')
@if($currentUser->role==='admin')<a class="button secondary" href="{{ route('prices',['company'=>$tenant->slug]) }}">Conferir preços e alertas</a>
@endif
@endsection
@section('content')
@if($currentUser->role==='admin')<form class="panel" method="post">
@csrf<input type="hidden" name="request_key" value="{{ old('request_key',\Illuminate\Support\Str::uuid()) }}"><h2>Registrar movimentação</h2><div class="form-grid"><div class="field"><label>Produto</label><select name="product_id" required><option value="">Selecione</option>
@foreach($products as $p)<option value="{{ $p->id }}" data-price-policy="{{ $p->price_policy??$p->group->price_policy??'alert' }}"
@selected(old('product_id')==$p->id)>{{ $p->sku }} · {{ $p->name }}</option>
@endforeach</select><small class="hint"><span id="movement-price-policy" class="badge" role="status">Selecione um produto</span></small></div><div class="field"><label>Tipo</label><select name="type"><option value="in"
@selected(old('type')==='in')>Entrada / compra / ajuste positivo</option><option value="out"
@selected(old('type')==='out')>Saída / perda / ajuste negativo</option></select></div><div class="field"><label>Quantidade</label><input name="quantity" type="number" step="0.001" min="0.001" value="{{ old('quantity') }}" required></div><div class="field"><label>Custo unitário da entrada (R$)</label><input name="unit_cost" type="number" step="0.0001" min="0" value="{{ old('unit_cost',0) }}" required><small class="hint">Inclua frete e custos atribuídos à unidade. Na saída, o sistema usa o custo médio.</small></div><div class="field"><label>Motivo / documento</label><input name="reason" value="{{ old('reason') }}" required minlength="3" maxlength="500"></div></div><div class="actions"><button class="primary">Registrar movimentação</button></div><p class="hint">Conforme a regra de cada produto, as entradas podem atualizar o preço automaticamente ou gerar alerta. Para ajuste de inventário, registre a diferença entre o saldo contado e o saldo atual. Movimentações não são apagadas; correções devem gerar nova movimentação.</p></form>
@endif
<section class="panel"><h2>Histórico de movimentações</h2><div class="scroll"><table><thead><tr><th>Data</th><th>Produto</th><th>Tipo</th><th class="num">Quantidade</th>
@if($currentUser->role==='admin')<th class="num">Custo unitário</th>
@endif<th class="num">Saldo após</th>
@if($currentUser->role==='admin')<th class="num">Custo médio após</th>
@endif<th>Motivo</th></tr></thead><tbody>
@forelse($movements as $m)<tr><td>{{ $m->created_at->format('d/m/Y H:i') }}</td><td>{{ $m->product->name }}</td><td>{{ ['in'=>'Entrada','out'=>'Saída','sale'=>'Venda','return'=>'Estorno'][$m->type] }}</td><td class="num">{{ $m->quantity }}</td>
@if($currentUser->role==='admin')<td class="num">{{ $m->unit_cost }}</td>
@endif<td class="num">{{ $m->balance }}</td>
@if($currentUser->role==='admin')<td class="num">{{ $m->average_cost }}</td>
@endif<td>{{ $m->reason }}</td></tr>
@empty<tr><td colspan="{{ $currentUser->role==='admin'?8:6 }}" class="empty">Nenhuma movimentação registrada.</td></tr>
@endforelse</tbody></table></div>{{ $movements->links() }}</section>
@endsection

@push('scripts')
<script>
const movementProduct=document.querySelector('select[name="product_id"]');
const movementPolicy=document.querySelector('#movement-price-policy');
if(movementProduct && movementPolicy){
    function showPricePolicy(){
        const mode=movementProduct.selectedOptions[0]?.dataset.pricePolicy;
        movementPolicy.textContent=mode?(mode==='automatic'?'Preço automático':'Somente alertas'):'Selecione um produto';
        movementPolicy.classList.toggle('warn',mode==='alert');
    }
    movementProduct.addEventListener('change',showPricePolicy);showPricePolicy();
}
const movementType=document.querySelector('select[name="type"]');
const movementCost=document.querySelector('input[name="unit_cost"]');
if(movementType&&movementCost){
    let incomingCost=movementCost.value;
    function syncCost(){
        const outgoing=movementType.value==='out';
        if(outgoing){incomingCost=movementCost.value;movementCost.value='0';}
        else if(movementCost.readOnly){movementCost.value=incomingCost;}
        movementCost.readOnly=outgoing;
        movementCost.setAttribute('aria-label',outgoing?'Custo calculado automaticamente pelo custo médio':'Custo unitário da entrada em reais');
    }
    movementType.addEventListener('change',syncCost);
    syncCost();
}
</script>
@endpush
