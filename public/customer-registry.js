const registryButton = document.querySelector('[data-registry-url]');

if (registryButton) {
    const form = registryButton.closest('form');
    const documentInput = form.elements.namedItem('document');
    const status = document.getElementById('customer-registry-status');
    const normalize = (value) => value.replace(/[.\/\s-]/g, '').toUpperCase();

    registryButton.hidden = false;
    registryButton.addEventListener('click', async () => {
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
                if (input instanceof HTMLInputElement && !input.value.trim()) input.value = value;
            }
            status.textContent = 'Dados da BrasilAPI inseridos nos campos vazios. Revise o cadastro e informe a IE, se houver.';
        } catch {
            status.textContent = 'Consulta indisponível. Preencha manualmente ou tente novamente.';
        } finally {
            registryButton.disabled = false;
        }
    });
}
