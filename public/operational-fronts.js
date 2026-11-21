(() => {
    document.querySelectorAll('[data-operation-paid]').forEach(select => {
        const fields = select.closest('form').querySelector('[data-operation-credit]');
        const refresh = () => {
            if (!fields) return;
            fields.hidden = select.value !== '0';
            fields.querySelector('input[type="date"]').required = select.value === '0';
        };
        select.addEventListener('change', refresh);
        refresh();
    });
    document.querySelectorAll('[data-product-search]').forEach(input => {
        const catalog = document.getElementById(input.dataset.productSearch);
        if (!catalog) return;
        const status = document.getElementById(input.getAttribute('aria-describedby'));
        const category = document.querySelector('[data-product-category="' + input.dataset.productSearch + '"]');
        const filter = () => {
            const term = input.value.toLocaleLowerCase('pt-BR').trim();
            let visible = 0;
            catalog.querySelectorAll('[data-product-name]').forEach(card => {
                card.hidden = !card.dataset.productName.includes(term) || !!(category?.value && card.dataset.category !== category.value);
                if (!card.hidden) visible++;
            });
            if (status) status.textContent = visible + (visible === 1 ? ' item encontrado' : ' itens encontrados');
        };
        input.addEventListener('input', filter);
        category?.addEventListener('change', filter);
    });
    const scanner = document.querySelector('[data-operation-scanner]');
    if (scanner && !document.getElementById('validation-errors')) {
        scanner.focus();
        scanner.select();
    }
    document.addEventListener('keydown', event => {
        if (event.key === 'F2' && scanner) {
            event.preventDefault();
            scanner.focus();
            scanner.select();
        }
        if (event.key === 'F9') {
            const checkout = document.querySelector('[data-operation-checkout]');
            if (checkout && !checkout.hidden) {
                event.preventDefault();
                checkout.open = true;
                checkout.scrollIntoView({block: 'center', behavior: 'smooth'});
                checkout.querySelector('select, input, button')?.focus();
            }
        }
    });
})();
