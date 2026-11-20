(() => {
    const toggle = document.getElementById('platform-menu-toggle');
    const menu = document.getElementById('platform-menu');
    if (!toggle || !menu) return;

    const narrow = window.matchMedia('(max-width: 840px)');
    function setExpanded(expanded) {
        menu.hidden = narrow.matches && !expanded;
        toggle.hidden = !narrow.matches;
        toggle.setAttribute('aria-expanded', String(expanded));
    }
    function syncViewport() {
        if (narrow.matches && menu.contains(document.activeElement)) {
            toggle.hidden = false;
            toggle.focus();
        }
        setExpanded(!narrow.matches);
    }
    toggle.addEventListener('click', () => setExpanded(menu.hidden));
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && narrow.matches && !menu.hidden) {
            setExpanded(false);
            toggle.focus();
        }
    });
    narrow.addEventListener('change', syncViewport);
    syncViewport();
})();
