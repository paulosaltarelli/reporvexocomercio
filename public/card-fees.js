(() => {
 const brands=window.vendraCardBrands||{};
 const units=v=>Vendra.decimalUnits(String(v||'0'),2);
 const money=n=>String(n/100n)+'.'+String(n%100n).padStart(2,'0');
 function quote(base,rate,policy){const charged=policy==='pass'?(base*20000n+(10000n-rate))/(2n*(10000n-rate)):base,fee=(charged*rate*2n+10000n)/20000n;return {charged,fee,passed:charged-base,net:charged-fee};}
 function renderTotal(form){
  const base=BigInt(form.dataset.cartNet||form.dataset.paymentTotal||'0');let passed=0n,active=false;
  for(const box of form.querySelectorAll('.card-fee-fields'))if(!box.hidden&&box.dataset.passed){passed+=BigInt(box.dataset.passed);active=true;}
  const grand=form.querySelector('#pos-net-total');
  if(grand)grand.textContent=Vendra.centsText(base+passed);
  const display=form.querySelector('.card-sale-total');if(display){display.hidden=!active;display.textContent='Total a cobrar: '+Vendra.centsText(base+passed)+' · acréscimo repassado: '+Vendra.centsText(passed);}
 }
 function refresh(box,reset=false){
  const form=box.closest('form'),row=box.closest('.split-payment-row'),field=n=>box.querySelector(`[data-field="${n}"]`);
  const planInstallments=row?row.querySelector('[data-field=installments]'):form.querySelector('[name=installments]');if(planInstallments)planInstallments.readOnly=false;
  const type=(row?row.querySelector('[data-field=payment_option]'):form.querySelector('[name=payment_option]'))?.selectedOptions[0]?.dataset.type;
  const available=Object.values(brands).some(b=>b.type===type),active=['credit','debit'].includes(type)&&available&&(row?!row.disabled:form.dataset.splitPayment!=='1');
  box.hidden=!active;box.dataset.passed='';box.querySelectorAll('input,select').forEach(e=>e.disabled=!active);field('card_brand').required=active;
  if(!active){field('fee_reviewed').required=false;renderTotal(form);return;}
  for(const opt of field('card_brand').options)if(opt.value)opt.hidden=brands[opt.value]?.type!==type;
  const brand=brands[field('card_brand').value];let base=row?units(row.querySelector('[data-field=amount]').value):BigInt(form.dataset.cartNet||form.dataset.paymentTotal||'0');
  if(!brand||brand.type!==type||base===null){box.querySelector('.card-fee-preview').textContent='Selecione uma bandeira e confira os valores.';field('fee_reviewed').required=false;renderTotal(form);return;}
  const n=field('card_installments').value,rate=units(brand.rates[n]);
  for(const opt of field('card_installments').options)opt.disabled=brand.rates[opt.value]===undefined;
  field('card_installments').setCustomValidity(brand.rates[n]===undefined?'Parcelamento sem taxa configurada.':'');
  if(rate===null||brand.rates[n]===undefined){box.querySelector('.card-fee-preview').textContent='Configure a taxa para este parcelamento ou escolha outra quantidade de parcelas.';renderTotal(form);return;}
  const policy=field('fee_policy').value,result=quote(base,rate,policy);
  if(field('rate_seen').value!==money(rate)||field('charged_seen').value!==money(result.charged))field('fee_reviewed').checked=false;
  field('rate_seen').value=money(rate);field('charged_seen').value=money(result.charged);
  if(reset)field('fee_reviewed').checked=false;
  box.querySelector('.card-fee-review').hidden=policy!=='pass';field('fee_reviewed').required=policy==='pass';field('fee_reviewed').disabled=policy!=='pass';
  box.querySelector('.card-fee-preview').textContent='Taxa '+money(rate).replace('.',',')+'% · custo previsto: '+Vendra.centsText(result.fee)+' · cliente paga: '+Vendra.centsText(result.charged)+' · líquido previsto para a loja: '+Vendra.centsText(result.net);
  box.dataset.passed=String(result.passed);
  const paid=(row?row.querySelector('[data-field=paid]'):form.querySelector('[name=paid]'));if(planInstallments&&paid?.value==='0'){planInstallments.value=n;planInstallments.readOnly=true;}
  renderTotal(form);
 }
 function init(box){
  box.querySelectorAll('input,select').forEach(e=>e.addEventListener('change',()=>{
   if(e.dataset.field==='card_brand'){
    const brand=brands[e.value];if(brand){box.querySelector('[data-field=fee_policy]').value=brand.policy;box.querySelector('[data-field=card_installments]').value=Object.keys(brand.rates)[0];}
   }
   refresh(box,e.dataset.field!=='fee_reviewed');
  }));refresh(box);
 }
 window.VendraCardFees={refresh,init,renderTotal,quote};
 document.querySelectorAll('.single-card-fee').forEach(box=>{
  init(box);const form=box.closest('form');form.querySelector('[name=payment_option]')?.addEventListener('change',()=>refresh(box,true));
  document.addEventListener('vendra:cart-updated',()=>refresh(box,true));form.addEventListener('change',e=>{if(e.target.matches('.split-enabled'))refresh(box);else if(e.target.name==='paid')refresh(box,true);});
 });
})();
