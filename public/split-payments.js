(() => {
 const box=document.querySelector('.split-payments');if(!box)return;
 const form=box.closest('form'),enabled=box.querySelector('.split-enabled'),container=box.querySelector('.split-payment-rows'),add=box.querySelector('.split-add'),balance=box.querySelector('.split-payment-balance');
 let sequence=0,total=BigInt(form.dataset.cartNet||form.dataset.paymentTotal||'0');
 const single=[...form.querySelectorAll('[name="payment_option"],[name="payment_method"],[name="paid"],[name="due_date"],[name="installments"]')];
 function update(event){
  let sum=0n,parts=0,invalid=false;
  for(const row of container.children){
   const field=n=>row.querySelector(`[data-field="${n}"]`),type=field('payment_option').selectedOptions[0]?.dataset.type,paid=field('paid').value==='1';
   row.disabled=!enabled.checked;
   for(const element of row.querySelectorAll('input,select'))element.disabled=!enabled.checked;
   row.querySelectorAll('.split-due').forEach(e=>e.hidden=paid);field('due_date').disabled=!enabled.checked||paid;field('due_date').required=!paid;
   field('installments').disabled=!enabled.checked||paid;
   const cash=paid&&type==='cash';row.querySelector('.split-cash').hidden=!cash;field('cash_received').disabled=!enabled.checked||!cash;
   window.VendraCardFees?.refresh(row.querySelector('.card-fee-fields'),!!event&&event.target?.dataset.field!=='fee_reviewed');
   const amount=Vendra.decimalUnits(field('amount').value,2);sum+=amount||0n;invalid ||= amount===null||amount<=0n;
   parts+=paid?1:Number(field('installments').value);
   field('cash_received').setCustomValidity('');const received=Vendra.decimalUnits(field('cash_received').value,2);
   row.querySelector('.split-change').textContent=cash&&received!==null&&amount!==null?'Troco: '+Vendra.centsText(received-amount):'';
   if(cash&&received!==null&&amount!==null&&received<amount)field('cash_received').setCustomValidity('O dinheiro entregue é menor que esta parte.');
  }
  const difference=total-sum;
  balance.textContent='Distribuído: '+Vendra.centsText(sum)+' · '+(difference>=0n?'Falta distribuir: ':'Excesso: ')+Vendra.centsText(difference>=0n?difference:-difference);
  enabled.setCustomValidity(enabled.checked&&(invalid||difference!==0n||parts>24||container.children.length===0)?'Distribua exatamente o total entre as formas, com até 24 parcelas no conjunto.':'');
  add.disabled=!enabled.checked||container.children.length>=10;
  single.forEach(e=>{e.disabled=enabled.checked;});
  form.dataset.splitPayment=enabled.checked?'1':'0';
  form.querySelectorAll('.single-card-fee').forEach(b=>window.VendraCardFees?.refresh(b));
  const legacyCash=form.querySelector('#pos-cash-received');if(enabled.checked&&legacyCash){legacyCash.disabled=true;legacyCash.setCustomValidity('');}else if(legacyCash)legacyCash.disabled=form.querySelector('#pos-cash-fields').hidden;
 }
 function insert(data={}){
  if(container.children.length>=10)return;
  const row=form.querySelector('.split-payment-template').content.firstElementChild.cloneNode(true),index=sequence++;
  row.querySelectorAll('[data-field]').forEach(e=>{e.name=`payments[${index}][${e.dataset.field}]`;if(e.type==='checkbox')e.checked=String(data[e.dataset.field]??'')==='1';else if(data[e.dataset.field]!==undefined&&data[e.dataset.field]!==null)e.value=data[e.dataset.field];});
  row.querySelector('[data-field="payment_option"]').addEventListener('change',()=>{if(row.querySelector('[data-field="payment_option"]').selectedOptions[0]?.dataset.type==='invoice')row.querySelector('[data-field="paid"]').value='0';update();});
  row.querySelector('[data-field="paid"]').addEventListener('change',()=>{if(row.querySelector('[data-field="payment_option"]').selectedOptions[0]?.dataset.type==='invoice')row.querySelector('[data-field="paid"]').value='0';});
  row.addEventListener('input',update);row.addEventListener('change',update);row.querySelector('.split-remove').addEventListener('click',()=>{row.remove();update();});container.append(row);Vendra.associateLabels(row);window.VendraCardFees?.init(row.querySelector('.card-fee-fields'));update();
 }
 enabled.addEventListener('change',()=>{if(enabled.checked&&!container.children.length)insert();update();});add.addEventListener('click',()=>insert());
 document.addEventListener('vendra:cart-updated',event=>{total=BigInt(event.detail.net);update();});
 form.addEventListener('submit',e=>{update();if(!form.reportValidity())e.preventDefault();});
 (window.vendraSplitInitial||[]).forEach(insert);update();
})();
