/* Landing only. No tracking SDK, cookies, storage, or outbound analytics requests. */
(() => {
    'use strict';
    const menu = document.querySelector('.mobile-menu');
    menu?.querySelectorAll('nav a').forEach(link => {
        link.addEventListener('click', () => {
            menu.open = false;
            const section = document.getElementById(link.hash.slice(1));
            if (section) {
                section.setAttribute('tabindex', '-1');
                section.focus({ preventScroll: true });
            }
        });
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && menu?.open) {
            menu.open = false;
            menu.querySelector('summary')?.focus();
        }
    });
    document.addEventListener('click', event => {
        if (menu?.open && !menu.contains(event.target)) menu.open = false;
    });

    let printClosed = [];
    window.addEventListener('beforeprint', () => {
        printClosed = [...document.querySelectorAll('.faq-list details:not([open])')];
        printClosed.forEach(item => { item.open = true; });
    });
    window.addEventListener('afterprint', () => {
        printClosed.forEach(item => { item.open = false; });
        printClosed = [];
    });

    // Only reviewed campaign codes may leave the URL as event metadata.
    // Unknown values, terms, content, click IDs and personal data are discarded.
    // Add campaign codes here after review, never arbitrary query-string values.
    const allowed = {
        utm_source: ['google', 'meta', 'facebook', 'instagram'],
        utm_medium: ['cpc', 'paid_social', 'paid_search'],
        utm_campaign: ['vendra_informatica', 'vendra_moda', 'vendra_automotivo']
    };
    const params = new URLSearchParams(window.location.search);
    const attribution = {};
    for (const [key, values] of Object.entries(allowed)) {
        const value = params.get(key);
        if (values.includes(value)) attribution[key] = value;
    }
    document.querySelectorAll('a[data-cta]').forEach(link => {
        link.addEventListener('click', () => {
            const detail = {
                version: 1,
                placement: link.dataset.cta,
                channel: 'whatsapp',
                attribution: { ...attribution }
            };
            // Intent only: neither event proves a message was sent or a lead received.
            // A future consent-aware adapter may subscribe; nothing is transmitted here.
            for (const name of ['whatsapp_click', 'demo_request']) {
                document.dispatchEvent(new CustomEvent(`vendra:${name}`, {
                    detail: { ...detail, event: name }
                }));
            }
        });
    });
})();
