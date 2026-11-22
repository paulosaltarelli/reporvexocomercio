const copyCatalog = document.querySelector('[data-copy-catalog]');
if (copyCatalog) {
    const address = document.getElementById('catalog-url');
    const status = document.getElementById('catalog-copy-status');
    copyCatalog.hidden = false;
    copyCatalog.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(address.value);
            status.textContent = 'Link copiado.';
        } catch {
            address.focus();
            address.select();
            status.textContent = 'Link selecionado. Use o comando de copiar do dispositivo.';
        }
    });
}
