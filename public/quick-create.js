(() => {
 const dialog=document.querySelector('#quick-create-dialog');if(!dialog)return;
 const form=dialog.querySelector('form'),save=form.querySelector('[type=submit]'),error=form.querySelector('#quick-create-errors'),feedback=document.querySelector('#quick-create-feedback');
 let kind='',targetForm=null,trigger=null,pending=false;
 const section=name=>form.querySelector(`[data-quick-section="${name}"]`);
 function visibility(){
  const item=kind!=='customers',stock=form.querySelector('#quick-stock');
  for(const [name,visible] of [['customer',!item],['item',item],['stock',item&&kind==='products'&&!!stock?.checked]]){const fieldset=section(name);if(fieldset){fieldset.hidden=!visible;fieldset.disabled=!visible;}}
  const stockChoice=form.querySelector('.quick-stock-choice');if(stockChoice)stockChoice.hidden=kind!=='products'||dialog.dataset.inventory!=='1';
  if(stock){stock.disabled=kind!=='products'||dialog.dataset.inventory!=='1';if(stock.disabled)stock.checked=false;}
 }
 document.querySelectorAll('[data-quick-create]').forEach(button=>{
  button.hidden=false;button.addEventListener('click',()=>{
   if(pending)return;kind=button.dataset.quickCreate;targetForm=button.closest('form');trigger=button;form.reset();form.elements.request_key.value=crypto.randomUUID();error.hidden=true;error.replaceChildren();
   const title={customers:'Novo cliente',products:'Novo produto',services:'Novo serviço'}[kind];dialog.querySelector('h2').textContent=title;form.querySelector('#quick-name-label').textContent=kind==='customers'?'Nome / razão social':'Nome do item';
   const stock=form.querySelector('#quick-stock');if(stock)stock.checked=kind==='products'&&dialog.dataset.inventory==='1';visibility();dialog.showModal();form.elements.name.focus();
  });
 });
 form.querySelector('#quick-stock')?.addEventListener('change',visibility);
 dialog.querySelectorAll('.quick-cancel').forEach(button=>button.addEventListener('click',()=>{if(!pending)dialog.close();}));dialog.addEventListener('cancel',event=>{if(pending)event.preventDefault();});
 document.addEventListener('keydown',event=>{if(dialog.open&&['F2','F4','F9'].includes(event.key)){event.preventDefault();event.stopImmediatePropagation();}},true);
 dialog.addEventListener('close',()=>trigger?.focus());
 function showError(messages){error.replaceChildren();const list=document.createElement('ul');for(const text of messages){const li=document.createElement('li');li.textContent=text;list.append(li);}error.append(list);error.hidden=false;error.tabIndex=-1;error.focus();}
 form.addEventListener('submit',async event=>{
  event.preventDefault();if(pending||!form.reportValidity())return;pending=true;save.disabled=true;dialog.querySelectorAll('.quick-cancel').forEach(b=>b.disabled=true);save.textContent='Salvando…';error.hidden=true;
  const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),20000);
  try{
   const response=await fetch(dialog.dataset.endpoint.replace('__kind__',kind),{method:'POST',signal:controller.signal,credentials:'same-origin',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},body:new FormData(form)});
   if(response.redirected||!(response.headers.get('content-type')||'').includes('application/json'))throw new Error('Sua sessão pode ter expirado. Entre novamente em outra aba e tente salvar; o atendimento foi preservado.');
   const result=await response.json();if(!response.ok){showError(result.errors?Object.values(result.errors).flat():[response.status===403?'Seu perfil não permite este cadastro.':response.status===419?'Sua sessão expirou. Entre novamente em outra aba e tente salvar; o atendimento foi preservado.':response.status>=500?'Não foi possível confirmar o cadastro. Tente novamente com os mesmos dados; o atendimento foi preservado.':result.message||'Não foi possível salvar. Revise os dados e tente novamente.']);return;}
   const item=result.item;if(!item||!item.id)throw new Error('Resposta incompleta. Confira o cadastro antes de tentar novamente.');
   if(kind==='customers'){
    const select=targetForm.querySelector('[name=customer_id]');let option=[...select.options].find(o=>o.value===String(item.id));if(!option){option=new Option(item.name,String(item.id));select.add(option);}select.value=String(item.id);select.dispatchEvent(new Event('change',{bubbles:true}));feedback.textContent='Cliente cadastrado e selecionado: '+item.name;
   }else{
    const detail={item,targetForm,used:false};document.dispatchEvent(new CustomEvent('vendra:catalog-created',{detail}));feedback.textContent=(detail.used?'Item cadastrado e adicionado ao atendimento: ':'Item cadastrado; selecione-o numa linha do atendimento: ')+item.name+' · preço aplicado '+Vendra.centsText(Vendra.decimalUnits(item.price,2));
   }
   dialog.close();if(kind==='customers')targetForm.querySelector('[name=customer_id]').focus();
  }catch(e){showError([e.name==='TypeError'||e.name==='AbortError'?'Não foi possível confirmar o cadastro. Confira a conexão e tente novamente com os mesmos dados; o atendimento foi preservado.':e.message||'Não foi possível salvar. Revise os dados e tente novamente.']);}
  finally{clearTimeout(timeout);pending=false;save.disabled=false;save.textContent='Salvar e usar';dialog.querySelectorAll('.quick-cancel').forEach(b=>b.disabled=false);}
 });
})();
