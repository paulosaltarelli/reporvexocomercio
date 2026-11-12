/* Navigation only: no analytics, lead collection or external requests. */
(() => {
    const menu = document.querySelector('.mobile-menu');
    if (!menu) return;
    menu.querySelectorAll('nav a').forEach(link => {
        link.addEventListener('click', () => { menu.open = false; });
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && menu.open) {
            menu.open = false;
            menu.querySelector('summary')?.focus();
        }
    });
    document.addEventListener('click', event => {
        if (menu.open && !menu.contains(event.target)) menu.open = false;
    });
})();
