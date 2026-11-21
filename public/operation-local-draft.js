(() => {
    const panel = document.querySelector('[data-offline-draft]');
    const form = document.querySelector('.operation-scanner');
    if (!panel || !form) return;
    const code = form.querySelector('[name="code"]'), quantity = form.querySelector('[name="quantity"]');
    const send = panel.querySelector('[data-draft-send]'), clear = panel.querySelector('[data-draft-clear]');
    const onlineOnlyButton=panel.querySelector('[data-draft-online-only]');
    const list = panel.querySelector('[data-draft-lines]'), count = panel.querySelector('[data-draft-count]'), status = panel.querySelector('[data-draft-status]');
    const key = 'vendra:local-readings:' + panel.dataset.draftScope;
    const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
    const uuid = () => {
        if (crypto.randomUUID) return crypto.randomUUID();
        const bytes = crypto.getRandomValues(new Uint8Array(16)); bytes[6]=(bytes[6]&15)|64; bytes[8]=(bytes[8]&63)|128;
        const hex=[...bytes].map(value=>value.toString(16).padStart(2,'0')).join('');
        return [hex.slice(0,8),hex.slice(8,12),hex.slice(12,16),hex.slice(16,20),hex.slice(20)].join('-');
    };
    const validQuantity = value => typeof value === 'string' && /^\d{1,6}(?:\.\d{1,3})?$/.test(value) && Number(value)>0 && Number(value)<=999999;
    let state = {request_key:null, version:Number(panel.dataset.draftVersion),items:[]}, storageReady = true, broken = false, sending = false, denied = false, feedback = '', safeReload = false, storageUnavailable = false, corrupt = false, clearFailed = false, onlineOnly = false;
    const checkoutButtons = new Map([...document.querySelectorAll('[data-operation-checkout] button[type="submit"]')].map(button=>[button,button.disabled]));
    let raw = null;
    try { raw=sessionStorage.getItem(key); }
    catch (_) { storageUnavailable=true;storageReady=false;broken=true; }
    if (!storageUnavailable) {
        try {
            const saved=JSON.parse(raw||'null');
            if (saved) {
                if (!Array.isArray(saved.items) || saved.items.length>200 || !Number.isInteger(saved.version) || saved.version<0 || !uuidPattern.test(saved.request_key) || saved.items.some(item=>!item || !uuidPattern.test(item.request_key) || typeof item.code!=='string' || !item.code.trim() || item.code.length>80 || !validQuantity(item.quantity)) || new Set(saved.items.map(item=>item.request_key)).size!==saved.items.length) throw new Error('Invalid local draft');
                state={request_key:saved.request_key,version:Number(panel.dataset.draftVersion),items:saved.items.map(({request_key,code,quantity})=>({request_key,code,quantity}))};
            }
        } catch (_) {corrupt=true;broken=true;storageReady=false;}
        try {const probe=key+':probe';sessionStorage.setItem(probe,'1');sessionStorage.removeItem(probe);}
        catch (_) {storageUnavailable=true;storageReady=false;broken=true;}
    }
    const persist = () => {
        try { sessionStorage.setItem(key,JSON.stringify(state)); return true; }
        catch (_) { storageReady=false;feedback='Não foi possível guardar a alteração neste navegador. Mantenha a leitura no campo e tente novamente com conexão.';return false; }
    };
    const render = () => {
        panel.hidden = navigator.onLine && !state.items.length && !broken;
        if (!navigator.onLine || state.items.length || broken) panel.open = true;
        count.textContent = state.items.length;
        panel.querySelector('[data-draft-login-link]').hidden=!denied;
        send.disabled = !navigator.onLine || !state.items.length || !storageReady || broken || sending || denied;
        clear.disabled = sending;
        const mayBypass=storageUnavailable&&!corrupt || clearFailed;
        onlineOnlyButton.hidden=onlineOnly||!navigator.onLine||state.items.length>0||sending||safeReload||!mayBypass;
        list.replaceChildren();
        state.items.forEach(item => {
            const row=document.createElement('li'), text=document.createElement('span'), remove=document.createElement('button');
            text.textContent=item.quantity+' × '+item.code;
            remove.type='button';remove.className='text-button';remove.textContent='Retirar';remove.disabled=sending;
            remove.setAttribute('aria-label','Retirar leitura '+item.code);
            remove.addEventListener('click',()=>{const before=state.items;state.items=state.items.filter(line=>line.request_key!==item.request_key);if(!persist())state.items=before;render();});
            row.append(text,remove);list.append(row);
        });
        checkoutButtons.forEach((disabled,button)=>button.disabled=disabled||!navigator.onLine||state.items.length>0||sending||broken&&!onlineOnly||denied||safeReload);
        if (onlineOnly) status.textContent='Modo online sem recuperação local nesta página. Confira manualmente as leituras. Dados não apagados podem reaparecer ao recarregar; novas leituras sem conexão não serão guardadas.';
        else if (clearFailed) status.textContent='Não foi possível apagar os dados locais. Eles podem reaparecer ao recarregar. Confira manualmente as leituras antes de continuar somente online.';
        else if (corrupt) status.textContent='O rascunho local está ilegível. Confira e descarte-o explicitamente antes de continuar.';
        else if (storageUnavailable) status.textContent='Este navegador não permite acessar a recuperação local. Para continuar online, confira manualmente as leituras e escolha a ação abaixo.';
        else if (feedback) status.textContent=feedback;
        else if (!navigator.onLine) status.textContent='Sem conexão. Use código de barras ou SKU; a busca por nome precisa de conexão. As leituras ficam apenas nesta aba até a conferência.';
        else if (state.items.length && !sending && !denied) status.textContent='Conexão disponível. Envie as leituras para conferir preços e quantidades atuais antes de receber.';
    };
    form.addEventListener('submit',event => {
        if (navigator.onLine) return;
        event.preventDefault();
        if (!form.reportValidity()) return;
        if (!storageReady || broken || state.items.length>=200) {status.textContent=state.items.length>=200?'O limite de 200 leituras locais foi atingido. Reconecte e envie para conferência.':'O navegador não permite guardar leituras locais. Mantenha o código no campo e tente novamente com conexão.';panel.hidden=false;panel.open=true;return;}
        const value=code.value.trim(), amount=quantity.value;
        if (!value || value.length>80 || !validQuantity(amount)) {status.textContent='Confira o código e a quantidade antes de guardar a leitura.';return;}
        let item;
        try {item={request_key:uuid(),code:value,quantity:amount};if(!state.request_key)state.request_key=uuid();}
        catch (_) {status.textContent='Este navegador não permite criar uma leitura segura. Use a operação com conexão.';panel.hidden=false;return;}
        state.items.push(item);
        if (!persist()) {state.items.pop();render();status.textContent='Não foi possível guardar esta leitura. O código permanece no campo.';return;}
        code.value='';quantity.value='1';render();code.focus();
    });
    clear.addEventListener('click',()=>{
        if (!window.confirm('Descartar as leituras locais ainda não enviadas?'))return;
        try {sessionStorage.removeItem(key);state={request_key:null,version:Number(panel.dataset.draftVersion),items:[]};broken=false;storageReady=true;storageUnavailable=false;corrupt=false;clearFailed=false;onlineOnly=false;feedback='';status.textContent='Leituras locais descartadas.';}
        catch (_) {state={request_key:null,version:Number(panel.dataset.draftVersion),items:[]};clearFailed=true;storageUnavailable=true;broken=true;storageReady=false;feedback='';status.textContent='As leituras desta página foram descartadas por sua confirmação, mas os dados locais não puderam ser apagados e podem reaparecer ao recarregar.';}
        render();
    });
    onlineOnlyButton.addEventListener('click',()=>{
        if(onlineOnlyButton.hidden||!navigator.onLine||state.items.length||sending||safeReload)return;
        if(!window.confirm('Continuar online sem recuperação local nesta página? Confira manualmente todas as leituras pendentes. Nenhuma leitura local será importada ou apagada.'))return;
        onlineOnly=true;render();
    });
    send.addEventListener('click',async()=>{
        if (send.disabled) return;
        feedback='Enviando leituras para conferência. Aguarde a confirmação do servidor.';sending=true;render();status.textContent='Enviando leituras para conferência. Aguarde a confirmação do servidor.';
        const controller=new AbortController(), timeout=setTimeout(()=>controller.abort(),12000);
        try {
            const response=await fetch(panel.dataset.draftUrl,{method:'POST',credentials:'same-origin',signal:controller.signal,headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':form.querySelector('[name="_token"]').value},body:JSON.stringify({request_key:state.request_key,cart:Number(panel.dataset.draftCart),version:state.version,items:state.items})});
            if ([401,419,403].includes(response.status)||response.redirected) {denied=true;throw new Error('Sua sessão ou acesso precisa ser renovado. Entre novamente; as leituras continuam guardadas.');}
            let data;try{data=await response.json();}catch(_){throw new Error('A confirmação não chegou. As leituras continuam guardadas para uma nova tentativa segura.');}
            if (!response.ok) throw new Error(Object.values(data.errors||{}).flat().join(' ')||'Não foi possível conferir as leituras. Atualize a venda quando estiver conectado e tente novamente.');
            if (data.ok!==true || Number(data.cart_id)!==Number(panel.dataset.draftCart) || !Array.isArray(data.imported_request_keys) || !Number.isInteger(Number(data.version)) || Number(data.version)<0)throw new Error('A resposta não confirmou as leituras. Elas continuam guardadas para conferência.');
            const acknowledged=new Set(data.imported_request_keys);
            state.items=state.items.filter(item=>!acknowledged.has(item.request_key));
            if (!state.items.length)sessionStorage.removeItem(key);
            else {state.version=Number(data.version);state.request_key=uuid();if(!persist())throw new Error('O servidor conferiu parte das leituras. Atualize a venda antes de continuar.');}
            safeReload=true;window.location.reload();
        } catch (error) {feedback=error instanceof TypeError||error.name==='AbortError'?'A conexão falhou. As leituras continuam guardadas; uma nova tentativa não duplica os itens.':error.message;}
        finally {clearTimeout(timeout);sending=false;render();}
    });
    document.querySelectorAll('[data-operation-checkout] form').forEach(checkout=>checkout.addEventListener('submit',event=>{
        if (!navigator.onLine || state.items.length || sending || broken&&!onlineOnly || denied) {event.preventDefault();panel.hidden=false;panel.open=true;feedback=!navigator.onLine?'Sem conexão. A venda e o recebimento só podem ser concluídos após a conferência online.':'Confira ou descarte as leituras locais pendentes antes de concluir a venda. Nenhum recebimento foi enviado.';status.textContent=feedback;}
    }));
    window.addEventListener('beforeunload',event=>{if(state.items.length&&!safeReload){event.preventDefault();event.returnValue='';}});
    window.addEventListener('online',()=>{if(!denied)feedback='';render();});window.addEventListener('offline',()=>{if(!denied)feedback='';render();});render();
})();
