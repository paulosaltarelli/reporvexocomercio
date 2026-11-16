/* Front-of-counter interactions; checkout totals and permissions remain server-authoritative. */
(() => {
    const form=document.querySelector('#checkout-form');
    if(!form)return;
    const button=form.querySelector('#checkout-submit'),search=form.querySelector('#pos-search');
    const method=form.querySelector('[name="payment_option"]'),paid=form.querySelector('[name="paid"]');
    const cash=form.querySelector('#pos-cash-received'),cashFields=form.querySelector('#pos-cash-fields');
    const creditFields=form.querySelector('#pos-credit-fields'),due=form.querySelector('[name="due_date"]');
    const initiallyDisabled=button.disabled;
    let totals={gross:form.dataset.cartGross||'0',discount:form.dataset.cartDiscount||'0',net:form.dataset.cartNet||'0'};
    function cashPreview(){
        cash.setCustomValidity('');
        if(form.dataset.splitPayment==='1')return;
        const received=Vendra.decimalUnits(cash.value,2),net=BigInt(totals.net);
        let text='—';
        if(!cashFields.hidden&&cash.value!==''){
            if(received===null)cash.setCustomValidity('Informe o valor entregue com até duas casas decimais.');
            else if(received<net){cash.setCustomValidity('O valor entregue é menor que o total da venda.');text='Valor insuficiente';}
            else text=Vendra.centsText(received-net);
        }
        form.querySelector('#pos-change').textContent=text;
    }
    function refreshPayment(){
        creditFields.hidden=paid.value!=='0';
        due.required=paid.value==='0';
        cashFields.hidden=paid.value!=='1'||(method.selectedOptions[0]?.dataset.type??method.value)!=='cash';
        cash.disabled=cashFields.hidden;
        cashPreview();
    }
    function refreshTotals(){
        form.querySelector('#pos-gross-total').textContent=Vendra.centsText(BigInt(totals.gross));
        form.querySelector('#pos-discount-total').textContent=Vendra.centsText(BigInt(totals.discount));
        form.querySelector('#pos-net-total').textContent=Vendra.centsText(BigInt(totals.net));
        cashPreview();
    }
    document.addEventListener('vendra:cart-updated',event=>{totals=event.detail;refreshTotals();});
    method.addEventListener('change',()=>{
        if((method.selectedOptions[0]?.dataset.type??method.value)==='invoice'){paid.value='0';paid.dispatchEvent(new Event('change'));}
        refreshPayment();
    });
    paid.addEventListener('change',refreshPayment);cash.addEventListener('input',cashPreview);
    form.addEventListener('keydown',event=>{
        if(event.key==='Enter'&&event.target.matches('#items input')){event.preventDefault();search.focus();}
    });
    form.addEventListener('submit',event=>{
        if(form.dataset.sending==='1'){event.preventDefault();return;}
        const selected=form.querySelector('#items .choice:valid');
        if(!selected){event.preventDefault();form.querySelector('#pos-search-status').textContent='Adicione pelo menos um item à venda.';search.focus();return;}
        // Hidden fields inside a collapsed disclosure still participate in validation.
        if(!form.reportValidity()){event.preventDefault();return;}
        form.dataset.sending='1';button.disabled=true;button.textContent='Registrando venda…';
    });
    form.addEventListener('invalid',event=>{
        for(let node=event.target.parentElement;node&&node!==form;node=node.parentElement){if(node.tagName==='DETAILS')node.open=true;}
    },true);
    document.addEventListener('keydown',event=>{
        if(event.altKey||event.ctrlKey||event.metaKey||event.defaultPrevented||form.closest('[inert]'))return;
        if(event.key==='F2'){event.preventDefault();search.focus();search.select();}
        if(event.key==='F4'){event.preventDefault();method.focus();}
        if(event.key==='F9'){event.preventDefault();if(!button.disabled)form.requestSubmit(button);}
    });
    window.addEventListener('pageshow',()=>{delete form.dataset.sending;button.disabled=initiallyDisabled;button.innerHTML='Registrar venda <kbd>F9</kbd>';});
    const customer=form.querySelector('[name="customer_id"]'),seller=form.querySelector('[name="salesperson_id"]');
    function ownerSummary(){
        const client=customer.selectedOptions[0]?.textContent||'Consumidor final';
        const name=seller?.tagName==='SELECT'?seller.selectedOptions[0]?.textContent:form.querySelector('#salesperson_id')?.value;
        form.querySelector('.pos-customer-summary').textContent=client+' · '+(name||'Selecione um vendedor');
    }
    customer.addEventListener('change',ownerSummary);seller?.addEventListener('change',ownerSummary);
    refreshPayment();refreshTotals();
    if(!document.querySelector('#validation-errors'))search?.focus({preventScroll:true});
})();
