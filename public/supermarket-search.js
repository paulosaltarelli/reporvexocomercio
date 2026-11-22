(() => {
    const form = document.querySelector('form[data-suggestions-url]');
    if (!form || !window.fetch || !window.AbortController || !form.requestSubmit) return;

    const scanner = form.elements.code;
    const list = document.getElementById('supermarket-suggestions');
    const status = document.getElementById('supermarket-suggestion-status');
    const previousResults = document.querySelector('[data-operation-search-results]');
    const money = new Intl.NumberFormat('pt-BR', {style: 'currency', currency: 'BRL'});
    let products = [], selected = -1;
    let timer, request;

    scanner.setAttribute('role', 'combobox');
    scanner.setAttribute('aria-autocomplete', 'list');
    scanner.setAttribute('aria-controls', list.id);
    scanner.setAttribute('aria-expanded', 'false');
    document.getElementById('supermarket-search-help').textContent =
        'Leia o código ou digite pelo menos 2 caracteres do nome. Use ↑ e ↓ para escolher, Enter para adicionar e Esc para limpar.';

    function close() {
        clearTimeout(timer);
        request?.abort();
        products = [];
        selected = -1;
        list.replaceChildren();
        list.hidden = true;
        status.textContent = '';
        scanner.removeAttribute('aria-activedescendant');
        scanner.setAttribute('aria-expanded', 'false');
    }

    function highlight(index) {
        list.children[selected]?.setAttribute('aria-selected', 'false');
        selected = index;
        const option = list.children[index];
        option.setAttribute('aria-selected', 'true');
        scanner.setAttribute('aria-activedescendant', option.id);
        option.scrollIntoView({block: 'nearest'});
    }

    function choose(product) {
        if (!product || !form.reportValidity()) return;
        scanner.value = product.barcode || product.sku;
        close();
        form.requestSubmit();
    }

    function render(data) {
        products = data.products;
        const options = products.map((product, index) => {
            const row = document.createElement('li');
            row.id = 'supermarket-suggestion-' + index;
            row.setAttribute('role', 'option');
            row.setAttribute('aria-selected', 'false');
            const name = document.createElement('strong');
            name.textContent = product.name;
            const price = document.createElement('span');
            price.className = 'amount';
            price.textContent = money.format(product.sale_price) + ' / ' + product.unit;
            const identifiers = document.createElement('small');
            identifiers.textContent = 'SKU ' + product.sku + (product.barcode ? ' · ' + product.barcode : '');
            row.append(name, price, identifiers);
            row.addEventListener('click', () => choose(product));
            return row;
        });
        list.replaceChildren(...options);
        list.hidden = !products.length;
        scanner.setAttribute('aria-expanded', String(products.length > 0));
        if (previousResults) previousResults.hidden = true;
        if (data.more) status.textContent = 'Primeiros 12 produtos. Continue digitando para refinar.';
        else if (!products.length) status.textContent = 'Nenhum produto encontrado. Confira o nome ou o código.';
        else status.textContent = products.length === 1 ? '1 produto encontrado.' : products.length + ' produtos encontrados.';
    }

    function search() {
        close();
        const term = scanner.value.trim();
        if (term.length < 2 || !navigator.onLine) return;
        const controller = new AbortController();
        request = controller;
        timer = setTimeout(async () => {
            status.textContent = 'Buscando produtos…';
            const url = new URL(form.dataset.suggestionsUrl, document.baseURI);
            url.searchParams.set('q', term);
            try {
                const response = await fetch(url, {signal: controller.signal, headers: {Accept: 'application/json'}});
                if (!response.ok) throw new Error();
                const data = await response.json();
                if (!controller.signal.aborted) render(data);
            } catch {
                if (!controller.signal.aborted) status.textContent = 'Busca rápida indisponível. Pressione Enter para consultar no caixa.';
            }
        }, 180);
    }

    scanner.addEventListener('input', () => {
        if (previousResults) previousResults.hidden = true;
        search();
    });
    scanner.addEventListener('focus', search);
    scanner.addEventListener('blur', close);
    window.addEventListener('offline', close);
    // A pointer selection keeps DOM focus in the combobox until the form is submitted.
    list.addEventListener('mousedown', event => event.preventDefault());
    scanner.addEventListener('keydown', event => {
        if (event.isComposing || event.altKey || event.ctrlKey || event.metaKey) return;
        if (event.key === 'Escape' && scanner.value) {
            event.preventDefault();
            scanner.value = '';
            if (previousResults) previousResults.hidden = true;
            close();
            return;
        }
        if (!products.length) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            const first = step === 1 ? 0 : products.length - 1;
            highlight(selected < 0 ? first : (selected + step + products.length) % products.length);
        } else if (event.key === 'Enter') {
            // A scanner's terminator must always use the original exact/weighted code.
            if (selected < 0 && (!/\p{L}/u.test(scanner.value) || products.some(p => Number(p.exact_code) === 1))) return;
            event.preventDefault();
            if (selected >= 0) choose(products[selected]);
            else if (products.length === 1) choose(products[0]);
            else highlight(0);
        }
    });
    if (previousResults && previousResults.contains(document.activeElement)) {
        scanner.focus();
        scanner.select();
    } else if (document.activeElement === scanner) search();
})();
