(() => {
    const cpf = document.querySelector('#nfce #nfce-consumer-cpf');
    if (cpf) document.addEventListener('keydown', event => {
        if (event.key !== 'F8' || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey
            || event.defaultPrevented || document.querySelector('dialog[open]')) return;
        event.preventDefault();
        const disclosure = cpf.closest('details');
        if (disclosure) disclosure.open = true;
        cpf.focus();
        cpf.select();
    });

    const dialog = document.querySelector('#nfce-print-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') return;

    const preview = dialog.querySelector('iframe');
    const pdfLink = dialog.querySelector('[data-nfce-open-pdf]');
    const pending = new Set(['pending', 'sent', 'uncertain']);
    function openPrint(link) {
        pdfLink.href = link.href;
        preview.src = link.href;
        if (!dialog.open) dialog.showModal();
    }

    dialog.addEventListener('close', () => preview.removeAttribute('src'));

    for (const row of document.querySelectorAll('[data-nfce-status]')) {
        const printLink = row.querySelector('[data-nfce-print]');
        let autoOpen = row.dataset.nfceAutoOpen === '1';
        let attempts = 0;
        printLink.addEventListener('click', event => {
            event.preventDefault();
            openPrint(printLink);
        });

        function render(status) {
            row.querySelector('[data-nfce-label]').textContent = status.label ?? status.state;
            row.querySelector('[data-nfce-code]').textContent = status.official_code ? ' · SEFAZ ' + status.official_code : '';
            row.querySelector('[data-nfce-protocol]').textContent = status.protocol ? ' · Protocolo ' + status.protocol : '';
            row.querySelector('[data-nfce-message]').textContent = status.operator_message ?? '';
            printLink.hidden = status.state !== 'authorized';
            printLink.style.display = printLink.hidden ? 'none' : '';
            if (autoOpen && status.state === 'authorized') {
                autoOpen = false;
                openPrint(printLink);
            }
        }

        async function poll() {
            attempts++;
            try {
                const response = await fetch(row.dataset.nfceStatus, {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                    redirect: 'error',
                });
                if ([401, 403, 404].includes(response.status)) return;
                if (response.ok) {
                    const status = await response.json();
                    render(status);
                    if (!pending.has(status.state)) return;
                }
            } catch {
                // Keep the last confirmed status while the connection recovers.
            }
            if (attempts < 60) window.setTimeout(poll, 5000);
        }

        if (autoOpen && row.dataset.nfceState === 'authorized') {
            autoOpen = false;
            openPrint(printLink);
        } else if (pending.has(row.dataset.nfceState)) {
            poll();
        }
    }
})();
