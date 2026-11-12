/* Shared UI behavior. Final financial values are always validated by PHP. */
window.Vendra = (() => {
    let sequence = 0;
    function decimalUnits(value, scale) {
        const text = String(value ?? '').trim();
        if (!/^\d+(\.\d*)?$/.test(text)) return null;
        const [whole, fraction = ''] = text.split('.');
        if (fraction.length > scale) return null;
        return BigInt(whole + fraction.padEnd(scale, '0'));
    }
    function centsText(cents) {
        const negative=cents<0n;
        const absolute=negative?-cents:cents;
        const whole = (absolute / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return 'R$ ' + (negative?'−':'') + whole + ',' + (absolute % 100n).toString().padStart(2, '0');
    }
    function lineCents(quantity, price, priceScale = 2) {
        const qty = decimalUnits(quantity, 3);
        const units = decimalUnits(price, priceScale);
        const divisor = 10n ** BigInt(3 + priceScale - 2);
        return qty === null || units === null ? null : (qty * units * 2n + divisor) / (divisor * 2n);
    }
    function suggestedCents(cost, margin) {
        const costUnits = decimalUnits(cost, 4);
        const basisPoints = decimalUnits(margin, 2);
        if (costUnits === null || basisPoints === null || basisPoints >= 10000n) return null;
        const denominator = 10000n - basisPoints;
        return (costUnits * 200n + denominator) / (denominator * 2n);
    }
    function associateLabels(root = document) {
        root.querySelectorAll('label:not([for])').forEach(label => {
            const control = label.querySelector('input,select,textarea') || label.parentElement.querySelector('input:not([type="hidden"]),select,textarea');
            if (!control) return;
            if (!control.id) control.id = 'vendra-field-' + (++sequence);
            label.htmlFor = control.id;
            const hints = [...label.parentElement.querySelectorAll('.hint')];
            if (hints.length && !control.hasAttribute('aria-describedby')) {
                control.setAttribute('aria-describedby', hints.map(hint => {
                    if (!hint.id) hint.id = 'vendra-hint-' + (++sequence);
                    return hint.id;
                }).join(' '));
            }
        });
    }
    function init() {
        document.body.classList.add('vendra-ui-ready');
        associateLabels();
        const observer = new MutationObserver(records => {
            if (records.some(record => [...record.addedNodes].some(node => node.nodeType === 1))) associateLabels();
        });
        observer.observe(document.body, {childList: true, subtree: true});
        const toggle = document.querySelector('.menu-toggle');
        const menu = document.querySelector('#company-navigation');
        if (toggle && menu) {
            const small = window.matchMedia('(max-width: 760px)');
            const updateMenu = () => {
                menu.hidden = small.matches;
                toggle.setAttribute('aria-expanded', String(!menu.hidden));
            };
            toggle.addEventListener('click', () => {
                menu.hidden = !menu.hidden;
                toggle.setAttribute('aria-expanded', String(!menu.hidden));
            });
            small.addEventListener('change', updateMenu);
            updateMenu();
        }
        document.querySelectorAll('[data-open-disclosure]').forEach(link => {
            link.addEventListener('click', event => {
                const details=document.getElementById(link.dataset.openDisclosure);
                if(!details)return;
                event.preventDefault();details.open=true;details.scrollIntoView({block:'center'});
                details.querySelector('input:not([type="hidden"]),select')?.focus();
            });
        });
        const errors = document.querySelector('#validation-errors');
        if (errors) {
            const fields = JSON.parse(document.querySelector('#validation-fields')?.textContent || '[]');
            document.querySelectorAll('input,select,textarea').forEach(control => {
                const field = control.name.replace(/\[([^\]]+)\]/g, '.$1');
                if (fields.includes(field)) control.setAttribute('aria-invalid', 'true');
            });
            errors.focus();
        }
        document.querySelectorAll('select[name="paid"]').forEach(select => {
            const dueDate = select.form?.querySelector('input[name="due_date"]');
            if (!dueDate) return;
            const installments=select.form?.querySelector('input[name="installments"]');
            let pendingInstallments=installments?.value || '1';
            const updateDueDate = () => {
                if(installments){
                    const paid=select.value==='1';
                    if(paid&&!installments.readOnly){pendingInstallments=installments.value;installments.value='1';}
                    if(!paid&&installments.readOnly)installments.value=pendingInstallments;
                    installments.readOnly=paid;
                }
                dueDate.required = select.value === '0';
                dueDate.setAttribute('aria-required', String(dueDate.required));
            };
            select.addEventListener('change', updateDueDate);
            updateDueDate();
        });
        function markScrollableTables() {
            document.querySelectorAll('.scroll').forEach(region => {
                if (region.scrollWidth > region.clientWidth && region.clientWidth>0) {
                    region.tabIndex = 0;
                    region.setAttribute('role', 'region');
                    if(!region.hasAttribute('aria-label'))region.setAttribute('aria-label', 'Tabela com rolagem horizontal');
                }
            });
        }
        markScrollableTables();
        document.querySelectorAll('details').forEach(details=>details.addEventListener('toggle',markScrollableTables));
        window.addEventListener('resize',markScrollableTables);
        const printDetails=[];
        window.addEventListener('beforeprint',()=>{
            document.querySelectorAll('main details:not([open])').forEach(details=>{
                if(details.matches('.no-print,.payment-disclosure,.chart-values') || details.closest('form') || details.querySelector(':scope > form'))return;
                printDetails.push(details);details.open=true;
            });
        });
        window.addEventListener('afterprint',()=>{printDetails.splice(0).forEach(details=>details.open=false);});
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
    return {decimalUnits, centsText, lineCents, suggestedCents, associateLabels};
})();
