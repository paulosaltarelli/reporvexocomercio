
@extends('layout')
@section('title',$product->exists?'Editar produto':'Novo produto')
@section('subtitle','O saldo e o custo médio são atualizados exclusivamente pelas movimentações de estoque.')
@section('content')<form class="panel" method="post" action="{{ route('product.save',['company'=>$tenant->slug,'id'=>$product->id]) }}">
@csrf<div class="form-grid">
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
<div class="field"><label>Margem própria (%)</label><input id="margin" type="number" step="0.01" min="0" max="99.99" name="margin" value="{{ old('margin',$product->margin) }}"><small class="hint">Em branco: herda a margem do grupo.</small></div><div class="field"><label>Preço de venda (R$)</label><input id="sale_price" type="number" step="0.01" min="0" name="sale_price" value="{{ old('sale_price',$product->sale_price) }}" required></div><div class="field"><label>Preço sugerido</label><strong id="suggested">—</strong><br><button type="button" class="text-button" id="apply-price">Usar sugestão</button><small class="hint">Custo médio atual: R$ {{ number_format($product->average_cost??0,4,',','.') }}. Sem impostos e despesas.</small></div>
<div class="field"><label>NCM (opcional)</label><input name="ncm" maxlength="8" value="{{ old('ncm',$product->ncm) }}"></div><div class="field"><label>Origem fiscal (opcional)</label><select name="origin"><option value="">Não informada</option>
@foreach(range(0,8) as $o)<option value="{{ $o }}" 
@selected((string)old('origin',$product->origin)===(string)$o)>{{ $o }}</option>
@endforeach</select></div><div class="field"><label><input type="checkbox" name="active" value="1" 
@checked(old('active',$product->active))> Produto ativo</label></div></div><div class="actions"><button class="primary">Salvar produto</button><a href="{{ route('products',['company'=>$tenant->slug]) }}">Voltar</a></div></form>
@endsection
@push('scripts')<script>const cost={{ (float)($product->average_cost??0) }};function suggest(){const m=document.querySelector('#margin').value||document.querySelector('#group').selectedOptions[0]?.dataset.margin||0;const p=cost/(1-Number(m)/100);document.querySelector('#suggested').textContent=Number.isFinite(p)?p.toLocaleString('pt-BR',{style:'currency',currency:'BRL'}):'—';return p;}document.querySelector('#margin').addEventListener('input',suggest);document.querySelector('#group').addEventListener('change',suggest);document.querySelector('#apply-price').onclick=()=>{const p=suggest();if(Number.isFinite(p))document.querySelector('#sale_price').value=p.toFixed(2);};suggest();</script>
@endpush
