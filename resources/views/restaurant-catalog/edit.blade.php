@extends('layout')
@section('title','Cardápio · '.$product->name)
@section('subtitle','As alterações valem para novos lançamentos. Pedidos existentes preservam preços, adicionais e ingredientes.')
@section('actions')<a class="button secondary" href="{{ route('restaurant.catalog.index',['company'=>$tenant->slug]) }}">Voltar ao cardápio</a>@endsection
@section('content')
@php
    $recipeRows=array_values(old('recipe.ingredients',$config->recipe['ingredients']??[]));
    $groupRows=array_values(old('groups',$groups->map(fn($group)=>$group->toArray())->all()));
@endphp
<form method="post" action="{{ route('restaurant.catalog.save',['company'=>$tenant->slug,'product'=>$product->id]) }}" id="restaurant-catalog-form">
@csrf
<input type="hidden" name="version" value="{{ old('version',$config->revision) }}">
<section class="panel"><h2>Preparo e controle de estoque</h2><p><strong>{{ $product->name }}</strong> · {{ $product->sku }} · revisão {{ $config->revision }}</p>
<input type="hidden" name="show_on_menu" value="0"><label class="checkbox-line"><input type="checkbox" name="show_on_menu" value="1" @checked(old('show_on_menu',$config->show_on_menu??true))> Exibir no cardápio</label><p class="hint">Desmarque para ingredientes e insumos que não devem ser vendidos pela comanda. Eles continuam disponíveis na ficha técnica.</p>
<div class="form-grid"><div class="field"><label for="default-station">Destino padrão</label><select id="default-station" name="station"><option value="kitchen" @selected(old('station',$config->station)==='kitchen')>Cozinha</option><option value="bar" @selected(old('station',$config->station)==='bar')>Bar</option></select></div><div class="field"><label for="stock-mode">Estoque ao fechar a venda</label><select id="stock-mode" name="stock_mode"><option value="product" @selected(old('stock_mode',$config->stock_mode)==='product')>Usar a regra do produto</option><option value="recipe" @selected(old('stock_mode',$config->stock_mode)==='recipe')>Baixar somente os ingredientes da ficha</option></select></div></div>
<p class="hint">Para usar ficha técnica, habilite o estoque da empresa e deixe o produto preparado sem saldo nem controle próprio no cadastro de produtos. Os ingredientes precisam controlar estoque. O lançamento na comanda não movimenta estoque; a baixa ocorre ao fechar a venda.</p>
@if($currentUser->allows('products.manage'))<p><a href="{{ route('product.edit',['company'=>$tenant->slug,'id'=>$product->id]) }}">Conferir cadastro e controle de estoque do produto</a></p>@endif
</section>
<section class="panel"><h2>Ficha técnica por unidade preparada</h2><p class="hint">As equivalências são as já cadastradas para cada ingrediente. Quantidades precisam caber em três casas decimais na unidade de estoque, sem arredondamento.</p>
@if($estimatedCost!==null)<p>Custo estimado dos ingredientes: <strong>R$ {{ number_format($estimatedCost,4,',','.') }}</strong> por unidade. O custo efetivo é registrado com o estoque disponível no fechamento.</p>@endif
<div id="recipe-ingredients">@foreach($recipeRows as $ingredientIndex=>$row)@include('restaurant-catalog.ingredient-row')@endforeach</div><button type="button" class="button secondary" id="add-recipe-ingredient">+ Ingrediente</button>
</section>
<section><div class="section-heading"><h2>Grupos de adicionais</h2><button type="button" class="button secondary" id="add-modifier-group">+ Grupo de escolhas</button></div><div id="modifier-groups">@foreach($groupRows as $groupIndex=>$group)@include('restaurant-catalog.group-fields')@endforeach</div></section>
<input type="hidden" name="catalog_complete" value="1">
<div class="actions"><button class="button">Salvar cardápio e ficha</button></div>
</form>
<template id="recipe-row-template">@include('restaurant-catalog.ingredient-row',['ingredientIndex'=>'__INDEX__','row'=>[]])</template>
<template id="modifier-group-template">@include('restaurant-catalog.group-fields',['groupIndex'=>'__GROUP__','group'=>[]])</template>
<template id="modifier-option-template">@include('restaurant-catalog.option-fields',['groupIndex'=>'__GROUP__','optionIndex'=>'__OPTION__','option'=>[]])</template>
@endsection
@push('scripts')
<script>
(() => {
    const form=document.getElementById('restaurant-catalog-form');
    let ingredientIndex={{ count($recipeRows) }}, groupIndex={{ count($groupRows) }}, optionIndex=1000;
    const prepare=root=>root.querySelectorAll('[data-recipe-product]').forEach(select=>{
        if(select.dataset.ready)return; select.dataset.ready='1';
        const row=select.closest('.recipe-row'), units=row.querySelector('[data-recipe-unit]'), factor=row.querySelector('[data-recipe-factor]');
        const update=()=>{const available=JSON.parse(select.selectedOptions[0]?.dataset.units||'{}');units.replaceChildren(...Object.keys(available).map(code=>new Option(code,code)));if(units.dataset.selected&&available[units.dataset.selected])units.value=units.dataset.selected;units.dataset.selected='';factor.value=available[units.value]||'';};
        select.addEventListener('change',update);units.addEventListener('change',()=>factor.value=JSON.parse(select.selectedOptions[0]?.dataset.units||'{}')[units.value]||'');update();
    });
    prepare(form);
    document.getElementById('add-recipe-ingredient').addEventListener('click',()=>{document.getElementById('recipe-ingredients').insertAdjacentHTML('beforeend',document.getElementById('recipe-row-template').innerHTML.replaceAll('__INDEX__',ingredientIndex++));prepare(form);});
    document.getElementById('add-modifier-group').addEventListener('click',()=>{document.getElementById('modifier-groups').insertAdjacentHTML('beforeend',document.getElementById('modifier-group-template').innerHTML.replaceAll('__GROUP__',groupIndex++));prepare(form);});
    form.addEventListener('click',event=>{const remove=event.target.closest('[data-remove-recipe-row]');if(remove)remove.closest('.recipe-row').remove();const add=event.target.closest('[data-add-modifier-option]');if(add){const group=add.closest('[data-group-index]');group.querySelector('[data-modifier-options]').insertAdjacentHTML('beforeend',document.getElementById('modifier-option-template').innerHTML.replaceAll('__GROUP__',group.dataset.groupIndex).replaceAll('__OPTION__',optionIndex++));prepare(form);}});
})();
</script>
@endpush
