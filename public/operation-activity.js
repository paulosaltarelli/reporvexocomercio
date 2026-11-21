(() => {
    const panel = document.querySelector('[data-operation-activity]');
    if (!panel) return;
    const message = panel.querySelector('[data-activity-message]');
    const connection = panel.querySelector('[data-operation-connection]');
    const reload = panel.querySelector('[data-activity-reload]');
    const login = panel.querySelector('[data-activity-login]');
    const forms = new Map();
    let baseline = panel.dataset.operationRevision || null, changed = false, running = false, navigating = false, denied = false;
    const signature = form => JSON.stringify([...new FormData(form)].filter(([name,value]) => !['_token','version','request_key'].includes(name) && typeof value === 'string'));
    const rememberForms = () => document.querySelectorAll('form').forEach(form => forms.set(form, signature(form)));
    const hasEdits = () => [...forms].some(([form,value]) => form.isConnected && signature(form) !== value);
    const safeToRefresh = () => !document.hidden && navigator.onLine && !hasEdits() && !navigating && !document.activeElement?.closest('form') && !document.activeElement?.matches('input,textarea,select,[contenteditable="true"]') && !document.querySelector('dialog[open], [data-operation-checkout][open], [data-partial-payment][open]');
    const update = () => {
        connection.textContent = !navigator.onLine ? 'Sem conexão' : denied ? 'Sessão precisa de atenção' : 'Conectado';
        if (changed && !denied) {
            reload.hidden = false;
            reload.disabled = hasEdits() || navigating;
            message.textContent = reload.disabled ? 'Há pedidos atualizados. Salve ou conclua os campos em edição antes de atualizar.' : 'Há pedidos atualizados disponíveis.';
        }
    };
    document.addEventListener('submit', event => { if (!event.defaultPrevented) navigating = true; });
    document.addEventListener('input', update);
    document.addEventListener('change', update);
    reload.addEventListener('click', () => { if (!hasEdits() && !navigating) window.location.reload(); });
    const poll = async () => {
        if (running || denied || document.hidden || !navigator.onLine || navigating) { update(); return; }
        running = true;
        const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 8000);
        try {
            const response = await fetch(panel.dataset.operationActivityUrl, {headers:{Accept:'application/json'}, credentials:'same-origin', cache:'no-store', signal:controller.signal});
            if ([401,419,403].includes(response.status) || response.redirected || !(response.headers.get('content-type')||'').includes('application/json')) {
                denied = true;
                message.textContent = response.status === 403 ? 'Seu acesso foi alterado. Entre novamente para conferir as permissões.' : 'Sua sessão expirou. Entre novamente para continuar.';
                login.hidden = false;
                reload.hidden = true;
                return;
            }
            if (!response.ok) throw new Error('Unavailable');
            const data = await response.json();
            const version = data.version ?? data.revision;
            if (typeof version !== 'string' && typeof version !== 'number') throw new Error('Invalid activity');
            if (baseline === null) baseline = String(version);
            else if (String(version) !== baseline) {
                changed = true;
                update();
                if (safeToRefresh()) window.location.reload();
            }
            if (!changed) message.textContent = 'Pedidos atualizados automaticamente quando não há campos em edição.';
        } catch (_) {
            connection.textContent = navigator.onLine ? 'Conexão instável' : 'Sem conexão';
            message.textContent = 'Não foi possível conferir novos pedidos. Nenhuma alteração foi enviada.';
        } finally {
            clearTimeout(timeout);
            running = false;
            if (denied || !navigator.onLine || changed) update();
        }
    };
    window.addEventListener('online', () => { update(); poll(); });
    window.addEventListener('offline', update);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
    setTimeout(() => { rememberForms(); update(); poll(); }, 0);
    setInterval(poll, 20000);
})();
