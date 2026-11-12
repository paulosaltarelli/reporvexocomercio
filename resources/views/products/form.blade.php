
@extends('layout')
@section('title',$product->exists?'Editar produto':'Novo produto')
@section('subtitle','O saldo e o custo médio são atualizados exclusivamente pelas movimentações de estoque.')
@section('content')<form class="panel" method="post" action="{{ route('product.save',['company'=>$tenant->slug,'id'=>$product->id]) }}">
@csrf
@if($groups->isEmpty())
<div class="form-note" role="status">Antes do primeiro produto, <a href="{{ route('catalog',['company'=>$tenant->slug,'kind'=>'groups']) }}">cadastre um grupo de produtos</a> para definir a margem padrão.</div>
@endif
<div class="form-note">A margem é calculada sobre o preço final: <strong>custo ÷ (1 − margem/100)</strong>. Exemplo: custo de R$ 60,00 e margem de 40% resultam em R$ 100,00. Essa sugestão não inclui impostos e despesas.</div>
<div class="form-grid">
@foreach(['name'=>'Nome do produto','sku'=>'SKU / código interno','barcode'=>'Código de barras'] as $field=>$label)<div class="field"><label for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" value="{{ old($field,$product->$field) }}"
@required($field!=='barcode') maxlength="{{ $field==='name'?180:80 }}"></div>
@endforeach
<div class="field"><label>Grupo</label><select name="product_group_id" id="group" required><option value="">Selecione</option>
@foreach($groups as $g)<option value="{{ $g->id }}" data-margin="{{ $g->margin }}"
@selected(old('product_group_id',$product->product_group_id)==$g->id)>{{ $g->name }} · {{ $g->margin }}%</option>
@endforeach</select></div>
<div class="field"><label>Unidade</label><select name="unit">
@foreach(['UN','KG','M','L','CX','PC','PAR'] as $u)<option
@selected(old('unit',$product->unit)===$u)>{{ $u }}</option>
@endforeach</select></div><div class="field"><label>Estoque mínimo</label><input type="number" step="0.001" min="0" name="minimum_stock" value="{{ old('minimum_stock',$product->minimum_stock) }}" required></div>
<div class="field"><label>Margem própria (%)</label><input id="margin" type="number" step="0.01" min="0" max="99.99" name="margin" value="{{ old('margin',$product->margin) }}"><small class="hint">Em branco: herda a margem do grupo.</small></div><div class="field"><label>Preço de venda (R$)</label><input id="sale_price" type="number" step="0.01" min="0" name="sale_price" value="{{ old('sale_price',$product->sale_price) }}" required></div><div class="field"><label>Preço sugerido</label><strong id="suggested" role="status" aria-live="polite">—</strong><br><button type="button" class="text-button" id="apply-price">Usar sugestão</button><small class="hint">Custo médio atual: R$ {{ number_format($product->average_cost??0,4,',','.') }}. Sem impostos e despesas.</small></div>
<div class="field"><label>NCM (opcional)</label><input name="ncm" maxlength="8" value="{{ old('ncm',$product->ncm) }}"></div><div class="field"><label>Origem fiscal (opcional)</label><select name="origin"><option value="">Não informada</option>
@foreach(range(0,8) as $o)<option value="{{ $o }}"
@selected((string)old('origin',$product->origin)===(string)$o)>{{ $o }}</option>
@endforeach</select></div><div class="field"><input type="hidden" name="active" value="0"><label><input type="checkbox" name="active" value="1"
@checked(old('active',$product->active))> Produto ativo</label></div></div><div class="actions"><button class="primary">Salvar produto</button><a href="{{ route('products',['company'=>$tenant->slug]) }}">Voltar</a></div></form>
@endsection
@push('scripts')
<script>
const cost = @json((string)($product->average_cost ?? '0'));
const marginField = document.querySelector('#margin');
const groupField = document.querySelector('#group');
const applyPrice = document.querySelector('#apply-price');
function suggest() {
    const margin = marginField.value || groupField.selectedOptions[0]?.dataset.margin;
    const cents = margin === undefined ? null : Vendra.suggestedCents(cost, margin);
    const hasCost = Vendra.decimalUnits(cost, 4) > 0n;
    document.querySelector('#suggested').textContent = !hasCost ? 'Custo ainda não informado' : (cents === null ? 'Confira o grupo e a margem' : Vendra.centsText(cents));
    applyPrice.disabled = !hasCost || cents === null;
    return cents;
}
marginField.addEventListener('input', suggest);
groupField.addEventListener('change', suggest);
applyPrice.addEventListener('click', () => {
    const cents = suggest();
    if (cents !== null && !applyPrice.disabled) {
        document.querySelector('#sale_price').value = (cents / 100n).toString() + '.' + (cents % 100n).toString().padStart(2, '0');
    }
});
suggest();
</script>
@endpush
