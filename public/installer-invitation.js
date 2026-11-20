(() => {
    const password = document.getElementById('invitation-password');
    const confirmation = document.getElementById('invitation-confirm');
    const checkPasswords = () => {
        password.setCustomValidity(new TextEncoder().encode(password.value).length > 72 ? 'Use uma senha de até 72 bytes.' : '');
        confirmation.setCustomValidity(confirmation.value && confirmation.value !== password.value ? 'Digite a mesma senha nos dois campos.' : '');
    };
    password.addEventListener('input', checkPasswords);
    confirmation.addEventListener('input', checkPasswords);
    const token = new URLSearchParams(window.location.hash.slice(1)).get('token');
    if (token === null) return;
    history.replaceState(null, '', window.location.pathname + window.location.search);
    if (/^[a-f0-9]{64}$/i.test(token)) {
        document.getElementById('invitation-code').value = token;
        document.getElementById('invitation-code-field').hidden = true;
    }
})();
