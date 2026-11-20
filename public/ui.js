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
    function commercialLine(quantity,catalogPrice,offeredPrice,discountPercent='0') {
        const qty=decimalUnits(quantity,3),catalog=decimalUnits(catalogPrice,2),offered=decimalUnits(offeredPrice,2),percent=decimalUnits(discountPercent,2);
        if(qty===null||catalog===null||offered===null||percent===null||percent>10000n)return null;
        const reference=catalog>offered?catalog:offered;
        const gross=(qty*reference*2n+1000n)/2000n;
        const net=(qty*offered*(10000n-percent)*2n+10000000n)/20000000n;
        return {gross,net,discount:gross-net,reference,unitReduction:reference*10000n-offered*(10000n-percent),explicit:percent};
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
        const sidebar = document.querySelector('.app-sidebar');
        const backdrop = document.querySelector('.sidebar-backdrop');
        if (toggle && menu && sidebar && backdrop) {
            const small = window.matchMedia('(max-width: 760px)');
            const workspace = document.querySelector('.workspace');
            const close = sidebar.querySelector('.sidebar-close');
            function setMenu(open, restoreFocus = false) {
                const modal = small.matches && open;
                sidebar.classList.toggle('is-open', modal);
                sidebar.inert = small.matches && !open;
                menu.hidden = small.matches && !open;
                backdrop.hidden = !modal;
                document.body.classList.toggle('navigation-open', modal);
                toggle.setAttribute('aria-expanded', String(open || !small.matches));
                toggle.setAttribute('aria-label', modal ? 'Fechar menu' : 'Abrir menu');
                if (workspace) workspace.inert = modal;
                if (modal) {
                    sidebar.setAttribute('role', 'dialog');
                    sidebar.setAttribute('aria-modal', 'true');
                    close?.focus();
                } else {
                    sidebar.removeAttribute('role');
                    sidebar.removeAttribute('aria-modal');
                    if (restoreFocus) toggle.focus();
                }
            }
            toggle.addEventListener('click', () => setMenu(!sidebar.classList.contains('is-open')));
            close?.addEventListener('click', () => setMenu(false, true));
            backdrop.addEventListener('click', () => setMenu(false, true));
            sidebar.addEventListener('keydown', event => {
                if (!small.matches || !sidebar.classList.contains('is-open')) return;
                if (event.key === 'Escape') {
                    event.preventDefault();
                    setMenu(false, true);
                }
                if (event.key === 'Tab') {
                    const focusable = [...sidebar.querySelectorAll('a[href],button,summary')]
                        .filter(element => element.getClientRects().length && !element.disabled);
                    const first = focusable[0], last = focusable[focusable.length - 1];
                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault(); last?.focus();
                    } else if (!event.shiftKey && document.activeElement === last) {
                        event.preventDefault(); first?.focus();
                    }
                }
            });
            small.addEventListener('change', () => {
                // Release focus from a drawer that becomes hidden after a resize.
                const restoreFocus = small.matches && sidebar.contains(document.activeElement);
                setMenu(!small.matches, restoreFocus);
            });
            setMenu(!small.matches);
            menu.querySelectorAll('.nav-group').forEach(group => {
                group.addEventListener('toggle', () => {
                    if (!group.open) return;
                    menu.querySelectorAll('.nav-group[open]').forEach(other => {
                        if (other !== group) other.open = false;
                    });
                });
            });
        }
        document.querySelectorAll('[data-open-disclosure]').forEach(link => {
            link.addEventListener('click', event => {
                const details=document.getElementById(link.dataset.openDisclosure);
                if(!details)return;
                event.preventDefault();details.open=true;details.scrollIntoView({block:'center'});
                details.querySelector('input:not([type="hidden"]),select')?.focus();
            });
        });
        document.addEventListener('invalid', event => {
            for (let parent = event.target.parentElement; parent; parent = parent.parentElement) {
                if (parent.tagName === 'DETAILS') parent.open = true;
            }
        }, true);
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
                    if (!region.hasAttribute('tabindex') && !region.hasAttribute('role') && !region.hasAttribute('aria-label')) {
                        region.dataset.scrollRegion = 'auto';
                    }
                    region.tabIndex = 0;
                    region.setAttribute('role', 'region');
                    if(!region.hasAttribute('aria-label')) {
                        region.setAttribute('aria-label','Tabela com rolagem horizontal');
                    }
                } else if (region.dataset.scrollRegion === 'auto') {
                    region.removeAttribute('tabindex');
                    region.removeAttribute('role');
                    region.removeAttribute('aria-label');
                    delete region.dataset.scrollRegion;
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
    return {decimalUnits, centsText, lineCents, commercialLine, suggestedCents, associateLabels};
})();
