<section class="panel {{ ($isPos??false)?'pos-items-panel':'' }}">
    <div class="entry-section-heading"><h2>{{ $itemsHeading??'Itens da venda' }}</h2>
    @if($currentUser->allows('products.manage'))<div class="quick-create-buttons">@if($operationConfig['catalog_mode']!=='services')<button type="button" class="button secondary" data-quick-create="products" hidden>+ Novo produto</button>@endif @if($operationConfig['catalog_mode']!=='products')<button type="button" class="button secondary" data-quick-create="services" hidden>+ Novo serviço</button>@endif</div>@endif
    </div>
    @if($isPos??false)
    <div class="pos-search-field"><label for="pos-search">Produto, serviço ou código de barras <kbd>F2</kbd></label><input id="pos-search" type="search" autocomplete="off" placeholder="Leia o código ou digite o nome do item" maxlength="200" autofocus><p id="pos-search-status" class="hint" role="status" aria-live="polite">Enter adiciona o item encontrado.</p><div id="pos-results" class="pos-results" aria-label="Itens encontrados"></div></div>
    <p id="pos-empty-cart" class="pos-empty-cart">Busque ou leia o primeiro item para começar a venda.</p>
    @else
    <p class="hint">Reduzir o preço unitário também conta como desconto. O limite é conferido pelo servidor contra o preço de cadastro.</p>
    @endif
    <input type="hidden" name="items_present" value="1">
    <div id="items"></div>
    <button type="button" id="add" class="button secondary">+ Adicionar item</button>
    <div class="sale-totals" role="status" aria-live="polite">@if($splitItemTotals??false)<div><span>Produtos</span><strong id="product-total">R$ 0,00</strong></div><div><span>Serviços</span><strong id="service-total">R$ 0,00</strong></div>@endif<div><span>Bruto</span><strong id="gross-total">R$ 0,00</strong></div><div><span>Descontos</span><strong id="discount-total">R$ 0,00</strong></div><div class="sale-net-total"><span>Total líquido</span><strong id="total">R$ 0,00</strong></div></div>
    <p class="hint">Prévia por item, com arredondamento em centavos. Os valores definitivos são calculados na confirmação.</p>
</section>
@if($isPos??false)
<dialog id="pos-catalog-dialog" aria-labelledby="pos-catalog-title">
    <h2 id="pos-catalog-title">Selecionar item</h2>
    <label for="pos-catalog">Produto ou serviço</label>
    <select id="pos-catalog"><option value="">Selecione</option>@include('components.sales.catalog-options')</select>
    <div class="actions"><button type="button" class="button secondary" data-cancel>Cancelar</button><button type="button" class="primary" data-select>Usar item</button></div>
</dialog>
@endif
<template id="row">
    <div class="sale-line-block">
        <div class="sale-row commercial-sale-row">
            <div class="selection"><label>Produto ou serviço</label>@if($isPos??false)<button class="button secondary pos-pick-item" type="button">Selecionar item</button>@endif<select class="choice" @if($isPos??false) hidden @else required @endif><option value="">Selecione</option>
                @unless($isPos??false) @include('components.sales.catalog-options') @endunless
            </select><input type="hidden" class="kind"><input type="hidden" class="item-id"><input type="hidden" class="catalog-seen"></div>
            @if($isPos??false)<div class="sale-measure" hidden><label>Unidade de venda</label><select class="measure"></select><input class="factor-seen" type="hidden"></div>@endif
            <div><label>Quantidade</label><input class="qty" type="number" step="0.001" min="0.001" max="999999" value="1" required></div>
            <div><label class="price-label">Preço unitário (R$)</label><input class="price" type="number" step="0.01" min="0" max="999999999999.99" required></div>
            <div><label>Desconto adicional (%)</label><input class="discount" type="number" step="0.01" min="0" max="100" value="0" required></div>
            <div class="line-total-field"><span class="line-total-label">Líquido do item</span><strong class="line-total">R$ 0,00</strong></div>
            <button type="button" class="text-button remove">Remover</button>
        </div>
        @if($isPos??false)<p class="notice error line-stock-warning" role="status" hidden></p>@endif
        <p class="hint line-discount-note" role="status"></p>
    </div>
</template>
@php($previousItems=session()->hasOldInput('items_present')?old('items',[]):old('items',$initialItems??[]))
@push('scripts')
<script>
window.vendraSaleItems = {
    splitItemTotals: @json((bool)($splitItemTotals??false)),
    isPos: @json((bool)($isPos??false)),
    previous: @json($previousItems),
    allowEmptyItems: @json((bool)($allowEmptyItems??false))
};
</script>
<script src="/sale-items.js?v={{ filemtime(public_path('sale-items.js')) }}"></script>
@endpush
