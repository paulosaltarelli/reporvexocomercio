
@extends('layout')
@section('title',$product->exists?'Editar produto ou serviço':'Novo produto ou serviço')
@section('subtitle','Um único cadastro para produtos e serviços, com estoque apenas quando fizer sentido para a operação.')
@push('styles')<link rel="stylesheet" href="/product-images.css?v={{ filemtime(public_path('product-images.css')) }}">@endpush
@section('content')<form class="panel" method="post" action="{{ route('product.save',['company'=>$tenant->slug,'id'=>$product->id]) }}">
@csrf
@php($hasStockBalance=bccomp((string)($product->quantity??'0'),'0',3)>0)
@php($itemType=old('type',$product->exists?$product->type:($operationConfig['catalog_mode']==='services'?'service':'product')))
@php($stockEnabled=$hasStockBalance?true:($operationConfig['inventory'] && (bool)old('controls_stock',$product->controls_stock??true)))
@if($groups->isEmpty())
<div class="form-note" role="status">Antes do primeiro cadastro, <a href="{{ route('catalog',['company'=>$tenant->slug,'kind'=>'groups']) }}">cadastre um grupo</a> para definir a margem padrão.</div>
@endif
@if(!$operationConfig['commissions'] || !$operationConfig['discounts'])<p class="form-note">As configurações da empresa prevalecem sobre os percentuais abaixo: comissões {{ $operationConfig['commissions']?'habilitadas':'desabilitadas' }}; descontos {{ $operationConfig['discounts']?'habilitados':'desabilitados' }}.</p>@endif
<div class="form-note">A margem é calculada sobre o preço final: <strong>custo ÷ (1 − margem/100)</strong>. Exemplo: custo de R$ 60,00 e margem de 40% resultam em R$ 100,00. Essa sugestão não inclui impostos e despesas.</div>
<div class="form-grid">
<div class="field"><label for="item-type">Tipo do cadastro</label><select id="item-type" name="type" required><option value="product" @selected($itemType==='product') @disabled(!$product->exists && $operationConfig['catalog_mode']==='services')>Produto</option><option value="service" @selected($itemType==='service') @disabled($hasStockBalance || (!$product->exists && $operationConfig['catalog_mode']==='products'))>Serviço</option></select><small class="hint">Serviços não movimentam estoque.</small></div>
<div class="field"><input id="controls-stock-hidden" type="hidden" name="controls_stock" value="{{ $hasStockBalance?'1':'0' }}"><label class="checkbox-line"><input id="controls_stock" type="checkbox" name="controls_stock" value="1" @checked($stockEnabled && $itemType==='product') @disabled($hasStockBalance || !$operationConfig['inventory'])> Controlar estoque deste produto</label><small class="hint" id="stock-control-note">@if($hasStockBalance)Há saldo de {{ number_format($product->quantity,3,',','.') }} {{ $product->unit }}. A opção permanece ativa enquanto houver saldo.@else
Desmarque para vender sem controlar quantidade. Informe o custo de referência para a precificação.@endif</small></div>
<div class="field" id="reference-cost-field"><label for="reference_cost">Custo de referência (R$)</label><input id="reference_cost" name="reference_cost" type="number" min="0" max="999999999.9999" step="0.0001" value="{{ old('reference_cost',$product->reference_cost??'0') }}"><small class="hint">Estimativa do custo unitário para serviços ou produtos sem estoque. Não gera movimentação de estoque.</small></div>
@foreach(['name'=>'Nome do produto ou serviço','sku'=>'SKU / código interno','barcode'=>'Código de barras'] as $field=>$label)<div class="field"><label for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" value="{{ old($field,$product->$field) }}"
@required($field!=='barcode') maxlength="{{ $field==='name'?180:80 }}"></div>
@endforeach
<div class="field"><label>Grupo</label><select name="product_group_id" id="group" required><option value="">Selecione</option>
@foreach($groups as $g)<option value="{{ $g->id }}" data-margin="{{ $g->margin }}" data-price-policy="{{ $g->price_policy??'alert' }}" data-discount-enabled="{{ $g->discount_enabled?'1':'0' }}" data-discount-limit="{{ $g->max_discount_percent??'0' }}" data-commission="{{ $g->commission_percent??'0' }}"
@selected(old('product_group_id',$product->product_group_id)==$g->id)>{{ $g->name }} · {{ $g->margin }}%</option>
@endforeach</select></div>
<div class="field"><label>Unidade de estoque (base)</label><a href="{{ route('units',['company'=>$tenant->slug]) }}" target="_blank" rel="noopener">Cadastrar unidades</a><select name="unit">
@foreach(array_keys(\App\Services\MeasureUnits::catalog($operationConfig)) as $u)<option
@selected(old('unit',$product->unit)===$u)>{{ $u }}</option>
@endforeach</select></div><div class="field" id="minimum-stock-field"><label>Estoque mínimo</label><input type="number" step="0.001" min="0" name="minimum_stock" value="{{ old('minimum_stock',$product->minimum_stock) }}" required></div>
<div class="field"><label>Margem própria (%)</label><input id="margin" type="number" step="0.01" min="0" max="99.99" name="margin" value="{{ old('margin',$product->margin) }}"><small class="hint">Em branco: herda a margem do grupo. A margem própria e a regra de atualização são escolhas independentes.</small></div><div class="field"><label for="price_policy">Atualização do preço</label><select id="price_policy" name="price_policy"><option value="" @selected(old('price_policy',$product->price_policy)===null||old('price_policy',$product->price_policy)==='')>Herdar regra do grupo</option><option value="alert" @selected(old('price_policy',$product->price_policy)==='alert')>Somente alertas · manter preço atual</option><option value="automatic" @selected(old('price_policy',$product->price_policy)==='automatic')>Automático · atualizar pelo custo médio</option></select><small class="hint" id="effective-policy" aria-live="polite"></small></div><div class="field"><label>Preço de venda (R$)</label><input id="sale_price" type="number" step="0.01" min="0" max="999999999999.99" name="sale_price" value="{{ old('sale_price',$product->sale_price) }}" required aria-describedby="price-policy-note"><small class="hint" id="price-policy-note"></small></div><div class="field"><label>Preço sugerido</label><strong id="suggested" role="status" aria-live="polite">—</strong><br><button type="button" class="text-button" id="apply-price">Usar sugestão</button><small class="hint" id="pricing-cost-base">A base de custo depende do controle de estoque. Sem impostos e despesas.</small></div>
<div class="field"><input type="hidden" name="active" value="0"><label><input type="checkbox" name="active" value="1"
@checked(old('active',$product->active))> Cadastro ativo</label></div></div>
@php($discountOverride=old('discount_enabled',$product->discount_enabled))
@php($discountChoice=$discountOverride===null||$discountOverride===''?'':((bool)$discountOverride?'1':'0'))
<details class="disclosure compact" style="margin-top:24px" @if($errors->hasAny(['discount_enabled','max_discount_percent','commission_percent'])) open @endif>
    <summary>Descontos e comissões</summary>
    <div class="form-grid disclosure-content">
        <div class="field"><label for="product-discount-enabled">Permissão de desconto</label><select id="product-discount-enabled" name="discount_enabled"><option value="" @selected($discountChoice==='')>Herdar do grupo</option><option value="1" @selected($discountChoice==='1')>Permitir</option><option value="0" @selected($discountChoice==='0')>Bloquear</option></select></div>
        <div class="field"><label for="product-discount-limit">Desconto máximo próprio (%)</label><input id="product-discount-limit" name="max_discount_percent" type="number" min="0" max="100" step="0.01" value="{{ old('max_discount_percent',$product->max_discount_percent) }}"><small class="hint">Em branco herda o limite do grupo. Zero sobrescreve e não permite abatimento.</small></div>
        <div class="field"><label for="product-commission">Comissão própria (%)</label><input id="product-commission" name="commission_percent" type="number" min="0" max="100" step="0.01" value="{{ old('commission_percent',$product->commission_percent) }}"><small class="hint">Em branco herda do grupo. Zero desativa a comissão neste item.</small></div>
    </div>
    <div class="form-note" id="effective-commercial-rules" role="status" aria-live="polite"></div>
    <p class="hint">Cada campo herda de forma independente. A comissão usa o valor líquido após descontos; sua apuração não registra pagamento ao vendedor.</p>
