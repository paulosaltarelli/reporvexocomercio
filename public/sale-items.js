(() => {
    let sequence=0;
    const {splitItemTotals,isPos,previous,allowEmptyItems}=window.vendraSaleItems;
    const container=document.querySelector('#items');
    const addButton=document.querySelector('#add');
    const templateChoice=document.querySelector('#row').content.querySelector('.choice');
    const catalogSelect=isPos?document.querySelector('#pos-catalog'):templateChoice;
    const catalogIndex=new Map();
    const legacyServices=new Map();
    const exactCodes=new Map();
    const normalize=value=>String(value||'').trim().normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase('pt-BR');
    function indexOption(option){
        if(!option.value)return;
        const {sku,barcode,name,legacyServiceId}=option.dataset;
        if(legacyServiceId)legacyServices.set(legacyServiceId,option);
        const terms=[name,sku,barcode].map(normalize);
        catalogIndex.set(option.value,{option,terms});
        for(const code of new Set(terms.slice(1).filter(Boolean))){
            if(!exactCodes.has(code))exactCodes.set(code,[]);
            exactCodes.get(code).push(option);
        }
    }
    for(const option of catalogSelect.options)indexOption(option);
    function setSelection(choice,option){
        if(isPos)choice.replaceChildren(option?option.cloneNode(true):new Option('Selecione',''));
        choice.value=option?.value||'';
    }
    const picker=document.querySelector('#pos-catalog-dialog');
    let pickerRow=null;
    function openPicker(row){
        pickerRow=row;
        catalogSelect.value=row?.querySelector('.choice').value||'';
        picker.showModal();
        catalogSelect.focus();
    }
    if(isPos){
        picker.querySelector('[data-cancel]').addEventListener('click',()=>picker.close());
        picker.querySelector('[data-select]').addEventListener('click',()=>{
            if(!catalogSelect.value){catalogSelect.focus();return;}
            const row=pickerRow||add();
            const choice=row.querySelector('.choice');
            setSelection(choice,catalogSelect.selectedOptions[0]);
            choice.dispatchEvent(new Event('change'));
            picker.close();
            row.querySelector('.pos-pick-item').focus();
        });
    }
    function percentText(numerator,denominator){
        if(denominator===0n)return '0,0000';
        const units=(numerator*2000000n+denominator)/(denominator*2n);
        return (units/10000n).toString()+','+(units%10000n).toString().padStart(4,'0');
    }
    function updateTotals(){
        let gross=0n,discount=0n,net=0n,products=0n,services=0n;
        container.querySelectorAll('.sale-line-block').forEach(block=>{
            const row=block.querySelector('.sale-row');
            const choice=row.querySelector('.choice');
            const option=choice.selectedOptions[0];
            const price=row.querySelector('.price');
            const explicit=row.querySelector('.discount');
            const note=block.querySelector('.line-discount-note');
            price.setCustomValidity('');
            const quantity=row.querySelector('.qty');
            const factor=row.querySelector('.factor-seen')?.value || '1';
            const scaled=(Vendra.decimalUnits(quantity.value,3) ?? 0n) * (Vendra.decimalUnits(factor,6) ?? 0n);
            quantity.setCustomValidity(scaled % 1000000n ? 'A quantidade convertida exige mais de três casas decimais.' : '');
            const milli=scaled / 1000000n;
            const baseQuantity=String(milli/1000n)+'.'+String(milli%1000n).padStart(3,'0');
            const result=Vendra.commercialLine(baseQuantity,option?.dataset.price||'0',price.value,explicit.value);
            if(!choice.value||!result){row.querySelector('.line-total').textContent='—';note.textContent=choice.value?'Confira quantidade, preço e desconto.':'Selecione um item para conferir preço e desconto permitido.';return;}
            if(option.dataset.itemType==='service') services+=result.net;
            else products+=result.net;
            gross+=result.gross;discount+=result.discount;net+=result.net;
            row.querySelector('.line-total').textContent=Vendra.centsText(result.net);
            const allowed=option.dataset.discountEnabled==='1';
            const limit=allowed?(Vendra.decimalUnits(option.dataset.discountLimit,2)??0n):0n;
            const exceeds=(!allowed&&(result.unitReduction>0n||result.explicit>0n)) || result.explicit>limit || result.unitReduction>result.reference*limit || (result.gross>0n&&result.discount*10000n>result.gross*limit);
            price.setCustomValidity(exceeds?'O desconto total, incluindo a redução do preço unitário e o arredondamento, ultrapassa a permissão deste item.':'');
            note.classList.toggle('line-limit-warning',exceeds);
            const catalog=Vendra.decimalUnits(option.dataset.price,2)??0n;
            note.textContent=(row.querySelector('.measure')?.selectedIndex>0 ? 'Quantidade em estoque: '+baseQuantity+' '+Object.keys(JSON.parse(option.dataset.measures))[0]+' · ' : '')+'Cadastro: '+Vendra.centsText(catalog)+' · '+(allowed?'limite efetivo de '+String(option.dataset.discountLimit).replace('.',',')+'%':'desconto bloqueado')+' · desconto total da prévia: '+percentText(result.discount,result.gross)+'%'+(exceeds?' — confira o preço e o desconto; a prévia ultrapassa o limite.':'.');
        });
        if(splitItemTotals){
            document.querySelector('#product-total').textContent=Vendra.centsText(products);
            document.querySelector('#service-total').textContent=Vendra.centsText(services);
        }
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
        if(isPos){
            const button=row.querySelector('.pos-pick-item');
            button.id='pos-pick-'+index;
            row.querySelector('.selection label').htmlFor=button.id;
            button.addEventListener('click',()=>openPicker(block));
        }
        const discount=row.querySelector('.discount');
        const measure=row.querySelector('.measure');
        const factor=row.querySelector('.factor-seen');
        function chooseUnit(preferred){
            if(!measure)return;
            const units=JSON.parse(choice.selectedOptions[0]?.dataset.measures || '{}');
            measure.replaceChildren(...Object.keys(units).map(code=>new Option(code,code)));
            if(preferred && units[preferred]) measure.value=preferred;
            row.querySelector('.sale-measure').hidden=measure.options.length<2;
            measure.disabled=!measure.value;
            factor.disabled=measure.disabled;
            factor.value=units[measure.value] || '1';
            row.querySelector('.price-label').textContent=measure.value?'Preço por '+Object.keys(units)[0]+' (R$)':'Preço unitário (R$)';
        }
        if(measure){
            measure.name=`items[${index}][measure_unit]`;
            factor.name=`items[${index}][factor_seen]`;
            measure.addEventListener('change',()=>{
                factor.value=JSON.parse(choice.selectedOptions[0].dataset.measures)[measure.value];
                updateTotals();
            });
        }
        function choose(reset){
            chooseUnit(reset?null:data?.measure_unit);
            const [kind,id]=choice.value.split(':');
            const option=choice.selectedOptions[0];
            row.querySelector('.kind').value=kind||'';row.querySelector('.item-id').value=id||'';
            row.querySelector('.catalog-seen').value=option?.dataset.price||'';
            if(isPos){const button=row.querySelector('.pos-pick-item');button.textContent=choice.value?option.textContent:'Selecionar item';button.setAttribute('aria-label',choice.value?'Alterar item: '+option.textContent:'Selecionar item');}
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
            const selected=catalogIndex.get(data.kind+':'+data.id)?.option || (data.kind==='service'?legacyServices.get(String(data.id)):null);
            setSelection(choice,selected);
            row.querySelector('.qty').value=data.quantity;row.querySelector('.price').value=data.offered_unit_price??data.unit_price;discount.value=data.explicit_discount_percent??data.discount_percent??'0';choose(false);
        }
        else choose(true);
        return block;
    }
    document.addEventListener('vendra:catalog-created',event=>{
        if(event.detail.targetForm!==container.closest('form'))return;
        const item=event.detail.item,value='product:'+item.id,template=catalogSelect;
        let option=catalogIndex.get(value)?.option;
        if(!option){option=new Option((item.type==='service'?'Serviço':'Produto')+' · '+item.sku+' · '+item.name+(item.controls_stock?' (saldo '+item.quantity+')':''),value);Object.assign(option.dataset,{itemType:item.type,sku:item.sku,barcode:item.barcode||'',name:item.name,price:item.price,discountEnabled:item.discount_enabled?'1':'0',discountLimit:item.discount_limit});template.add(option);indexOption(option);if(!isPos)container.querySelectorAll('.choice').forEach(choice=>choice.add(option.cloneNode(true)));}
        const empty=[...container.querySelectorAll('.sale-line-block')].find(block=>!block.querySelector('.choice').value);
        if(!empty&&container.children.length>=50)return;
        const block=empty||add();const choice=block.querySelector('.choice');setSelection(choice,option);choice.dispatchEvent(new Event('change'));event.detail.used=true;
    });
    if(isPos){
        const search=document.querySelector('#pos-search'),results=document.querySelector('#pos-results'),status=document.querySelector('#pos-search-status');
        function matches(term,limit){
            const found=[];
            if(term)for(const {option,terms} of catalogIndex.values()){
                if(terms.some(value=>value.includes(term)))found.push(option);
                if(found.length>=limit)break;
            }
            return found;
        }
        function insert(option){
            const existing=[...container.querySelectorAll('.sale-line-block')].find(block=>block.querySelector('.choice').value===option.value && !(block.querySelector('.measure')?.selectedIndex>0));
            if(existing){
                const input=existing.querySelector('.qty'),units=Vendra.decimalUnits(input.value,3);
                if(units===null||units+1000n>999999000n){status.textContent='Confira a quantidade deste item antes de adicionar.';input.focus();return;}
                const next=units+1000n;input.value=String(next/1000n)+'.'+String(next%1000n).padStart(3,'0');input.dispatchEvent(new Event('input'));
            }else{
                const empty=[...container.querySelectorAll('.sale-line-block')].find(block=>!block.querySelector('.choice').value);
                if(!empty&&container.children.length>=50){status.textContent='Limite de 50 itens por venda.';return;}
                const block=empty||add();const choice=block.querySelector('.choice');setSelection(choice,option);choice.dispatchEvent(new Event('change'));
            }
            search.value='';results.replaceChildren();status.textContent='Adicionado: '+option.dataset.name;search.focus();
        }
        search.addEventListener('input',()=>{
            results.replaceChildren();const found=matches(normalize(search.value),8);status.textContent=search.value.trim()?(found.length?'Selecione um item ou pressione Enter para um código exato.':'Nenhum item encontrado.'):'Enter adiciona o item encontrado.';
            found.forEach(option=>{const button=document.createElement('button');button.type='button';button.className='pos-result';button.textContent=option.dataset.name+' · '+option.dataset.sku+' · '+Vendra.centsText(Vendra.decimalUnits(option.dataset.price,2)??0n);button.addEventListener('click',()=>insert(option));results.append(button);});
        });
        search.addEventListener('keydown',event=>{
            if(event.key==='ArrowDown'){const first=results.querySelector('button');if(first){event.preventDefault();first.focus();}return;}
            if(event.key!=='Enter')return;event.preventDefault();const term=normalize(search.value);
            const found=exactCodes.get(term)||matches(term,2);if(found.length===1)insert(found[0]);else status.textContent=found.length?'Selecione um dos itens encontrados.':'Nenhum item encontrado.';
        });
        results.addEventListener('keydown',event=>{if(event.key==='Escape'){results.replaceChildren();search.focus();}});
    }
    addButton.addEventListener('click',()=>{if(container.children.length>=50)return;if(isPos)openPicker(null);else add().querySelector('.choice').focus();});
    const savedRows=Object.values(previous);
    if(savedRows.length)savedRows.forEach(add);else if(!allowEmptyItems&&!isPos)add();
    updateTotals();
})();
