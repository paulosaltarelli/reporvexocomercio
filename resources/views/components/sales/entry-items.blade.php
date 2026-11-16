<section class="panel {{ ($isPos??false)?'pos-items-panel':'' }}">
    <h2>{{ $itemsHeading??'Itens da venda' }}</h2>
    @if($isPos??false)
    <div class="pos-search-field"><label for="pos-search">Produto, serviço ou código de barras <kbd>F2</kbd></label><input id="pos-search" type="search" autocomplete="off" placeholder="Leia o código ou digite o nome do item" maxlength="200" autofocus><p id="pos-search-status" class="hint" role="status" aria-live="polite">Enter adiciona o item encontrado.</p><div id="pos-results" class="pos-results" aria-label="Itens encontrados"></div></div>
    <p id="pos-empty-cart" class="pos-empty-cart">Busque ou leia o primeiro item para começar a venda.</p>
    @else
    <p class="hint">Reduzir o preço unitário também conta como desconto. O limite é conferido pelo servidor contra o preço de cadastro.</p>
    @endif
    <input type="hidden" name="items_present" value="1">
    <div id="items"></div>
    <button type="button" id="add" class="button secondary">+ Adicionar item</button>
    <div class="sale-totals" role="status" aria-live="polite"><div><span>Bruto</span><strong id="gross-total">R$ 0,00</strong></div><div><span>Descontos</span><strong id="discount-total">R$ 0,00</strong></div><div class="sale-net-total"><span>Total líquido</span><strong id="total">R$ 0,00</strong></div></div>
    <p class="hint">Prévia por item, com arredondamento em centavos. Os valores definitivos são calculados na confirmação.</p>
</section>
<template id="row">
    <div class="sale-line-block">
        <div class="sale-row commercial-sale-row">
            <div class="selection"><label>Produto ou serviço</label><select class="choice" required><option value="">Selecione</option>
                @foreach($products as $product)
                    @php($isService=($product->type??'product')==='service')
                    @php($stockControlled=!$isService && ($product->controls_stock??true))
                    <option value="product:{{ $product->id }}" data-sku="{{ $product->sku }}" data-barcode="{{ $product->barcode }}" data-name="{{ $product->name }}" data-legacy-service-id="{{ $product->legacy_service_id??'' }}" data-price="{{ $product->sale_price }}" data-discount-enabled="{{ $product->discountEnabled()?'1':'0' }}" data-discount-limit="{{ $product->maxDiscountPercent() }}">{{ $isService?'Serviço':'Produto' }} · {{ $product->sku }} · {{ $product->name }}@if($stockControlled) (saldo {{ number_format($product->quantity,3,',','.') }})@endif</option>
                @endforeach
            </select><input type="hidden" class="kind"><input type="hidden" class="item-id"><input type="hidden" class="catalog-seen"></div>
            <div><label>Quantidade</label><input class="qty" type="number" step="0.001" min="0.001" max="999999" value="1" required></div>
            <div><label>Preço unitário (R$)</label><input class="price" type="number" step="0.01" min="0" max="999999999999.99" required></div>
            <div><label>Desconto adicional (%)</label><input class="discount" type="number" step="0.01" min="0" max="100" value="0" required></div>
            <div class="line-total-field"><span class="line-total-label">Líquido do item</span><strong class="line-total">R$ 0,00</strong></div>
            <button type="button" class="text-button remove">Remover</button>
        </div>
        <p class="hint line-discount-note" role="status"></p>
    </div>
