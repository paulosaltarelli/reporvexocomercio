(() => {
    const button = document.getElementById('copy-quote');
    const message = document.getElementById('quote-message');
    const status = document.getElementById('quote-copy-feedback');
    button.hidden = false;
    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(message.value);
            status.textContent = 'Mensagem copiada.';
        } catch {
            message.focus();
            message.select();
            status.textContent = 'Texto selecionado. Use a opção Copiar do seu dispositivo.';
        }
    });
})();
