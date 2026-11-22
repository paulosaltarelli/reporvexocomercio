(() => {
    const shortcuts = {
        F3: document.getElementById('supermarket-quantity'),
        F4: document.getElementById('supermarket-new-sale'),
        F8: document.getElementById('supermarket-cart-transition')
    };
    document.addEventListener('keydown', event => {
        const target = shortcuts[event.key];
        if (!target || target.disabled || event.isComposing || event.defaultPrevented) return;
        if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey || document.querySelector('dialog[open]')) return;
        event.preventDefault();
        target.focus();
        if (event.key === 'F3') target.select();
    });
})();
