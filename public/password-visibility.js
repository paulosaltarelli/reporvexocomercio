document.querySelectorAll('.password-toggle').forEach(button => {
    const input = document.getElementById(button.getAttribute('aria-controls'));
    if (!input || input.type !== 'password') return;

    button.hidden = false;
    button.addEventListener('click', () => {
        const reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        const action = reveal ? button.dataset.hide : button.dataset.show;
        button.setAttribute('aria-label', action);
        button.title = action;
    });
});
