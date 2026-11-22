(() => {
    const panel = document.getElementById('market-payment');
    if (!panel || typeof HTMLDialogElement === 'undefined' || !HTMLDialogElement.prototype.showModal) return;

    const triggers = [...document.querySelectorAll('[data-market-checkout-open]')];
    const dialog = document.createElement('dialog');
    dialog.id = 'supermarket-checkout-dialog';
    dialog.className = 'market-checkout-dialog';
    dialog.setAttribute('aria-labelledby', 'market-checkout-title');
    panel.before(dialog);
    dialog.append(panel);

    const form = panel.querySelector('form');
    const confirm = form.querySelector('[data-market-checkout-confirm]');
    const close = panel.querySelector('[data-market-checkout-close]');
    const draft = document.querySelector('[data-offline-draft]');
    const errors = panel.hasAttribute('data-checkout-errors') && document.getElementById('validation-errors');
    if (errors) form.before(errors);
    let returnToDraft = false;
    let submitting = false;

    function focusDraft() {
        if (!draft) return;
        draft.hidden = false;
        draft.open = true;
        draft.querySelector('summary').focus();
    }

    function openCheckout() {
        if (dialog.open) return;
        if (confirm.disabled && draft) {
            focusDraft();
            return;
        }
        if (document.querySelector('dialog[open]')) return;
        dialog.showModal();
        if (errors) errors.focus();
        else {
            const payment = form.querySelector('.split-enabled:checked')
                ? form.querySelector('.split-payment-row [data-field="payment_option"]')
                : form.querySelector('[name="payment_option"]');
            (payment || confirm).focus();
        }
    }

    close.hidden = false;
    confirm.querySelector('[data-market-confirm-shortcut]').hidden = false;
    confirm.setAttribute('aria-keyshortcuts', 'Control+Enter');
    close.addEventListener('click', () => dialog.close());
    for (const trigger of triggers) {
        trigger.setAttribute('aria-haspopup', 'dialog');
        trigger.setAttribute('aria-controls', dialog.id);
        trigger.addEventListener('click', event => {
            event.preventDefault();
            openCheckout();
        });
    }
    dialog.addEventListener('close', () => {
        if (returnToDraft) focusDraft();
        else {
            const scanner = document.querySelector('[data-operation-scanner]');
            scanner?.focus();
            scanner?.select();
        }
        returnToDraft = false;
    });
    // Keep pending local readings reachable when the terminal loses its connection.
    new MutationObserver(() => {
        if (dialog.open && confirm.disabled && draft) {
            returnToDraft = true;
            dialog.close();
        }
    }).observe(confirm, { attributes: true, attributeFilter: ['disabled'] });

    dialog.addEventListener('keydown', event => {
        if (event.key === 'Enter' && event.ctrlKey && !event.isComposing && !event.shiftKey && !event.altKey && !event.metaKey && form.contains(event.target)) {
            event.preventDefault();
            if (!event.repeat && !confirm.disabled) confirm.click();
            return;
        }
        if (event.isComposing || event.ctrlKey || event.altKey || event.metaKey) return;
        if (event.key === 'F9' || (event.key === 'Enter' && event.repeat)) {
            event.preventDefault();
            return;
        }
        const enterInInput = event.key === 'Enter' && event.target.matches('input:not([type="button"]):not([type="submit"])');
        if (event.key !== 'Tab' && !enterInInput) return;
        const controls = [...dialog.querySelectorAll('button, a[href], input:not([type="hidden"]), select, textarea, summary')]
            .filter(field => !field.matches(':disabled') && field.tabIndex >= 0 && field.getClientRects().length
                && (!field.closest('details:not([open])') || field.matches('details:not([open]) > summary')));
        const first = controls[0], last = controls.at(-1);
        if (enterInInput) {
            event.preventDefault();
            if (event.target.reportValidity()) controls[controls.indexOf(event.target) + 1]?.focus();
        } else if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last?.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first?.focus();
        }
    });
    form.addEventListener('submit', event => {
        if (event.defaultPrevented) return;
        if (!dialog.open || confirm.disabled || submitting) event.preventDefault();
        else submitting = true;
    });
    window.addEventListener('pageshow', () => { submitting = false; });
    if (errors) openCheckout();
})();
