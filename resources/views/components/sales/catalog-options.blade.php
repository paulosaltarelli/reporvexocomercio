@php
    $recipeStock = ($isPos ?? false) && $operationConfig['inventory']
        ? app(\App\Services\RecipeInventory::class)->plansForProducts($products->modelKeys())
        : [];
@endphp
@foreach($products as $product)
    @php($isService=($product->type??'product')==='service')
    @php($stockControlled=!$isService && ($product->controls_stock??true))
    <option value="product:{{ $product->id }}" @if($isPos??false) data-measures="{{ json_encode(\App\Services\MeasureUnits::options($product,$operationConfig,'sale')) }}" @if($product->controlsStock() && !isset($recipeStock[$product->id])) data-stock-quantity="{{ $product->quantity }}" @endif @endif data-item-type="{{ $isService ? 'service' : 'product' }}" data-sku="{{ $product->sku }}" data-barcode="{{ $product->barcode }}" data-name="{{ $product->name }}" data-legacy-service-id="{{ $product->legacy_service_id??'' }}" data-price="{{ $product->sale_price }}" data-discount-enabled="{{ $product->discountEnabled()?'1':'0' }}" data-discount-limit="{{ $product->maxDiscountPercent() }}">{{ $isService?'Serviço':'Produto' }} · {{ $product->sku }} · {{ $product->name }}@if($stockControlled) (saldo {{ number_format($product->quantity,3,',','.') }})@endif</option>
@endforeach
