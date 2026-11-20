document.querySelectorAll('[data-registry-url]').forEach(registryButton => {
    const form = registryButton.closest('form');
    const documentInput = form.elements.namedItem('document');
    const status = registryButton.closest('.field').querySelector('[role="status"]');
    let generation = 0;
    form.addEventListener('reset', () => {
        generation++;
        status.textContent = '';
        registryButton.disabled = false;
    });
    const normalize = (value) => value.replace(/[.\/\s-]/g, '').toUpperCase();

    registryButton.hidden = false;
    registryButton.addEventListener('click', async () => {
        const requestGeneration = generation;
        const cnpj = normalize(documentInput.value);
        if (!/^\d{14}$/.test(cnpj)) {
            status.textContent = 'Digite um CNPJ numérico com 14 dígitos para consultar.';
            documentInput.focus();
            return;
        }

        status.textContent = 'Consultando CNPJ na BrasilAPI…';
        registryButton.disabled = true;
        try {
            const response = await fetch(registryButton.dataset.registryUrl, {
                method: 'POST',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json'},
                body: JSON.stringify({document: cnpj, _token: form.elements.namedItem('_token').value}),
            });
            const data = await response.json();
            if (requestGeneration !== generation) return;
            if (normalize(documentInput.value) !== cnpj) {
                status.textContent = 'O documento mudou. Faça uma nova consulta.';
                return;
            }
            if (!response.ok) {
                const messages = {
                    404: 'CNPJ não encontrado. Confira os dígitos ou preencha manualmente.',
                    419: 'Sua sessão expirou. Reabra o cadastro para consultar novamente.',
                    422: 'CNPJ inválido. Confira os dígitos.',
                    429: 'Muitas consultas. Aguarde um minuto ou preencha manualmente.',
                };
                status.textContent = messages[response.status] ?? 'Consulta indisponível. Preencha manualmente ou tente novamente.';
                return;
            }
            for (const [name, value] of Object.entries(data.fields)) {
                const input = form.elements.namedItem(name);
                if (!(input instanceof HTMLInputElement || input instanceof HTMLSelectElement)) continue;
                if (input.value.trim() && input.value !== input.dataset.registryDefault) continue;
                if (input instanceof HTMLSelectElement && !Array.from(input.options).some(option => option.value === value)) continue;
                input.value = value;
            }
            status.textContent = 'Consulta concluída. Confira os dados antes de salvar.';
        } catch {
            if (requestGeneration !== generation) return;
            status.textContent = 'Consulta indisponível. Preencha manualmente ou tente novamente.';
        } finally {
            if (requestGeneration === generation) registryButton.disabled = false;
        }
    });
});