</template>
@push('scripts')
<script>
(() => {
    let sequence=0;
    const isPos=@json((bool)($isPos??false));
    const previous=Object.values(@json(session()->hasOldInput('items_present')?old('items',[]):old('items',$initialItems??[])));
    const allowEmptyItems=@json((bool)($allowEmptyItems??false));
    const container=document.querySelector('#items');
    const addButton=document.querySelector('#add');
    function percentText(numerator,denominator){
        if(denominator===0n)return '0,0000';
        const units=(numerator*2000000n+denominator)/(denominator*2n);
        return (units/10000n).toString()+','+(units%10000n).toString().padStart(4,'0');
    }
    function updateTotals(){
        let gross=0n,discount=0n,net=0n;
        container.querySelectorAll('.sale-line-block').forEach(block=>{
            const row=block.querySelector('.sale-row');
            const choice=row.querySelector('.choice');
            const option=choice.selectedOptions[0];
            const price=row.querySelector('.price');
            const explicit=row.querySelector('.discount');
            const note=block.querySelector('.line-discount-note');
            price.setCustomValidity('');
            const result=Vendra.commercialLine(row.querySelector('.qty').value,option?.dataset.price||'0',price.value,explicit.value);
            if(!choice.value||!result){row.querySelector('.line-total').textContent='—';note.textContent=choice.value?'Confira quantidade, preço e desconto.':'Selecione um item para conferir preço e desconto permitido.';return;}
            gross+=result.gross;discount+=result.discount;net+=result.net;
            row.querySelector('.line-total').textContent=Vendra.centsText(result.net);
            const allowed=option.dataset.discountEnabled==='1';
            const limit=allowed?(Vendra.decimalUnits(option.dataset.discountLimit,2)??0n):0n;
            const exceeds=(!allowed&&(result.unitReduction>0n||result.explicit>0n)) || result.explicit>limit || result.unitReduction>result.reference*limit || (result.gross>0n&&result.discount*10000n>result.gross*limit);
            price.setCustomValidity(exceeds?'O desconto total, incluindo a redução do preço unitário e o arredondamento, ultrapassa a permissão deste item.':'');
            note.classList.toggle('line-limit-warning',exceeds);
            const catalog=Vendra.decimalUnits(option.dataset.price,2)??0n;
            note.textContent='Cadastro: '+Vendra.centsText(catalog)+' · '+(allowed?'limite efetivo de '+String(option.dataset.discountLimit).replace('.',',')+'%':'desconto bloqueado')+' · desconto total da prévia: '+percentText(result.discount,result.gross)+'%'+(exceeds?' — confira o preço e o desconto; a prévia ultrapassa o limite.':'.');
        });
        document.querySelector('#gross-total').textContent=Vendra.centsText(gross);
        document.querySelector('#discount-total').textContent=Vendra.centsText(discount);
        document.querySelector('#total').textContent=Vendra.centsText(net);
        if(isPos){document.querySelector('#pos-empty-cart').hidden=container.children.length>0;const totals={gross:String(gross),discount:String(discount),net:String(net)};const form=container.closest('form');form.dataset.cartGross=totals.gross;form.dataset.cartDiscount=totals.discount;form.dataset.cartNet=totals.net;document.dispatchEvent(new CustomEvent('vendra:cart-updated',{detail:totals}));}
    }
    function add(data){
        const block=document.querySelector('#row').content.firstElementChild.cloneNode(true);
        const row=block.querySelector('.sale-row');
        const index=sequence++;
        [['kind','kind'],['item-id','id'],['qty','quantity'],['price','unit_price'],['discount','discount_percent'],['catalog-seen','catalog_price_seen']].forEach(([selector,field])=>row.querySelector('.'+selector).name=`items[${index}][${field}]`);
        const choice=row.querySelector('.choice');
        const discount=row.querySelector('.discount');
        function choose(reset){
            const [kind,id]=choice.value.split(':');
            const option=choice.selectedOptions[0];
            row.querySelector('.kind').value=kind||'';row.querySelector('.item-id').value=id||'';
            row.querySelector('.catalog-seen').value=option?.dataset.price||'';
            const allowed=option?.dataset.discountEnabled==='1';
            discount.readOnly=!allowed;
            discount.max=allowed?(option.dataset.discountLimit||'0'):'0';
            if(reset){row.querySelector('.price').value=option?.dataset.price||'';discount.value='0';}
            if(!allowed)discount.value='0';
            updateTotals();
        }
        choice.addEventListener('change',()=>choose(true));
        row.querySelectorAll('input').forEach(input=>input.addEventListener('input',updateTotals));
        row.querySelector('.remove').addEventListener('click',()=>{block.remove();updateTotals();addButton.focus();});
        container.append(block);Vendra.associateLabels(block);
        if(data){
            choice.value=data.kind+':'+data.id;
            if(!choice.value && data.kind==='service'){
                const mapped=Array.from(choice.options).find(option=>option.dataset.legacyServiceId===String(data.id));
                if(mapped)choice.value=mapped.value;
            }
            row.querySelector('.qty').value=data.quantity;row.querySelector('.price').value=data.offered_unit_price??data.unit_price;discount.value=data.explicit_discount_percent??data.discount_percent??'0';choose(false);
        }
        else choose(true);
        return block;
    }
    if(isPos){
        const search=document.querySelector('#pos-search'),results=document.querySelector('#pos-results'),status=document.querySelector('#pos-search-status');
        const catalog=[...document.querySelector('#row').content.querySelectorAll('.choice option')].filter(option=>option.value);
        const normalize=value=>String(value||'').trim().normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase('pt-BR');
        function matches(){const term=normalize(search.value);return term?catalog.filter(option=>[option.dataset.name,option.dataset.sku,option.dataset.barcode].some(value=>normalize(value).includes(term))):[];}
        function insert(option){
            const existing=[...container.querySelectorAll('.sale-line-block')].find(block=>block.querySelector('.choice').value===option.value);
            if(existing){
                const input=existing.querySelector('.qty'),units=Vendra.decimalUnits(input.value,3);
                if(units===null||units+1000n>999999000n){status.textContent='Confira a quantidade deste item antes de adicionar.';input.focus();return;}
                const next=units+1000n;input.value=String(next/1000n)+'.'+String(next%1000n).padStart(3,'0');input.dispatchEvent(new Event('input'));
            }else{
                const empty=[...container.querySelectorAll('.sale-line-block')].find(block=>!block.querySelector('.choice').value);
                if(!empty&&container.children.length>=50){status.textContent='Limite de 50 itens por venda.';return;}
                const block=empty||add();const choice=block.querySelector('.choice');choice.value=option.value;choice.dispatchEvent(new Event('change'));
            }
            search.value='';results.replaceChildren();status.textContent='Adicionado: '+option.dataset.name;search.focus();
        }
        search.addEventListener('input',()=>{
            results.replaceChildren();const found=matches();status.textContent=search.value.trim()?(found.length?'Selecione um item ou pressione Enter para um código exato.':'Nenhum item encontrado.'):'Enter adiciona o item encontrado.';
            found.slice(0,8).forEach(option=>{const button=document.createElement('button');button.type='button';button.className='pos-result';button.textContent=option.dataset.name+' · '+option.dataset.sku+' · '+Vendra.centsText(Vendra.decimalUnits(option.dataset.price,2)??0n);button.addEventListener('click',()=>insert(option));results.append(button);});
        });
        search.addEventListener('keydown',event=>{
            if(event.key==='ArrowDown'){const first=results.querySelector('button');if(first){event.preventDefault();first.focus();}return;}
            if(event.key!=='Enter')return;event.preventDefault();const term=normalize(search.value);
            const exact=term?catalog.filter(option=>[option.dataset.sku,option.dataset.barcode].some(value=>value&&normalize(value)===term)):[];
            const found=exact.length?exact:matches();if(found.length===1)insert(found[0]);else status.textContent=found.length?'Selecione um dos itens encontrados.':'Nenhum item encontrado.';
        });
        results.addEventListener('keydown',event=>{if(event.key==='Escape'){results.replaceChildren();search.focus();}});
    }
    addButton.addEventListener('click',()=>{if(container.children.length<50){const block=add();block.querySelector('.choice').focus();}});
    if(previous.length)previous.forEach(add);else if(!allowEmptyItems&&!isPos)add();
    updateTotals();
})();
</script>
@endpush