</details>
<details id="item-tax-section" class="disclosure compact" style="margin-top:24px" @if(!config('fiscal.product_invoices_enabled') && $itemType!=='service') hidden @endif @if($errors->hasAny(['ncm','origin','municipal_service_code','iss_rate'])) open @endif>
    <summary>{{ config('fiscal.product_invoices_enabled') ? 'Dados fiscais preparatórios (opcional)' : 'Dados municipais do serviço (opcional)' }}</summary>
    <div class="disclosure-content">
        @if(config('fiscal.product_invoices_enabled'))
        <div id="product-fiscal-fields" class="form-grid"><div class="field"><label>NCM (opcional)</label><input name="ncm" maxlength="8" value="{{ old('ncm',$product->ncm) }}"></div><div class="field"><label>Origem fiscal (opcional)</label><select name="origin"><option value="">Não informada</option>
@foreach(range(0,8) as $o)<option value="{{ $o }}"
@selected((string)old('origin',$product->origin)===(string)$o)>{{ $o }}</option>
@endforeach</select></div></div>
        @endif
        <div id="service-fiscal-fields" class="form-grid">
            <div class="field"><label for="municipal_service_code">Código municipal do serviço</label><input id="municipal_service_code" name="municipal_service_code" maxlength="30" value="{{ old('municipal_service_code',$product->municipal_service_code) }}"><small class="hint">Informe conforme a classificação usada pelo município.</small></div>
            <div class="field"><label for="iss_rate">Alíquota de ISS (%)</label><input id="iss_rate" name="iss_rate" type="number" min="0" max="100" step="0.01" value="{{ old('iss_rate',$product->iss_rate) }}"></div>
        </div>
        <p class="hint">Informe o código e a alíquota conforme o cadastro municipal do serviço.</p>
    </div>
