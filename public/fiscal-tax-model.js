for (const form of document.querySelectorAll('[data-tax-parameters]')) {
    const model = form.querySelector('[name="tax_model"]');
    const start = form.querySelector('[name="valid_from"]');
    const end = form.querySelector('[name="valid_until"]');
    const cbs = form.querySelector('[name="cbs_rate"]');
    const reference = form.querySelector('[name="accountant_reference"]');
    const drafts = new Map();
    let previous = model.value;
    function showFieldsForModel() {
        const reform = model.value === 'regular_resale_2027';
        form.querySelector('[data-reform-help]').hidden = !reform;
        form.querySelector('[data-optional-start]').hidden = reform;
        start.required = reform;
        start.min = end.min = reform ? '2027-01-01' : '';
        start.max = end.max = reform ? '2027-12-31' : '';
        if (model.value !== previous) {
            drafts.set(previous, { cbs: cbs.value, reference: reference.value });
            const draft = drafts.get(model.value);
            cbs.value = draft?.cbs ?? '';
            reference.value = draft?.reference ?? '';
            previous = model.value;
        }
        for (const field of form.querySelectorAll('[data-legacy-field]')) {
            field.hidden = reform;
            field.querySelector('input, select').disabled = reform;
        }
    }
    model.addEventListener('change', showFieldsForModel);
    showFieldsForModel();
}
