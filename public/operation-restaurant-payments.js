(() => {
    const units = (value,scale=2) => window.Vendra?.decimalUnits(String(value||''),scale) ?? null;
    const money = cents => (cents/100n).toLocaleString('pt-BR') + ',' + String(cents%100n).padStart(2,'0');
    const decimal = cents => String(cents/100n)+'.'+String(cents%100n).padStart(2,'0');
    document.querySelectorAll('[data-restaurant-partial]').forEach(form => {
        const allocation=form.querySelector('[data-partial-allocation]'), person=form.querySelector('[data-partial-person]'), items=form.querySelector('[data-partial-items]');
        const amountGroup=form.querySelector('[data-partial-amount]'), amount=amountGroup.querySelector('input'), share=person.querySelector('select');
        const method=form.querySelector('[name="payment_option"]'), cash=form.querySelector('[data-partial-cash]'), tendered=cash.querySelector('input');
        const rows=[...items.querySelectorAll('[data-partial-item]')], globalMaximum=units(amount.max);
        const itemChoice=allocation.querySelector('option[value="items"]');
        if (itemChoice) itemChoice.disabled=!rows.length;
        const personChoice=allocation.querySelector('option[value="person"]');if(personChoice)personChoice.disabled=share.options.length<2;
        [...method.options].filter(option=>option.dataset.type==='invoice').forEach(option=>{option.disabled=true;option.hidden=true;});
        const total=()=>{
            if(allocation.value!=='items')return units(amount.value)||0n;
            return rows.reduce((sum,row)=>{
                if(!row.querySelector('[data-partial-item-selected]').checked)return sum;
                const quantity=units(row.querySelector('[data-partial-item-quantity]').value,3), totalQuantity=units(row.dataset.itemQuantity,3), totalAmount=units(row.dataset.itemTotal), remaining=units(row.dataset.itemRemaining,3), remainingAmount=units(row.dataset.itemRemainingAmount);
                if(quantity===null||totalQuantity===null||totalQuantity<=0n||totalAmount===null||quantity<=0n)return sum;
                return sum+(quantity===remaining&&remainingAmount!==null?remainingAmount:(totalAmount*quantity*2n+totalQuantity)/(2n*totalQuantity));
            },0n);
        };
        const refresh=(fillShare=false)=>{
            const byPerson=allocation.value==='person', byItems=allocation.value==='items';
            person.hidden=!byPerson;share.disabled=!byPerson;share.required=byPerson;
            items.hidden=!byItems;amountGroup.hidden=byItems;amount.disabled=byItems;amount.required=!byItems;
            if(byPerson){const balance=units(share.selectedOptions[0]?.dataset.shareBalance);if(balance!==null){amount.max=decimal(globalMaximum===null||balance<globalMaximum?balance:globalMaximum);if(fillShare)amount.value=decimal(balance);}else amount.max=globalMaximum===null?'':decimal(globalMaximum);}
            else if(globalMaximum!==null)amount.max=decimal(globalMaximum);
            rows.forEach(row=>{
                const checked=row.querySelector('[data-partial-item-selected]').checked;
                row.querySelector('[data-partial-item-selected]').disabled=!byItems;
                row.querySelectorAll('[name]').forEach(input=>{input.disabled=!byItems||!checked;if(input.matches('[data-partial-item-quantity]'))input.required=byItems&&checked;});
            });
            const base=total();
            allocation.setCustomValidity(byItems&&base<=0n?'Selecione os itens e as quantidades que serão recebidos.':byItems&&globalMaximum!==null&&base>globalMaximum?'Os itens escolhidos ultrapassam o saldo da comanda. Confira as quantidades e os recebimentos anteriores.':'');
            form.dataset.paymentTotal=String(base);
            form.querySelector('[data-partial-total]').textContent='Prévia do consumo: R$ '+money(base)+'. O servidor confere o saldo antes de registrar.';
            const cashActive=method.selectedOptions[0]?.dataset.type==='cash';cash.hidden=!cashActive;tendered.disabled=!cashActive;
            const received=units(tendered.value), change=received===null?null:received-base;
            form.querySelector('[data-partial-change]').textContent=received===null?'':change!==null&&change>=0n?'Troco previsto: R$ '+money(change):'O dinheiro entregue precisa cobrir o valor recebido.';
            tendered.setCustomValidity(cashActive&&received!==null&&received<base?'Confira o dinheiro entregue.':'');
            const fees=form.querySelector('.card-fee-fields');
            if(fees&&window.VendraCardFees)window.VendraCardFees.refresh(fees,true);
        };
        allocation.addEventListener('change',()=>refresh(true));share.addEventListener('change',()=>refresh(true));
        amount.addEventListener('input',()=>refresh());method.addEventListener('change',()=>refresh());tendered.addEventListener('input',()=>refresh());
        rows.forEach(row=>row.addEventListener('change',()=>refresh()));
        refresh();
    });
    document.querySelectorAll('[data-restaurant-division]').forEach(form=>{
        const count=form.querySelector('[data-division-count]'), names=form.querySelector('[data-division-names]');
        let initial=[];try{const saved=JSON.parse(form.dataset.divisionInitial||'[]');if(Array.isArray(saved))initial=saved.slice(0,100).map(value=>typeof value==='string'?value.slice(0,100):'');}catch(_){}
        const refresh=()=>{
            const current=[...names.querySelectorAll('input')].map(input=>input.value), previous=current.length?current:initial;
            const total=Math.min(100,Math.max(1,Number(count.value)||1));
            names.replaceChildren();
            for(let index=0;index<total;index++){
                const field=document.createElement('div'),label=document.createElement('label'),input=document.createElement('input');
                field.className='field';input.name='names[]';input.id='division-name-'+index;input.maxLength=100;input.value=previous[index]||'';input.placeholder='Pessoa '+(index+1);label.htmlFor=input.id;label.textContent='Nome '+(index+1)+' (opcional)';field.append(label,input);names.append(field);
            }
        };
        count.addEventListener('change',refresh);refresh();
    });
})();
