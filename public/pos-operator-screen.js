(() => {
    const button = document.querySelector('[data-pos-fullscreen]');
    if (!button) return;
    const workspace = button.closest('.workspace');
    const message = document.querySelector('[data-pos-fullscreen-status]');
    const key = 'vendra.operatorScreen';

    function setExpanded(expanded) {
        document.body.toggleAttribute('data-pos-expanded', expanded);
        button.setAttribute('aria-pressed', String(expanded));
        button.querySelector('span').textContent = expanded ? 'Sair da tela cheia' : 'Tela cheia';
        try {
            if (expanded) sessionStorage.setItem(key, '1');
            else sessionStorage.removeItem(key);
        } catch {
            // The expanded layout still works if the browser disables storage.
        }
    }

    try {
        if (sessionStorage.getItem(key) === '1') setExpanded(true);
    } catch {
        // A blocked store starts with the regular layout.
    }

    async function toggleFullscreen() {
        const expanded = document.body.hasAttribute('data-pos-expanded');
        message.textContent = '';
        setExpanded(!expanded);
        if (expanded) {
            if (document.fullscreenElement === workspace) await document.exitFullscreen().catch(() => {});
            return;
        }
        try {
            await workspace.requestFullscreen();
        } catch {
            message.textContent = 'O caixa foi ampliado. Use F11 para ocultar as barras do navegador.';
        }
    }

    button.addEventListener('click', toggleFullscreen);
    document.addEventListener('keydown', event => {
        if (event.key !== 'F10' || event.repeat || event.defaultPrevented
            || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
        event.preventDefault();
        toggleFullscreen();
    });
})();
