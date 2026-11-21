(() => {
    const header = document.querySelector('[data-operational-header]');
    if (header) {
        const updateHeaderHeight = () => document.body.style.setProperty('--operational-header-height', header.getBoundingClientRect().height + 'px');
        updateHeaderHeight();
        if (window.ResizeObserver) new ResizeObserver(updateHeaderHeight).observe(header);
        else window.addEventListener('resize', updateHeaderHeight);
    }

    document.querySelectorAll('.operation-checkout .split-enabled').forEach(toggle => {
        const form = toggle.closest('form');
        if (form.dataset.splitPayment === undefined) return;
        toggle.closest('.split-payments').dataset.operationSplitReady = '';
        const single = form.querySelector('[data-operation-single]');
        const updateSingleVisibility = () => {
            if (single) single.hidden = toggle.checked;
        };
        toggle.addEventListener('change', updateSingleVisibility);
        updateSingleVisibility();
    });
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
        const normalizeSearch = value => value.toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
        const stateKey = input.dataset.productStateKey ? 'vendra:catalog:' + input.dataset.productStateKey : null;
        if (stateKey) {
            try {
                const saved = JSON.parse(sessionStorage.getItem(stateKey) || 'null');
                if (saved && typeof saved.term === 'string' && saved.term.length <= 180) input.value = saved.term;
                if (category && saved && typeof saved.category === 'string' && [...category.options].some(option => option.value === saved.category)) category.value = saved.category;
            } catch (_) { /* Filtering works when browser storage is unavailable. */ }
        }
        const filter = () => {
            if (stateKey) {
                try { sessionStorage.setItem(stateKey, JSON.stringify({term:input.value.slice(0,180), category:category?.value || ''})); }
                catch (_) { /* Storage never blocks the order. */ }
            }
            const term = normalizeSearch(input.value);
            let visible = 0;
            catalog.querySelectorAll('[data-product-name]').forEach(card => {
                card.hidden = !normalizeSearch(card.dataset.productName).includes(term) || !!(category?.value && card.dataset.category !== category.value);
                if (!card.hidden) visible++;
            });
            catalog.querySelectorAll('[data-product-group]').forEach(group => {
                const count = group.querySelectorAll('[data-product-name]:not([hidden])').length;
                group.hidden = count === 0;
                const counter = group.querySelector('[data-product-group-count]');
                if (counter) counter.textContent = count;
            });
            const empty = catalog.querySelector('[data-product-empty]');
            if (empty) empty.hidden = visible > 0;
            if (status) status.textContent = visible + (visible === 1 ? ' item encontrado' : ' itens encontrados');
        };
        input.addEventListener('input', filter);
        category?.addEventListener('change', filter);
        document.querySelectorAll('[data-product-reset="' + input.dataset.productSearch + '"], [data-product-reset=""]').forEach(button => {
            button.hidden = false;
            button.addEventListener('click', () => {
                input.value = '';
                if (category) {
                    category.value = '';
                    category.dispatchEvent(new Event('change', {bubbles:true}));
                } else filter();
                input.focus();
            });
        });
        filter();
    });
    document.querySelectorAll('[data-category-chips]').forEach(chips => {
        const select = document.getElementById(chips.dataset.categoryChips);
        if (!select) return;
        chips.hidden = false;
        // Keep the original select visible: it remains usable with keyboard and assistive technology.
        chips.querySelectorAll('[data-category-value]').forEach(button => {
            button.addEventListener('click', () => {
                select.value = button.dataset.categoryValue;
                select.dispatchEvent(new Event('change', {bubbles:true}));
            });
        });
        const update = () => chips.querySelectorAll('[data-category-value]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.categoryValue === select.value)));
        select.addEventListener('change', update);
        update();
    });
    const updateElapsed = () => document.querySelectorAll('[data-operation-elapsed]').forEach(time => {
        const timestamp = Date.parse(time.dataset.operationElapsed);
        if (!Number.isFinite(timestamp)) return;
        const minutes = Math.max(0, Math.floor((Date.now() - timestamp)/60000));
        time.textContent = [time.dataset.elapsedPrefix || 'Há', minutes + ' min', time.dataset.elapsedSuffix || ''].filter(Boolean).join(' ');
    });
    updateElapsed();
    if (document.querySelector('[data-operation-elapsed]')) setInterval(updateElapsed, 60000);
    const scanner = document.querySelector('[data-operation-scanner]');
    const searchResults = document.querySelector('[data-operation-search-results]');
    const scanQuantity = document.getElementById('supermarket-quantity');
    document.querySelectorAll('.operation-search-result').forEach(form => {
        form.addEventListener('submit', event => {
            if (!scanQuantity) return;
            if (!scanQuantity.checkValidity()) {
                event.preventDefault();
                scanQuantity.reportValidity();
                return;
            }
            form.querySelector('[data-result-quantity]').value = scanQuantity.value;
        });
    });
    if (!document.getElementById('validation-errors')) {
        if (searchResults) {
            searchResults.querySelector('h2')?.focus();
        } else if (scanner) {
            scanner.focus();
            scanner.select();
            scanner.scrollIntoView({block: 'center'});
        }
    }
    document.addEventListener('keydown', event => {
        if (event.key === 'F2' && scanner) {
            event.preventDefault();
            scanner.focus();
            scanner.select();
            scanner.scrollIntoView({block: 'center'});
        }
        if (event.key === 'F9') {
            const checkout = document.querySelector('[data-operation-checkout]');
            if (checkout && !checkout.hidden) {
                event.preventDefault();
                checkout.open = true;
                checkout.scrollIntoView({block: 'center', behavior: 'smooth'});
                const firstField = [...checkout.querySelectorAll('select:not([disabled]), input:not([type="hidden"]):not([disabled]), button:not([disabled])')].find(field => field.getClientRects().length);
                firstField?.focus();
            }
        }
    });
})();
