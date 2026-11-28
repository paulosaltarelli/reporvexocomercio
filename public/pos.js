/* Front-of-counter interactions; checkout totals and permissions remain server-authoritative. */
(() => {
    const form=document.querySelector('#checkout-form');
    if(!form)return;
    const button=form.querySelector('#checkout-submit'),search=form.querySelector('#pos-search');
    const method=form.querySelector('[name="payment_option"]'),paid=form.querySelector('[name="paid"]');
    const cash=form.querySelector('#pos-cash-received'),cashFields=form.querySelector('#pos-cash-fields');
    const creditFields=form.querySelector('#pos-credit-fields'),due=form.querySelector('[name="due_date"]');
    const initiallyDisabled=button.disabled;
    const paymentDialog = form.querySelector('#pos-payment-dialog');
    const startDialog = form.querySelector('#pos-start-dialog');
    const staged = typeof paymentDialog?.showModal === 'function' && typeof startDialog?.showModal === 'function';
    const stageSummary = form.querySelector('.pos-stage-summary');
    const startButton = form.querySelector('[data-pos-start]');
    const startConfirm = form.querySelector('[data-pos-start-confirm]');
    const startWithoutNote = form.querySelector('[data-pos-start-without-note]');
    const startCpf = form.querySelector('#pos-start-cpf');
    const cpf = form.querySelector('#checkout-nfce-cpf');
    const issue = form.querySelector('[name="issue_nfce"]');
    const pixMode = form.querySelector('[name="nfce_pix_mode"]');
    const paymentError = form.querySelector('[data-pos-fiscal-payment-error]');
    let started = false;

    function paymentType() {
        return method.selectedOptions[0]?.dataset.type ?? method.value;
    }

    function fiscalPayment() {
        if (!staged || !issue) return true;
        const split = form.dataset.splitPayment === '1';
        const splitOptions = [...form.querySelectorAll('.split-payment-row [data-field="payment_option"]')];
        const types = split ? splitOptions.map(select => select.selectedOptions[0]?.dataset.type ?? select.value) : [paymentType()];
        const pix = issue.checked && types.includes('pix');
        pixMode.closest('[data-pos-fiscal-pix]').hidden = !pix;
        pixMode.required = pix;
        pixMode.disabled = !pix;
        const unsupported = issue.checked && types.some(type => ['credit', 'debit', 'invoice'].includes(type));
        paymentError.hidden = !unsupported;
        paymentError.textContent = unsupported ? 'Para emitir NFC-e neste piloto, use dinheiro, Pix ou transferência. Para registrar cartão, volte aos itens com Esc e use F8 para continuar sem NFC-e.' : '';
        button.disabled = initiallyDisabled || unsupported;
        return !unsupported;
    }

    function openStart() {
        startCpf.value = cpf?.value ?? '';
        startDialog.showModal();
        (cpf ? startCpf : startConfirm).focus();
        if (cpf) startCpf.select();
    }

    function finishStart(withNote) {
        if (issue) {
            issue.checked = withNote;
            cpf.value = withNote ? startCpf.value.trim() : '';
        }
        started = true;
        startDialog.close();
        startButton.textContent = issue ? 'Alterar CPF ou NFC-e (F8)' : 'Continuar lançamento';
        stageSummary.querySelector('[data-pos-fiscal-summary]').textContent = issue?.checked
            ? (cpf.value ? 'NFC-e com CPF informado' : 'NFC-e sem CPF informado') : 'Venda sem emissão automática';
        search.focus();
        fiscalPayment();
    }

    function itemsValid() {
        const selected = [...form.querySelectorAll('#items .choice')];
        if (!selected.length || selected.some(choice => !choice.value)) {
            form.querySelector('#pos-search-status').textContent = 'Adicione pelo menos um item à venda.';
            (selected.find(choice => !choice.value)?.parentElement.querySelector('.pos-pick-item') || search).focus();
            return false;
        }
        return [...form.querySelectorAll('#items input')].every(input => input.reportValidity());
    }

    function openPayment() {
        if (initiallyDisabled || !itemsValid()) return;
        paymentDialog.showModal();
        fiscalPayment();
        if (form.dataset.splitPayment === '1') {
            (form.querySelector('.split-payment-row [data-field="payment_option"]') || form.querySelector('.split-add')).focus();
        } else {
            method.focus();
        }
    }

    if (staged) {
        paymentDialog.append(form.querySelector('[data-pos-payment-panel]'));
        form.querySelector('[data-pos-back]').hidden = false;
        form.querySelectorAll('[data-pos-inline-cpf], [data-pos-inline-issue]').forEach(field => field.hidden = true);
        stageSummary.hidden = false;
        startDialog.querySelector('[data-pos-start-fiscal]').hidden = !cpf;
        startWithoutNote.hidden = !cpf;
        if (cpf) startConfirm.innerHTML = 'Iniciar com NFC-e <kbd>Enter</kbd>';
        startButton.addEventListener('click', openStart);
        startConfirm.addEventListener('click', () => finishStart(Boolean(cpf)));
        startWithoutNote.addEventListener('click', () => finishStart(false));
        startCpf.addEventListener('keydown', event => {
            if (event.key === 'Enter' && !event.isComposing) {
                event.preventDefault();
                finishStart(true);
            }
        });
        startDialog.addEventListener('cancel', event => {
            event.preventDefault();
            if (!started) finishStart(false);
            else startDialog.close();
        });
        form.querySelector('[data-pos-payment]').addEventListener('click', openPayment);
        form.querySelector('[data-pos-back]').addEventListener('click', () => paymentDialog.close());
        paymentDialog.addEventListener('close', () => search.focus());
        form.addEventListener('change', fiscalPayment);
    }

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
        window.VendraCardFees.renderTotal(form);
        if (staged) stageSummary.querySelector('[data-pos-stage-total]').textContent = form.querySelector('#pos-net-total').textContent;
        fiscalPayment();
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
        if (staged && !paymentDialog.open) {
            event.preventDefault();
            openPayment();
            return;
        }
        if (!itemsValid() || !fiscalPayment()) { event.preventDefault(); return; }
        // Hidden fields inside a collapsed disclosure still participate in validation.
        if(!form.reportValidity()){event.preventDefault();return;}
        form.dataset.sending='1';button.disabled=true;button.textContent='Registrando venda…';
    });
    form.addEventListener('invalid',event=>{
        for(let node=event.target.parentElement;node&&node!==form;node=node.parentElement){if(node.tagName==='DETAILS')node.open=true;}
    },true);
    document.addEventListener('keydown',event=>{
        if(event.altKey||event.ctrlKey||event.metaKey||event.defaultPrevented||form.closest('[inert]'))return;
        const activeDialog = document.querySelector('dialog[open]');
        if (activeDialog && activeDialog !== paymentDialog) return;
        if (paymentDialog?.open && event.key !== 'F9') return;
        if(event.key==='F2'){event.preventDefault();search.focus();search.select();}
        if(event.key==='F4'){
            event.preventDefault();
            if (staged) { openPayment(); return; }
            if(form.dataset.splitPayment==='1'){
                const split=form.querySelector('.split-payments');
                split.open=true;
                (split.querySelector('.split-payment-row [data-field="payment_option"]')||split.querySelector('.split-add')).focus();
            }else method.focus();
        }
        if (event.key === 'F8') {
            const cpf = form.querySelector('#checkout-nfce-cpf');
            if (cpf) {
                event.preventDefault();
                if (staged) openStart();
                else { cpf.focus(); cpf.select(); }
            }
        }
        if (event.key === 'F9') {
            event.preventDefault();
            if (event.repeat) return;
            if (staged && !paymentDialog.open) openPayment();
            else if (!button.disabled) form.requestSubmit(button);
        }
    });
    window.addEventListener('pageshow',()=>{delete form.dataset.sending;button.disabled=initiallyDisabled;button.innerHTML='Registrar venda e recebimento <kbd>F9</kbd>';fiscalPayment();});
    const customer=form.querySelector('[name="customer_id"]'),seller=form.querySelector('[name="salesperson_id"]');
    function ownerSummary(){
        const client=customer.selectedOptions[0]?.textContent||'Consumidor final';
        const name=seller?.tagName==='SELECT'?seller.selectedOptions[0]?.textContent:form.querySelector('#salesperson_id')?.value;
        form.querySelector('.pos-customer-summary').textContent=client+' · '+(name||'Selecione um vendedor');
    }
    customer.addEventListener('change',ownerSummary);seller?.addEventListener('change',ownerSummary);
    refreshPayment();refreshTotals();
    if (!document.querySelector('#validation-errors')) {
        if (staged && !initiallyDisabled && !form.querySelector('#items .choice')) openStart();
        else search.focus({preventScroll: true});
    } else if (staged) {
        started = true;
        if (issue?.checked) stageSummary.querySelector('[data-pos-fiscal-summary]').textContent = cpf.value ? 'NFC-e com CPF informado' : 'NFC-e sem CPF informado';
    }
})();