</details>
<div class="actions"><button class="primary">Salvar cadastro</button><a href="{{ route('products',['company'=>$tenant->slug]) }}">Voltar</a></div></form>
@if($product->exists && $product->type==='product')
<form class="panel" method="post" action="{{ route('product.units',['company'=>$tenant->slug,'id'=>$product->id]) }}">
@csrf
<h2>Unidades de compra e de venda</h2>
<p>Informe quanto uma unidade representa em {{ $product->unit }}. Por exemplo, se o estoque está em KG, 1 T corresponde a 1000 KG.</p>
<input type="hidden" name="base" value="{{ $product->unit }}">
<div class="form-grid">
<div class="field"><label for="linked-unit">Unidade</label><select id="linked-unit" name="code" required>@foreach(\App\Services\MeasureUnits::catalog($operationConfig) as $code=>$label)@if($code!==$product->unit)<option value="{{ $code }}">{{ $code }} · {{ $label }}</option>@endif @endforeach</select></div>
<div class="field"><label for="unit-factor">Quantidade em {{ $product->unit }}</label><input id="unit-factor" name="factor" type="number" step="0.000001" min="0.000001" value="1" required></div>
<div class="field"><label for="unit-purpose">Usar em</label><select id="unit-purpose" name="purpose"><option value="both">Compras e vendas</option><option value="purchase">Compras</option><option value="sale">Vendas</option><option value="remove">Remover vínculo</option></select></div></div>
<button class="primary">Salvar equivalência</button>
<ul>@foreach(($operationConfig['unit_links'][$product->id]['units']??[]) as $code=>$link)<li>1 {{ $code }} = {{ rtrim(rtrim($link['factor'],'0'),'.') }} {{ $product->unit }} · {{ $link['sale'] && $link['purchase']?'Compras e vendas':($link['sale']?'Vendas':'Compras') }}</li>@endforeach</ul></form>
@endif
@if($product->exists)
<section class="panel product-image-upload">
<h2>Imagem do produto</h2>
@php($productImages=app(\App\Services\ProductImages::class))
<div class="product-image-settings">
<div>
@if($productImages->presentation($tenant->slug,$tenant->id,$product->id))
@include('components.product-image')
@else
<div class="product-image-empty">Nenhuma imagem cadastrada</div>
@endif
</div>
<form method="post" enctype="multipart/form-data" action="{{ route('product.image.save',['company'=>$tenant->slug,'id'=>$product->id]) }}">
@csrf
<div class="field"><label for="product-image">Escolher imagem</label><input id="product-image" type="file" name="product_image" accept="image/png,image/jpeg,image/webp" aria-describedby="product-image-help"><small class="hint" id="product-image-help">PNG, JPEG ou WebP, até 2 MB. Dimensões de 48 a 2048 pixels. Exibida no cardápio e na busca do caixa.</small></div>
<label class="checkbox-line"><input type="checkbox" name="remove_product_image" value="1"> Remover a imagem atual</label>
<p class="hint">Salve a imagem separadamente das alterações do cadastro.</p>
<div class="actions"><button class="button secondary">Salvar imagem</button></div>
</form>
</div>
</section>
@endif
@endsection
@push('scripts')
<script>
(() => {
    const averageCost = @json((string)($product->average_cost ?? '0'));
    const hasStockBalance = @json($hasStockBalance);
    const typeField=document.querySelector('#item-type');
    const companyInventory=@json($operationConfig['inventory']);
    const stockField=document.querySelector('#controls_stock');
    const stockHidden=document.querySelector('#controls-stock-hidden');
    const referenceCost=document.querySelector('#reference_cost');
    const referenceContainer=document.querySelector('#reference-cost-field');
    const minimumContainer=document.querySelector('#minimum-stock-field');
    const marginField=document.querySelector('#margin');
    const groupField=document.querySelector('#group');
    const policyField=document.querySelector('#price_policy');
    const priceField=document.querySelector('#sale_price');
    const applyPrice=document.querySelector('#apply-price');
    let productStockChoice=typeField.value==='product'?stockField.checked:true;
    let previousType=typeField.value;
    let manualPrice=priceField.value;
    let wasAutomatic=false;
    function controlsStock(){return typeField.value==='product' && stockField.checked;}
    function asValue(cents){return (cents/100n).toString()+'.'+(cents%100n).toString().padStart(2,'0');}
    function syncItemType(){
        if(!companyInventory){stockField.checked=false;stockField.disabled=true;stockHidden.value='0';}
        else if(hasStockBalance){typeField.value='product';stockField.checked=true;stockField.disabled=true;stockHidden.value='1';}
        else{
            if(typeField.value==='service'){
                if(previousType==='product')productStockChoice=stockField.checked;
                stockField.checked=false;stockField.disabled=true;
            }else{if(previousType==='service')stockField.checked=productStockChoice;stockField.disabled=false;}
            stockHidden.value='0';
            document.querySelector('#stock-control-note').textContent=typeField.value==='service'?'Serviços usam custo de referência e não controlam quantidade.':'Desmarque para vender sem controlar quantidade. Informe o custo de referência para a precificação.';
        }
        previousType=typeField.value;
        const isService=typeField.value==='service';
        const productFiscal=document.querySelector('#product-fiscal-fields');
        const serviceFiscal=document.querySelector('#service-fiscal-fields');
        document.querySelector('#item-tax-section').hidden=!productFiscal && !isService;
        if(productFiscal){productFiscal.hidden=isService;productFiscal.querySelectorAll('input,select').forEach(field=>field.disabled=isService);}
        if(serviceFiscal){serviceFiscal.hidden=!isService;serviceFiscal.querySelectorAll('input,select').forEach(field=>field.disabled=!isService);}
        const controlled=controlsStock();
        referenceContainer.hidden=controlled;
        referenceCost.required=!controlled;
        referenceCost.disabled=controlled;
        minimumContainer.hidden=!controlled;
        minimumContainer.querySelector('input').disabled=!controlled;
        document.querySelector('#pricing-cost-base').textContent=controlled?'Base de cálculo: custo médio das entradas de estoque. Sem impostos e despesas.':'Base de cálculo: custo de referência informado neste cadastro. Sem impostos e despesas.';
    }
    function updatePricing(){
        const controlled=controlsStock();
        const cost=controlled?averageCost:referenceCost.value;
        const units=Vendra.decimalUnits(cost,4);
        const hasCost=units!==null && units>0n;
        const group=groupField.selectedOptions[0];
        const margin=marginField.value || group?.dataset.margin;
        const mode=policyField.value || group?.dataset.pricePolicy || 'alert';
        const automatic=mode==='automatic';
        const cents=margin===undefined?null:Vendra.suggestedCents(cost,margin);
        const overflow=cents!==null && cents>99999999999999n;
        const canSuggest=hasCost && cents!==null && !overflow;
        document.querySelector('#suggested').textContent=!hasCost?'Custo ainda não informado':(overflow?'Sugestão acima do limite':(cents===null?'Confira o grupo e a margem':Vendra.centsText(cents)));
        marginField.setCustomValidity(automatic && overflow?'A margem gera um preço acima do limite. Revise a margem ou escolha somente alertas.':'');
        document.querySelector('#effective-policy').textContent=(policyField.value?'Regra própria: ':'Regra do grupo: ')+(automatic?'automático.':'somente alertas.');
        if(automatic && canSuggest){priceField.value=asValue(cents);priceField.readOnly=true;}
        else{if(wasAutomatic)priceField.value=manualPrice;priceField.readOnly=false;}
        wasAutomatic=automatic && canSuggest;
        applyPrice.hidden=automatic;applyPrice.disabled=!canSuggest;
        const missingCost=controlled?'Sem custo médio: o preço atual é preservado até uma entrada com custo.':'Sem custo de referência: o preço atual é preservado até informar um custo válido.';
        document.querySelector('#price-policy-note').textContent=automatic?(overflow?'O preço calculado ultrapassa o limite. Revise a margem antes de salvar.':(hasCost?'O servidor recalcula o preço usando a base de custo e a margem efetiva.':missingCost)):'O preço atual é mantido. Aplique a sugestão ou informe outro valor quando decidir alterá-lo.';
        return cents;
    }
    priceField.addEventListener('input',()=>{if(!priceField.readOnly)manualPrice=priceField.value;});
    typeField.addEventListener('change',()=>{syncItemType();updatePricing();});
    stockField.addEventListener('change',()=>{productStockChoice=stockField.checked;syncItemType();updatePricing();});
    [marginField,groupField,policyField,referenceCost].forEach(field=>field.addEventListener('input',updatePricing));
    [groupField,policyField].forEach(field=>field.addEventListener('change',updatePricing));
    applyPrice.addEventListener('click',()=>{const cents=updatePricing();if(cents!==null&&!applyPrice.disabled){manualPrice=asValue(cents);priceField.value=manualPrice;}});
    syncItemType();updatePricing();
})();
</script>
@endpush

@push('scripts')
<script>
(() => {
    const group=document.querySelector('#group');
    const enabled=document.querySelector('#product-discount-enabled');
    const limit=document.querySelector('#product-discount-limit');
    const commission=document.querySelector('#product-commission');
    function explain(){
        const option=group.selectedOptions[0];
        const allowed=(enabled.value===''?option?.dataset.discountEnabled:enabled.value)==='1';
        const rate=limit.value===''?(option?.dataset.discountLimit||'0'):limit.value;
        const commissionRate=commission.value===''?(option?.dataset.commission||'0'):commission.value;
        document.querySelector('#effective-commercial-rules').textContent=(allowed?'Desconto permitido até '+rate.replace('.',',')+'% de redução total.':'Desconto bloqueado neste item.')+' Comissão efetiva: '+commissionRate.replace('.',',')+'% sobre a venda líquida.';
    }
    [group,enabled,limit,commission].forEach(field=>{field.addEventListener('input',explain);field.addEventListener('change',explain);});explain();
})();
</script>
@endpush
