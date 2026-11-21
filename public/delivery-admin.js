(() => {
    document.querySelectorAll('[data-delivery-map]').forEach(form => {
        const channel = form.querySelector('[name=provider]');
        const refresh = () => { form.action = channel.dataset.providerBase + '/' + encodeURIComponent(channel.value) + '/catalogo'; };
        channel.addEventListener('change', refresh);
        refresh();
    });
})();
