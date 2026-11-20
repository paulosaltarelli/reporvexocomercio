document.querySelectorAll('[data-copy-pix]').forEach(button => {
    const code = document.getElementById(button.dataset.copyPix);
    const feedback = document.getElementById(button.getAttribute('aria-describedby'));
    button.hidden = false;
    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(code.value);
            feedback.textContent = 'Código Pix copiado.';
        } catch {
            code.focus();
            code.select();
            feedback.textContent = 'Código selecionado. Use a opção de copiar do dispositivo.';
        }
    });
});
