/**
 * RhSheetReview: the shared "check your changes" list shown before a grid saves (owner FEATURE 2026-11-26,
 * .claude/SHEET_VIEW_PLAN.md Bundle A; used by every money column in the stock and menu grids).
 *
 *   RhSheetReview.open({title, changes: [{item, field, old, new, kind: 'money'|'number'|'text'}], onConfirm, onCancel})
 *     Lists every change, money first ("Chicken curry  Price  8,500.00 -> 9,000.00"), a count line ("12 changes,
 *     5 prices") and marks "Check this price" on a money change that moves by more than half either way, or from or
 *     to 0 / blank. Nothing is saved from here except through the Save button: onConfirm() may return a Promise; the
 *     list closes when it resolves and shows the error when it rejects. Esc, the backdrop, Close and Cancel call
 *     onCancel(). Focus stays inside the list while it is open and goes back where it was after.
 *   Pure helpers (also run by scripts/tests/sheet_grid_test.mjs): flag, summary, sortChanges, formatValue.
 * Every text reaches the page through textContent.
 */
(function () {
    'use strict';

    /* ================================================================ logic (pure, no DOM) */
    function num(v) {
        if (v === null || v === undefined || v === '') return 0;
        var x = Number(v);
        return isFinite(x) ? x : NaN;
    }

    /** True for a money change worth a second look: from or to 0 (blank = 0), or a move of more than 50% either way. */
    function flag(ch) {
        if (!ch || ch.kind !== 'money') return false;
        var a = num(ch.old), b = num(ch.new);
        if (isNaN(a) || isNaN(b)) return true;
        if (a === b) return false;
        if (a === 0 || b === 0) return true;
        return Math.abs(b - a) * 2 > Math.abs(a);
    }

    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

    /** "12 changes, 5 prices" (prices = money changes); "3 changes" when no money changed. */
    function summary(changes) {
        var n = changes.length, m = 0;
        changes.forEach(function (c) { if (c.kind === 'money') m++; });
        return plural(n, 'change', 'changes') + (m ? ', ' + plural(m, 'price', 'prices') : '');
    }

    /** Money first, then the rest; the order inside each group is kept. */
    function sortChanges(changes) {
        var money = [], rest = [];
        changes.forEach(function (c) { (c.kind === 'money' ? money : rest).push(c); });
        return money.concat(rest);
    }

    function formatMoney(v) {
        var x = Number(v);
        if (!isFinite(x)) return String(v);
        var s = Math.abs(x).toFixed(6).replace(/0+$/, '');
        var p = s.split('.');
        var frac = p[1] || '';
        while (frac.length < 2) frac += '0';
        var whole = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        var neg = x < 0 && (whole !== '0' || /[1-9]/.test(frac));
        return (neg ? '-' : '') + whole + '.' + frac;
    }
    function formatValue(v, kind) {
        if (v === null || v === undefined || v === '') return '(blank)';
        if (kind === 'money') return formatMoney(v);
        if (kind === 'number') {
            var x = Number(v);
            if (!isFinite(x)) return String(v);
            var s = Math.abs(x).toFixed(6).replace(/0+$/, '').replace(/\.$/, '');
            return (x < 0 && s !== '0' ? '-' : '') + s;
        }
        if (v === true) return 'Yes';
        if (v === false) return 'No';
        return String(v);
    }

    var logic = { flag: flag, summary: summary, sortChanges: sortChanges, formatValue: formatValue };
    if (typeof module === 'object' && module && module.exports) module.exports = logic;
    if (typeof window === 'undefined' || !window.document || window.RhSheetReview) return;

    /* ================================================================ DOM */
    var current = null;
    var uid = 0;

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = String(text);
        return n;
    }
    function focusables(box) {
        return Array.prototype.filter.call(
            box.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
            function (n) { return !n.disabled && !n.hidden && n.offsetParent !== null; }
        );
    }

    function open(opts) {
        opts = opts || {};
        if (current) current.cancel();
        var id = 'rh-review-' + (++uid);
        var list = sortChanges(opts.changes || []);
        var opener = document.activeElement;
        var saving = false;

        var modal = el('div', 'modal modal--lg active rh-review');
        modal.setAttribute('data-disable-auto-pagination', '');
        modal.setAttribute('data-no-ctx', '');
        var backdrop = el('div', 'modal__backdrop');
        var wrapper = el('div', 'modal__wrapper');
        var box = el('div', 'modal__container');
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');
        box.setAttribute('aria-labelledby', id + '-title');
        box.setAttribute('aria-describedby', id + '-count');
        box.tabIndex = -1;

        var head = el('div', 'modal__header');
        var title = el('h2', 'modal__title', opts.title || 'Check your changes');
        title.id = id + '-title';
        var bClose = el('button', 'modal__close');
        bClose.type = 'button';
        bClose.setAttribute('aria-label', 'Close without saving');
        bClose.appendChild(el('span', null, '\u00d7')).setAttribute('aria-hidden', 'true');
        head.appendChild(title);
        head.appendChild(bClose);

        var body = el('div', 'modal__body');
        var count = el('p', 'rh-review__count', summary(list) + '.');
        count.id = id + '-count';
        var flagged = list.filter(flag).length;
        if (flagged) count.appendChild(el('span', 'rh-review__count-check', ' ' + plural(flagged, 'price', 'prices') + ' to check.'));
        body.appendChild(count);
        body.appendChild(el('p', 'rh-review__note', 'Nothing is saved until you press Save.'));
        var ul = el('ul', 'rh-review__list');
        list.forEach(function (c) {
            var li = el('li', 'rh-review__item' + (c.kind === 'money' ? ' is-money' : ''));
            li.appendChild(el('span', 'rh-review__name', c.item));
            li.appendChild(el('span', 'rh-review__field', c.field));
            var vals = el('span', 'rh-review__values');
            vals.appendChild(el('span', 'rh-review__old', formatValue(c.old, c.kind)));
            var arrow = el('span', 'rh-review__arrow', ' -> ');
            vals.appendChild(arrow);
            vals.appendChild(el('span', 'rh-review__new', formatValue(c.new, c.kind)));
            li.appendChild(vals);
            if (flag(c)) {
                li.classList.add('is-flagged');
                li.appendChild(el('span', 'rh-review__flag', 'Check this price'));
            }
            ul.appendChild(li);
        });
        body.appendChild(ul);
        var err = el('p', 'rh-review__error');
        err.setAttribute('role', 'alert');
        err.hidden = true;
        body.appendChild(err);

        var foot = el('div', 'modal__footer');
        var bCancel = el('button', 'rh-sheet__btn', 'Cancel');
        bCancel.type = 'button';
        bCancel.setAttribute('data-review-cancel', '');
        var bSave = el('button', 'rh-sheet__btn rh-sheet__btn--primary', 'Save ' + plural(list.length, 'change', 'changes'));
        bSave.type = 'button';
        bSave.setAttribute('data-review-save', '');
        if (!list.length) bSave.disabled = true;
        foot.appendChild(bCancel);
        foot.appendChild(bSave);

        box.appendChild(head);
        box.appendChild(body);
        box.appendChild(foot);
        wrapper.appendChild(box);
        modal.appendChild(backdrop);
        modal.appendChild(wrapper);

        var prevOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        document.body.appendChild(modal);

        function close() {
            document.removeEventListener('keydown', onKey, true);
            if (modal.parentNode) modal.parentNode.removeChild(modal);
            document.body.style.overflow = prevOverflow;
            if (current === handle) current = null;
            if (opener && opener.focus && document.contains(opener)) {
                try { opener.focus({ preventScroll: true }); } catch (e) { opener.focus(); }
            }
        }
        function cancel() {
            if (saving) return;
            close();
            if (typeof opts.onCancel === 'function') opts.onCancel();
        }
        function save() {
            if (saving || bSave.disabled) return;
            saving = true;
            bSave.disabled = true;
            bCancel.disabled = true;
            bClose.disabled = true;
            bSave.textContent = 'Saving...';
            err.hidden = true;
            var p;
            try { p = Promise.resolve(typeof opts.onConfirm === 'function' ? opts.onConfirm() : true); } catch (e) { p = Promise.reject(e); }
            p.then(function () {
                saving = false;
                close();
            }, function (e) {
                saving = false;
                bSave.disabled = false;
                bCancel.disabled = false;
                bClose.disabled = false;
                bSave.textContent = 'Save ' + plural(list.length, 'change', 'changes');
                err.textContent = 'Not saved: ' + ((e && e.message) ? e.message : 'please try again') + '.';
                err.hidden = false;
                bSave.focus();
            });
        }
        function onKey(e) {
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); cancel(); return; }
            if (e.key === 'Tab') {
                var f = focusables(box);
                if (!f.length) { e.preventDefault(); box.focus(); return; }
                var first = f[0], last = f[f.length - 1];
                if (!box.contains(document.activeElement)) { e.preventDefault(); first.focus(); return; }
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        }

        backdrop.addEventListener('click', cancel);
        bClose.addEventListener('click', cancel);
        bCancel.addEventListener('click', cancel);
        bSave.addEventListener('click', save);
        document.addEventListener('keydown', onKey, true);
        // Cancel takes the first focus, so Enter never saves money by accident.
        bCancel.focus();

        var handle = { close: function () { cancel(); }, cancel: cancel, el: modal };
        current = handle;
        return handle;
    }

    window.RhSheetReview = { open: open, logic: logic };
})();
