/**
 * RhSheetGrid: the shared spreadsheet-style data-entry grid (owner FEATURE 2026-11-26, .claude/SHEET_VIEW_PLAN.md
 * Phase S1, Bundle A). Later screens (stock count, stock items, menu prices...) type into it; it NEVER saves
 * anything itself: the page's onSave(changes) does that through the page's existing save path.
 *
 *  logic   pure helpers (no DOM): clipboard text -> cells, number / money / select / yes-no parsing, the paste,
 *          fill-down and clear plans, undo / redo, the "N cells not pasted" message. Also run by
 *          scripts/tests/sheet_grid_test.mjs (node vm), so every input rule is tested.
 *
 * API
 *   RhSheetGrid.create(container, opts) -> {getChanges(), clearChanges(), setRows(rows), setReadOnly(bool),
 *                                           toolbar (element for page extras, e.g. the presence box), destroy()}
 *     opts.columns   [{key, label, help (text for the "?" on the head), type 'text'|'number'|'money'|'select'|'bool',
 *                     min, max, step, options [{value, label}], readOnly, width (px), filter (bool)}]
 *                     number and money default to min 0 (no negatives); money takes at most 2 decimals; number
 *                     takes at most the decimals of its step (no step = any).
 *     opts.rows      [{id, cells: {key: value}, readOnly: true | [keys]}]   (null = still loading)
 *     opts.rowLabelKey  the column shown first and kept in view (the item name)
 *     opts.readOnly  whole grid read-only (grey)
 *     opts.computed(cells) -> {key: value}  values for read-only computed columns, worked out again after each edit
 *     opts.onSave(changes) -> Promise   changes = [{id, key, old, new, item, field, kind}] (item / field = row and
 *                     column labels, kind 'money' | 'number' | 'text' for the review list). Resolves true (or
 *                     anything but false) = saved: the marks go. Resolves false or rejects = not saved: every change
 *                     stays marked. Editing is blocked while it is pending.
 *     opts.pageSizes [10, 25, 50, 'All'] (default page size 10, RULES.md)
 *     opts.label     accessible name of the grid
 *   getChanges()   every cell whose value differs from the value it had when the rows were set (or last saved)
 *   clearChanges() the current values become the new starting values; marks and the undo history go
 *   RhSheetGrid.wanted()  true at a viewport of 768px or wider (phones keep the page's own list)
 *   RhSheetGrid.viewSwitch(storageKey, {onGrid, onList}) -> {el, mode(), destroy()}  a "Grid view / List view"
 *                     switch; the choice is kept in localStorage (grid by default) and calls onGrid / onList.
 *                     Below 768px it calls onList and stays hidden.
 *
 * Gestures (same as the room grids): click, drag a rectangle, Shift+click range, Ctrl/Cmd+click adds a cell, Esc
 * clears, arrows move (Shift+arrows extend). Type to replace, F2 / double-click edits in place, Enter saves the cell
 * and moves down, Tab right, Shift+Enter / Shift+Tab back, Esc cancels the edit. Delete clears the selected cells
 * that can be changed. Ctrl+C / Ctrl+V copy and paste tab-separated text (Excel). Ctrl+Z / Ctrl+Y undo / redo
 * edits that are not saved yet. Right-click: Copy, Paste, Clear, Fill down (admin/js/admin-context-menu.js).
 * A blank pasted cell is skipped (never clears); clearing is only Delete or Clear. Text reaches the page only
 * through textContent.
 */
(function () {
    'use strict';

    /* ================================================================ logic (pure, no DOM) */
    var CURRENCY = /^(MWK|MK|K|\$)\s*/i;

    function isBlank(t) {
        return t === null || t === undefined || String(t).replace(/[\s\u00a0\u202f]+/g, '') === '';
    }

    /** Excel / Google Sheets clipboard text (tab-separated) -> rows of cell strings. One trailing newline is the end
        of the last row, not a blank row. A cell that starts with " is quoted: tabs, newlines and "" inside it belong
        to the cell. A " anywhere else is literal (12" pizza). */
    function parseClipboard(text) {
        var s = String(text === null || text === undefined ? '' : text);
        var rows = [], row = [], cell = '', i = 0, n = s.length, atStart = true;
        if (s === '') return rows;
        while (i < n) {
            var ch = s.charAt(i);
            if (atStart && ch === '"') {
                var j = i + 1, q = '', closed = false;
                while (j < n) {
                    if (s.charAt(j) === '"') {
                        if (s.charAt(j + 1) === '"') { q += '"'; j += 2; continue; }
                        closed = true; j++; break;
                    }
                    q += s.charAt(j); j++;
                }
                if (closed) { cell = q; i = j; atStart = false; continue; }
                // No closing quote: the quote is just a character.
                cell = '"'; i++; atStart = false; continue;
            }
            if (ch === '\t') { row.push(cell); cell = ''; atStart = true; i++; continue; }
            if (ch === '\r' || ch === '\n') {
                row.push(cell); rows.push(row); row = []; cell = ''; atStart = true;
                i += (ch === '\r' && s.charAt(i + 1) === '\n') ? 2 : 1;
                continue;
            }
            cell += ch; atStart = false; i++;
        }
        if (!(atStart && cell === '' && row.length === 0)) { row.push(cell); rows.push(row); }
        return rows;
    }

    /** Rows of cell strings -> tab-separated text Excel reads back (cells with a tab, newline or leading " quoted). */
    function toTSV(matrix) {
        return matrix.map(function (r) {
            return r.map(function (c) {
                var v = c === null || c === undefined ? '' : String(c);
                return /[\t\r\n]/.test(v) || v.charAt(0) === '"' ? '"' + v.replace(/"/g, '""') + '"' : v;
            }).join('\t');
        }).join('\n');
    }

    /** Decimals allowed by a step (from its written form): 0.01 -> 2, 1 -> 0, none / 'any' -> null (no limit). */
    function stepDecimals(step) {
        if (step === null || step === undefined || step === '' || step === 'any') return null;
        var s = String(step).toLowerCase();
        var m = s.match(/^(\d*)(?:\.(\d*))?e-(\d+)$/);
        if (m) return (m[2] || '').length + Number(m[3]);
        var p = s.split('.');
        return p[1] ? p[1].length : 0;
    }

    function plainNumber(v) {
        var x = Number(v);
        if (!isFinite(x)) return String(v);
        var s = Math.abs(x).toFixed(6).replace(/0+$/, '').replace(/\.$/, '');
        if (s === '0') return '0';
        return (x < 0 ? '-' : '') + s;
    }

    /** Money for display: thousands commas, at least 2 decimals, extra decimals kept (an old cost of 0.0125 stays
        0.0125, never hidden). Fixed rules, no locale. */
    function formatMoney(v) {
        var x = Number(v);
        if (v === null || v === undefined || v === '' || !isFinite(x)) return v === null || v === undefined ? '' : String(v);
        var s = Math.abs(x).toFixed(6).replace(/0+$/, '');
        var p = s.split('.');
        var frac = p[1] || '';
        while (frac.length < 2) frac += '0';
        var whole = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        var neg = x < 0 && (whole !== '0' || /[1-9]/.test(frac));
        return (neg ? '-' : '') + whole + '.' + frac;
    }

    /** One typed or pasted number. Returns {ok, value} or {ok:false, reason} ({blank:true} for an empty cell).
        Commas only as thousands separators in groups of three; anything else unclear is refused, never guessed. */
    function parseNumber(text, col) {
        col = col || {};
        var s = String(text === null || text === undefined ? '' : text).replace(/[\u00a0\u202f]/g, ' ').trim();
        if (s === '') return { ok: false, blank: true, reason: 'is blank' };
        var neg = false;
        if (s.charAt(0) === '-') { neg = true; s = s.slice(1).trim(); }
        var m = s.match(CURRENCY);
        if (m) s = s.slice(m[0].length);
        if (!neg && s.charAt(0) === '-') { neg = true; s = s.slice(1); }
        var grouped = /^[1-9]\d{0,2}(,\d{3})+(\.\d+)?$/;
        var plain = /^(\d+(\.\d+)?|\.\d+)$/;
        if (!grouped.test(s) && !plain.test(s)) return { ok: false, reason: 'is not a clear number' };
        var digits = s.replace(/,/g, '');
        var dec = (digits.split('.')[1] || '').length;
        var min = (col.min === null || col.min === undefined || col.min === '') ? 0 : Number(col.min);
        if (neg && min >= 0) return { ok: false, reason: 'cannot be below ' + plainNumber(min) };
        if (col.type === 'money') {
            if (dec > 2) return { ok: false, reason: 'has more than 2 decimals' };
        } else {
            var allowed = stepDecimals(col.step);
            if (allowed !== null && dec > allowed) {
                return { ok: false, reason: allowed === 0 ? 'must be a whole number' : 'has more than ' + allowed + ' decimals' };
            }
        }
        var v = Number(digits);
        if (!isFinite(v)) return { ok: false, reason: 'is not a clear number' };
        if (neg && v !== 0) v = -v;
        if (v < min) return { ok: false, reason: 'cannot be below ' + plainNumber(min) };
        if (col.max !== null && col.max !== undefined && col.max !== '' && v > Number(col.max)) {
            return { ok: false, reason: 'cannot be above ' + plainNumber(col.max) };
        }
        return { ok: true, value: v };
    }

    var YES = ['yes', 'true', '1', 'y'], NO = ['no', 'false', '0', 'n'];

    /** One typed or pasted cell for a column of any type. */
    function parseCell(text, col) {
        col = col || {};
        if (isBlank(text)) return { ok: false, blank: true, reason: 'is blank' };
        var t = String(text).replace(/[\u00a0\u202f]/g, ' ').trim();
        var type = col.type || 'text';
        if (type === 'number' || type === 'money') return parseNumber(t, col);
        if (type === 'select') {
            var low = t.toLowerCase(), opts = col.options || [];
            for (var i = 0; i < opts.length; i++) {
                if (String(opts[i].value).trim().toLowerCase() === low || String(opts[i].label).trim().toLowerCase() === low) {
                    return { ok: true, value: opts[i].value };
                }
            }
            return { ok: false, reason: 'is not one of the choices' };
        }
        if (type === 'bool') {
            var b = t.toLowerCase();
            if (YES.indexOf(b) >= 0) return { ok: true, value: true };
            if (NO.indexOf(b) >= 0) return { ok: true, value: false };
            return { ok: false, reason: 'is not yes or no' };
        }
        if (col.maxLength && t.length > col.maxLength) return { ok: false, reason: 'is longer than ' + col.maxLength + ' characters' };
        return { ok: true, value: t };
    }

    /** A value as the cell shows it (and as Copy writes it). */
    function formatValue(v, col) {
        col = col || {};
        if (v === null || v === undefined || v === '') return '';
        var type = col.type || 'text';
        if (type === 'money') return formatMoney(v);
        if (type === 'number') return plainNumber(v);
        if (type === 'bool') return (v === true || v === 1 || v === '1' || String(v).toLowerCase() === 'yes' || String(v).toLowerCase() === 'true') ? 'Yes' : 'No';
        if (type === 'select') {
            var opts = col.options || [];
            for (var i = 0; i < opts.length; i++) if (String(opts[i].value) === String(v)) return String(opts[i].label);
            return String(v);
        }
        return String(v);
    }

    function sameValue(a, b, col) {
        var ab = a === null || a === undefined || a === '', bb = b === null || b === undefined || b === '';
        if (ab || bb) return ab && bb;
        var type = (col && col.type) || 'text';
        if (type === 'number' || type === 'money') return Number(a) === Number(b);
        if (type === 'bool') return formatValue(a, col) === formatValue(b, col);
        return String(a) === String(b);
    }

    /** True when the cell may not be changed: whole grid, column, computed column, whole row or listed key. */
    function cellReadOnly(snap, row, col) {
        if (snap.readOnly || col.readOnly || col.computed) return true;
        if (row.readOnly === true) return true;
        return Array.isArray(row.readOnly) && row.readOnly.indexOf(col.key) >= 0;
    }

    function planCell(snap, r, c, text, out) {
        var rows = snap.rows, cols = snap.columns;
        if (isBlank(text)) return;
        if (r >= rows.length || c >= cols.length) {
            out.skipped.push({
                id: null, key: null, text: String(text),
                rowLabel: r < rows.length ? rows[r].label : null,
                colLabel: c < cols.length ? cols[c].label : null,
                reason: r >= rows.length ? 'is past the last row shown' : 'is past the last column', edge: true
            });
            return;
        }
        var row = rows[r], col = cols[c];
        var base = { id: row.id, key: col.key, text: String(text), rowLabel: row.label, colLabel: col.label };
        if (cellReadOnly(snap, row, col)) { base.reason = 'cannot be changed here'; out.skipped.push(base); return; }
        var p = parseCell(text, col);
        if (!p.ok) { base.reason = p.reason; out.skipped.push(base); return; }
        var old = row.cells[col.key];
        if (sameValue(old, p.value, col)) return;
        out.apply.push({ id: row.id, key: col.key, old: old === undefined ? null : old, new: p.value });
    }

    /** Paste plan. snap = {columns (shown order), rows [{id, label, cells, readOnly}] (shown rows), readOnly};
        start = {r, c} (indices in snap); matrix = parseClipboard(...). Pure: nothing is changed. */
    function planPaste(snap, start, matrix) {
        var out = { apply: [], skipped: [] };
        for (var i = 0; i < matrix.length; i++) {
            for (var j = 0; j < matrix[i].length; j++) planCell(snap, start.r + i, start.c + j, matrix[i][j], out);
        }
        return out;
    }

    /** Fill down: the top cell of each column of the rectangle {r1, c1, r2, c2} is copied into the cells below it,
        through the same checks as a paste. A blank top cell fills nothing (clearing is only Delete / Clear). */
    function planFill(snap, rect) {
        var out = { apply: [], skipped: [] };
        var R = normRect(rect);
        for (var c = R.c1; c <= R.c2 && c < snap.columns.length; c++) {
            if (R.r1 >= snap.rows.length) break;
            var col = snap.columns[c];
            var text = formatValue(snap.rows[R.r1].cells[col.key], col);
            if (isBlank(text)) continue;
            for (var r = R.r1 + 1; r <= R.r2 && r < snap.rows.length; r++) planCell(snap, r, c, text, out);
        }
        return out;
    }

    /** Clear (Delete key / Clear menu): every listed cell that may be changed and is not already blank. */
    function planClear(snap, cells) {
        var out = { apply: [], skipped: [] };
        cells.forEach(function (rc) {
            var row = snap.rows[rc.r], col = snap.columns[rc.c];
            if (!row || !col) return;
            if (cellReadOnly(snap, row, col)) { out.skipped.push({ id: row.id, key: col.key, reason: 'cannot be changed here' }); return; }
            var old = row.cells[col.key];
            if (old === null || old === undefined || old === '') return;
            out.apply.push({ id: row.id, key: col.key, old: old, new: null });
        });
        return out;
    }

    /** Apply a list of changes to {id: cells}. Pure apart from the given object. */
    function applyBatch(cellsById, batch) {
        batch.forEach(function (ch) { if (cellsById[ch.id]) cellsById[ch.id][ch.key] = ch.new; });
        return cellsById;
    }

    /** Undo / redo of batches (one paste, fill or clear = one step). A new step empties redo. */
    function createHistory(limit) {
        var undoS = [], redoS = [], max = limit || 200;
        function inv(b) {
            return b.slice().reverse().map(function (ch) { return { id: ch.id, key: ch.key, old: ch.new, new: ch.old }; });
        }
        return {
            push: function (batch) {
                if (!batch || !batch.length) return;
                undoS.push(batch.slice());
                if (undoS.length > max) undoS.shift();
                redoS = [];
            },
            undo: function () { var b = undoS.pop(); if (!b) return null; redoS.push(b); return inv(b); },
            redo: function () { var b = redoS.pop(); if (!b) return null; undoS.push(b); return b.slice(); },
            canUndo: function () { return undoS.length > 0; },
            canRedo: function () { return redoS.length > 0; },
            clear: function () { undoS = []; redoS = []; }
        };
    }

    /** Every editable cell whose value differs from its starting value. rows = [{id, label, base, cells}]. */
    function diffChanges(rows, columns) {
        var out = [];
        rows.forEach(function (row) {
            columns.forEach(function (col) {
                if (col.readOnly || col.computed) return;
                var a = row.base[col.key], b = row.cells[col.key];
                if (sameValue(a, b, col)) return;
                out.push({
                    id: row.id, key: col.key,
                    old: a === undefined ? null : a, new: b === undefined ? null : b,
                    item: row.label, field: col.label,
                    kind: col.type === 'money' ? 'money' : (col.type === 'number' ? 'number' : 'text')
                });
            });
        });
        return out;
    }

    /** "3 cells not pasted: Row 'Gin' Counted: '8,5' is not a clear number; ..." (first `limit` listed). */
    function skippedMessage(skipped, verb, limit) {
        var n = skipped.length;
        if (!n) return '';
        var max = limit || 8;
        var parts = skipped.slice(0, max).map(function (s) {
            var where = (s.rowLabel !== null && s.rowLabel !== undefined ? "Row '" + s.rowLabel + "' " : '')
                + (s.colLabel ? s.colLabel + ': ' : '');
            return where + (s.text !== undefined ? "'" + s.text + "' " : '') + s.reason;
        });
        if (n > max) parts.push('and ' + (n - max) + ' more');
        return n + (n === 1 ? ' cell' : ' cells') + ' not ' + (verb || 'pasted') + ': ' + parts.join('; ');
    }

    function normRect(a) {
        return {
            r1: Math.min(a.r1, a.r2), r2: Math.max(a.r1, a.r2),
            c1: Math.min(a.c1, a.c2), c2: Math.max(a.c1, a.c2)
        };
    }
    function rectCells(rects) {
        var seen = {}, out = [];
        rects.forEach(function (x) {
            var R = normRect(x);
            for (var r = R.r1; r <= R.r2; r++) for (var c = R.c1; c <= R.c2; c++) {
                var k = r + ':' + c;
                if (!seen[k]) { seen[k] = true; out.push({ r: r, c: c }); }
            }
        });
        return out;
    }

    /** Filter box (every word must appear in the row's shown text) + column filters {key: '' | '\u0000blank' |
        '\u0000set' | 'v:<value>'}. */
    function filterRows(rows, columns, query, colFilters) {
        var words = String(query || '').toLowerCase().split(/\s+/).filter(Boolean);
        var fkeys = Object.keys(colFilters || {}).filter(function (k) { return colFilters[k]; });
        var byKey = {};
        columns.forEach(function (c) { byKey[c.key] = c; });
        return rows.filter(function (row) {
            for (var i = 0; i < fkeys.length; i++) {
                var f = colFilters[fkeys[i]], v = row.cells[fkeys[i]];
                var blank = v === null || v === undefined || v === '';
                if (f === '\u0000blank' && !blank) return false;
                if (f === '\u0000set' && blank) return false;
                if (f.indexOf('v:') === 0 && (blank || !sameValue(v, f.slice(2), byKey[fkeys[i]]))) return false;
            }
            if (!words.length) return true;
            var hay = columns.map(function (c) { return formatValue(row.cells[c.key], c); }).join(' \u0001 ').toLowerCase();
            return words.every(function (w) { return hay.indexOf(w) >= 0; });
        });
    }

    /** Page window: size 0 / 'All' = every row. */
    function pageSlice(total, page, size) {
        var all = !size || size === 'All' || size === 0;
        var per = all ? Math.max(total, 1) : Number(size);
        var pages = Math.max(1, Math.ceil(total / per));
        var p = Math.min(Math.max(1, page || 1), pages);
        var start = all ? 0 : (p - 1) * per;
        return { start: start, end: Math.min(total, start + per), page: p, pages: pages };
    }

    var logic = {
        isBlank: isBlank, parseClipboard: parseClipboard, toTSV: toTSV, stepDecimals: stepDecimals,
        parseNumber: parseNumber, parseCell: parseCell, formatValue: formatValue, formatMoney: formatMoney,
        plainNumber: plainNumber, sameValue: sameValue, cellReadOnly: cellReadOnly, planPaste: planPaste,
        planFill: planFill, planClear: planClear, applyBatch: applyBatch, createHistory: createHistory,
        diffChanges: diffChanges, skippedMessage: skippedMessage, normRect: normRect, rectCells: rectCells,
        filterRows: filterRows, pageSlice: pageSlice
    };
    if (typeof module === 'object' && module && module.exports) module.exports = logic;
    if (typeof window === 'undefined' || !window.document || window.RhSheetGrid) return;

    /* ================================================================ DOM */
    var instances = [];
    var menuRegistered = false;

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = String(text);
        return n;
    }
    function icon(name) {
        var i = el('i', 'fas ' + name);
        i.setAttribute('aria-hidden', 'true');
        return i;
    }
    function button(label, cls, iconName) {
        var b = el('button', 'rh-sheet__btn' + (cls ? ' ' + cls : ''));
        b.type = 'button';
        if (iconName) b.appendChild(icon(iconName));
        b.appendChild(el('span', null, label));
        return b;
    }
    function wanted() {
        try { return !!(window.matchMedia && window.matchMedia('(min-width: 768px)').matches); } catch (e) { return window.innerWidth >= 768; }
    }

    function registerMenu() {
        if (menuRegistered || !window.RhContextMenu) return;
        menuRegistered = true;
        window.RhContextMenu.register('.rh-sheet__table td.rh-sheet__cell', function (td) {
            for (var i = 0; i < instances.length; i++) {
                if (instances[i].root.contains(td)) return instances[i].menuFor(td);
            }
            return null;
        });
    }

    var uid = 0;

    function create(container, opts) {
        opts = opts || {};
        registerMenu();
        var gid = 'rh-sheet-' + (++uid);
        var sizes = (opts.pageSizes && opts.pageSizes.length) ? opts.pageSizes : [10, 25, 50, 'All'];
        var st = {
            columns: [], rows: null, byId: {}, view: [], pageRows: [], page: 1, size: sizes[0] === 'All' ? 0 : Number(sizes[0]),
            query: '', colFilters: {}, rects: [], active: { r: 0, c: 0 }, anchor: { r: 0, c: 0 },
            editing: null, saving: false, readOnly: !!opts.readOnly, dragging: false, clip: ''
        };
        var history = createHistory(200);
        var computedKeys = {};

        // Columns: the row label column first.
        var cols = (opts.columns || []).slice();
        if (opts.rowLabelKey) {
            var li = -1;
            cols.forEach(function (c, i) { if (c.key === opts.rowLabelKey) li = i; });
            if (li > 0) cols.unshift(cols.splice(li, 1)[0]);
        }
        st.columns = cols.map(function (c) {
            var o = {};
            Object.keys(c).forEach(function (k) { o[k] = c[k]; });
            o.type = o.type || 'text';
            return o;
        });

        /* ---------- markup */
        var root = el('div', 'rh-sheet');
        root.id = gid;
        root.setAttribute('data-sheet-grid', '');
        root.setAttribute('data-disable-auto-pagination', '');
        root.setAttribute('data-no-page-size-picker', '');

        var bar = el('div', 'rh-sheet__toolbar');
        var fWrap = el('label', 'rh-sheet__field rh-sheet__field--search');
        fWrap.appendChild(el('span', 'rh-sheet__field-label', 'Filter'));
        var fInput = el('input', 'rh-sheet__input');
        fInput.type = 'search';
        fInput.placeholder = 'Type to filter rows';
        fInput.setAttribute('aria-controls', gid + '-table');
        fWrap.appendChild(fInput);
        bar.appendChild(fWrap);

        var colFilterEls = [];
        st.columns.forEach(function (col) {
            if (!col.filter) return;
            var w = el('label', 'rh-sheet__field');
            w.appendChild(el('span', 'rh-sheet__field-label', col.label));
            var s = el('select', 'rh-sheet__input');
            s.setAttribute('data-filter-key', col.key);
            w.appendChild(s);
            bar.appendChild(w);
            colFilterEls.push({ col: col, select: s });
            s.addEventListener('change', function () {
                st.colFilters[col.key] = s.value;
                refilter();
            });
        });

        var extra = el('div', 'rh-sheet__extra');
        bar.appendChild(extra);

        var actions = el('div', 'rh-sheet__actions');
        var bUndo = button('Undo', null, 'fa-rotate-left');
        var bRedo = button('Redo', null, 'fa-rotate-right');
        bUndo.setAttribute('aria-keyshortcuts', 'Control+Z');
        bRedo.setAttribute('aria-keyshortcuts', 'Control+Y');
        var bSave = button('Save changes', 'rh-sheet__btn--primary', 'fa-floppy-disk');
        bSave.setAttribute('data-sheet-save', '');
        var saveCount = el('span', 'rh-sheet__count');
        bSave.appendChild(saveCount);
        actions.appendChild(bUndo);
        actions.appendChild(bRedo);
        if (typeof opts.onSave === 'function') actions.appendChild(bSave);
        bar.appendChild(actions);
        root.appendChild(bar);

        var msg = el('div', 'rh-sheet__msg');
        msg.setAttribute('role', 'status');
        msg.setAttribute('aria-live', 'polite');
        msg.hidden = true;
        var msgText = el('span', 'rh-sheet__msg-text');
        var msgClose = el('button', 'rh-sheet__msg-close', 'Dismiss');
        msgClose.type = 'button';
        msg.appendChild(msgText);
        msg.appendChild(msgClose);
        root.appendChild(msg);

        var scroller = el('div', 'rh-sheet__scroll');
        var table = el('table', 'rh-sheet__table');
        table.id = gid + '-table';
        table.setAttribute('role', 'grid');
        table.setAttribute('aria-multiselectable', 'true');
        table.setAttribute('aria-label', opts.label || 'Sheet');
        table.setAttribute('aria-colcount', String(st.columns.length));
        var thead = el('thead');
        var htr = el('tr');
        htr.setAttribute('aria-rowindex', '1');
        st.columns.forEach(function (col, ci) {
            var th = el('th', 'rh-sheet__head' + (ci === 0 ? ' rh-sheet__first' : '') + (col.type === 'number' || col.type === 'money' ? ' is-num' : ''));
            th.setAttribute('role', 'columnheader');
            th.setAttribute('scope', 'col');
            th.setAttribute('aria-colindex', String(ci + 1));
            if (col.width) th.style.minWidth = Number(col.width) + 'px';
            th.appendChild(el('span', 'rh-sheet__head-label', col.label));
            if (col.help) {
                var q = el('i', 'help', '?');
                q.setAttribute('tabindex', '0');
                q.setAttribute('role', 'button');
                q.setAttribute('aria-label', 'About ' + col.label);
                q.setAttribute('data-tip', col.help);
                th.appendChild(q);
            }
            htr.appendChild(th);
        });
        thead.appendChild(htr);
        var tbody = el('tbody');
        table.appendChild(thead);
        table.appendChild(tbody);
        scroller.appendChild(table);
        root.appendChild(scroller);

        var pager = el('div', 'rh-sheet__pager');
        var szWrap = el('label', 'rh-sheet__field rh-sheet__field--inline');
        szWrap.appendChild(el('span', 'rh-sheet__field-label', 'Rows per page'));
        var szSel = el('select', 'rh-sheet__input');
        szSel.setAttribute('data-sheet-size', '');
        sizes.forEach(function (s) {
            var o = el('option', null, s === 'All' || s === 0 ? 'All' : String(s));
            o.value = s === 'All' ? '0' : String(s);
            szSel.appendChild(o);
        });
        szSel.value = String(st.size);
        szWrap.appendChild(szSel);
        var summary = el('span', 'rh-sheet__summary');
        summary.setAttribute('aria-live', 'polite');
        var bPrev = button('Previous', null, 'fa-chevron-left');
        var bNext = button('Next', null, 'fa-chevron-right');
        pager.appendChild(szWrap);
        pager.appendChild(summary);
        pager.appendChild(bPrev);
        pager.appendChild(bNext);
        root.appendChild(pager);

        container.appendChild(root);

        /* ---------- data */
        function rowLabel(row) {
            var col = st.columns[0];
            var v = row.cells[col.key];
            return v === null || v === undefined || v === '' ? String(row.id) : formatValue(v, col);
        }
        function runComputed(row) {
            if (typeof opts.computed !== 'function') return;
            var copy = {};
            Object.keys(row.cells).forEach(function (k) { copy[k] = row.cells[k]; });
            var out = null;
            try { out = opts.computed(copy); } catch (e) { out = null; }
            if (!out) return;
            Object.keys(out).forEach(function (k) { row.cells[k] = out[k]; computedKeys[k] = true; });
        }
        function markComputedCols() {
            st.columns.forEach(function (c) { if (computedKeys[c.key]) c.computed = true; });
        }
        function loadRows(rows) {
            st.byId = {};
            if (rows === null || rows === undefined) { st.rows = null; return; }
            st.rows = rows.map(function (r) {
                var cells = {};
                Object.keys(r.cells || {}).forEach(function (k) { cells[k] = r.cells[k]; });
                var row = { id: r.id, cells: cells, readOnly: r.readOnly || false, base: null, label: '' };
                runComputed(row);
                row.base = {};
                Object.keys(row.cells).forEach(function (k) { row.base[k] = row.cells[k]; });
                row.label = rowLabel(row);
                st.byId[String(r.id)] = row;
                return row;
            });
            markComputedCols();
        }

        function fillColumnFilters() {
            colFilterEls.forEach(function (f) {
                var col = f.col, s = f.select, keep = s.value;
                while (s.firstChild) s.removeChild(s.firstChild);
                function add(v, t) { var o = el('option', null, t); o.value = v; s.appendChild(o); }
                add('', 'All');
                if (col.type === 'select') {
                    (col.options || []).forEach(function (o) { add('v:' + o.value, String(o.label)); });
                } else if (col.type === 'bool') {
                    add('v:yes', 'Yes'); add('v:no', 'No');
                } else if (col.type === 'text') {
                    var seen = {}, vals = [];
                    (st.rows || []).forEach(function (r) {
                        var v = r.cells[col.key];
                        if (v === null || v === undefined || v === '') return;
                        var k = String(v);
                        if (!seen[k]) { seen[k] = true; vals.push(k); }
                    });
                    vals.sort(function (a, b) { return a.toLowerCase() < b.toLowerCase() ? -1 : 1; });
                    vals.slice(0, 200).forEach(function (v) { add('v:' + v, v); });
                }
                add('\u0000set', col.type === 'number' || col.type === 'money' ? 'Filled in' : 'Not empty');
                add('\u0000blank', col.type === 'number' || col.type === 'money' ? 'Not filled in' : 'Empty');
                s.value = keep;
                if (s.value !== keep) s.value = '';
                st.colFilters[col.key] = s.value;
            });
        }

        function snapshot() {
            return {
                readOnly: st.readOnly || st.saving,
                columns: st.columns,
                rows: st.pageRows.map(function (r) { return { id: r.id, label: r.label, cells: r.cells, readOnly: r.readOnly }; })
            };
        }

        /* ---------- render */
        function refilter() {
            cancelEdit();
            st.view = st.rows ? filterRows(st.rows, st.columns, st.query, st.colFilters) : [];
            st.page = 1;
            st.rects = [];
            st.active = { r: 0, c: 0 };
            st.anchor = { r: 0, c: 0 };
            render();
        }

        function isRO(row, col) {
            return cellReadOnly({ readOnly: st.readOnly }, row, col);
        }

        function render() {
            var hadFocus = root.contains(document.activeElement) && document.activeElement.tagName === 'TD';
            while (tbody.firstChild) tbody.removeChild(tbody.firstChild);
            root.classList.toggle('is-readonly', st.readOnly);
            root.classList.toggle('is-saving', st.saving);
            if (st.rows === null) {
                stateRow('Loading rows...');
                st.pageRows = [];
                updatePager({ start: 0, end: 0, page: 1, pages: 1 });
                updateButtons();
                return;
            }
            var sl = pageSlice(st.view.length, st.page, st.size);
            st.page = sl.page;
            st.pageRows = st.view.slice(sl.start, sl.end);
            table.setAttribute('aria-rowcount', String(st.view.length + 1));
            if (!st.rows.length) stateRow('No rows yet.');
            else if (!st.view.length) stateRow('No rows match the filter.', true);
            var sel = {};
            rectCells(st.rects).forEach(function (x) { sel[x.r + ':' + x.c] = true; });
            st.pageRows.forEach(function (row, ri) {
                var tr = el('tr', 'rh-sheet__row');
                tr.setAttribute('aria-rowindex', String(sl.start + ri + 2));
                if (row.readOnly === true) tr.classList.add('is-ro-row');
                st.columns.forEach(function (col, ci) {
                    var v = row.cells[col.key];
                    var td = el('td', 'rh-sheet__cell', formatValue(v, col));
                    td.setAttribute('role', ci === 0 ? 'rowheader' : 'gridcell');
                    td.setAttribute('aria-colindex', String(ci + 1));
                    td.setAttribute('data-r', String(ri));
                    td.setAttribute('data-c', String(ci));
                    td.setAttribute('data-key', col.key);
                    td.tabIndex = (ri === st.active.r && ci === st.active.c) ? 0 : -1;
                    if (ci === 0) td.classList.add('rh-sheet__first');
                    if (col.type === 'number' || col.type === 'money') td.classList.add('is-num');
                    if (isRO(row, col)) { td.classList.add('is-ro'); td.setAttribute('aria-readonly', 'true'); }
                    if (!col.readOnly && !col.computed && !sameValue(row.base[col.key], v, col)) {
                        td.classList.add('is-changed');
                        td.setAttribute('aria-description', 'Changed, not saved yet');
                    }
                    var isSel = !!sel[ri + ':' + ci];
                    td.setAttribute('aria-selected', isSel ? 'true' : 'false');
                    if (isSel) td.classList.add('is-selected');
                    if (ri === st.active.r && ci === st.active.c) td.classList.add('is-active');
                    tr.appendChild(td);
                });
                tbody.appendChild(tr);
            });
            updatePager(sl);
            updateButtons();
            var first = thead.querySelector('th');
            if (first) scroller.style.scrollPaddingLeft = first.offsetWidth + 'px';
            if (hadFocus) focusActive();
        }

        function stateRow(text, withClear) {
            var tr = el('tr', 'rh-sheet__state-row');
            var td = el('td', 'rh-sheet__state', text);
            td.colSpan = st.columns.length;
            if (withClear) {
                var b = button('Clear filters');
                b.addEventListener('click', clearFilters);
                td.appendChild(document.createTextNode(' '));
                td.appendChild(b);
            }
            tr.appendChild(td);
            tbody.appendChild(tr);
        }

        function clearFilters() {
            fInput.value = '';
            st.query = '';
            colFilterEls.forEach(function (f) { f.select.value = ''; st.colFilters[f.col.key] = ''; });
            refilter();
        }

        function updatePager(sl) {
            var total = st.view.length;
            summary.textContent = total ? ('Rows ' + (sl.start + 1) + ' to ' + sl.end + ' of ' + total) : '0 rows';
            bPrev.disabled = sl.page <= 1;
            bNext.disabled = sl.page >= sl.pages;
            var showNav = sl.pages > 1;
            bPrev.hidden = !showNav;
            bNext.hidden = !showNav;
        }

        function updateButtons() {
            var n = st.rows ? diffChanges(st.rows, st.columns).length : 0;
            bSave.disabled = n === 0 || st.saving || st.readOnly;
            saveCount.textContent = n ? ' (' + n + ')' : '';
            bSave.setAttribute('aria-label', n ? 'Save ' + n + (n === 1 ? ' change' : ' changes') : 'Save changes (nothing changed yet)');
            bUndo.disabled = !history.canUndo() || st.saving || st.readOnly;
            bRedo.disabled = !history.canRedo() || st.saving || st.readOnly;
        }

        function cellEl(r, c) {
            return tbody.querySelector('td[data-r="' + r + '"][data-c="' + c + '"]');
        }
        function focusActive() {
            var td = cellEl(st.active.r, st.active.c);
            if (!td) return;
            var old = tbody.querySelectorAll('td[tabindex="0"]');
            for (var i = 0; i < old.length; i++) if (old[i] !== td) old[i].tabIndex = -1;
            td.tabIndex = 0;
            try { td.focus({ preventScroll: true }); } catch (e) { td.focus(); }
            if (td.scrollIntoView) td.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        }

        function showMsg(text, kind) {
            msgText.textContent = text;
            msg.className = 'rh-sheet__msg' + (kind ? ' is-' + kind : '');
            msg.hidden = !text;
        }

        /* ---------- selection */
        function select(r, c, mode) {
            var maxR = st.pageRows.length - 1, maxC = st.columns.length - 1;
            if (maxR < 0) return;
            r = Math.max(0, Math.min(maxR, r));
            c = Math.max(0, Math.min(maxC, c));
            if (mode === 'extend') {
                var a = st.anchor;
                if (st.rects.length) st.rects[st.rects.length - 1] = { r1: a.r, c1: a.c, r2: r, c2: c };
                else st.rects = [{ r1: a.r, c1: a.c, r2: r, c2: c }];
            } else if (mode === 'add') {
                st.rects.push({ r1: r, c1: c, r2: r, c2: c });
                st.anchor = { r: r, c: c };
            } else {
                st.rects = [{ r1: r, c1: c, r2: r, c2: c }];
                st.anchor = { r: r, c: c };
            }
            st.active = { r: r, c: c };
            paintSelection();
            focusActive();
        }
        function paintSelection() {
            var sel = {};
            rectCells(st.rects).forEach(function (x) { sel[x.r + ':' + x.c] = true; });
            var tds = tbody.querySelectorAll('td.rh-sheet__cell');
            for (var i = 0; i < tds.length; i++) {
                var td = tds[i], k = td.getAttribute('data-r') + ':' + td.getAttribute('data-c');
                var on = !!sel[k];
                td.classList.toggle('is-selected', on);
                td.setAttribute('aria-selected', on ? 'true' : 'false');
                td.classList.toggle('is-active', +td.getAttribute('data-r') === st.active.r && +td.getAttribute('data-c') === st.active.c);
            }
        }
        function lastRect() {
            if (st.rects.length) return normRect(st.rects[st.rects.length - 1]);
            return { r1: st.active.r, c1: st.active.c, r2: st.active.r, c2: st.active.c };
        }
        function selectedCells() {
            return st.rects.length ? rectCells(st.rects) : [{ r: st.active.r, c: st.active.c }];
        }

        /* ---------- changes */
        function blocked() {
            if (st.readOnly) { showMsg('This sheet is read-only: nothing here can be changed.', 'warn'); return true; }
            if (st.saving) { showMsg('Saving: wait a moment before changing anything else.', 'warn'); return true; }
            return false;
        }
        function applyChanges(batch, record) {
            if (!batch || !batch.length) return;
            var touched = {};
            batch.forEach(function (ch) {
                var row = st.byId[String(ch.id)];
                if (!row) return;
                row.cells[ch.key] = ch.new;
                touched[String(ch.id)] = row;
            });
            Object.keys(touched).forEach(function (k) { runComputed(touched[k]); touched[k].label = rowLabel(touched[k]); });
            if (record) history.push(batch);
            render();
        }
        function doUndo() {
            if (blocked()) return;
            var b = history.undo();
            if (!b) return;
            applyChanges(b, false);
            showMsg('Undone: ' + b.length + (b.length === 1 ? ' cell' : ' cells') + '.', '');
        }
        function doRedo() {
            if (blocked()) return;
            var b = history.redo();
            if (!b) return;
            applyChanges(b, false);
            showMsg('Done again: ' + b.length + (b.length === 1 ? ' cell' : ' cells') + '.', '');
        }
        function doPaste(text) {
            if (blocked()) return;
            var matrix = parseClipboard(text);
            if (!matrix.length) { showMsg('There was nothing to paste.', 'warn'); return; }
            var R = lastRect();
            var plan = planPaste(snapshot(), { r: R.r1, c: R.c1 }, matrix);
            applyChanges(plan.apply, true);
            var h = matrix.length, w = 0;
            matrix.forEach(function (r) { w = Math.max(w, r.length); });
            st.rects = [{ r1: R.r1, c1: R.c1, r2: Math.min(R.r1 + h - 1, st.pageRows.length - 1), c2: Math.min(R.c1 + w - 1, st.columns.length - 1) }];
            st.anchor = { r: R.r1, c: R.c1 };
            st.active = { r: R.r1, c: R.c1 };
            paintSelection();
            focusActive();
            var done = plan.apply.length + (plan.apply.length === 1 ? ' cell' : ' cells') + ' pasted.';
            if (plan.skipped.length) {
                var pastPage = st.size && plan.skipped.some(function (s) { return s.reason === 'is past the last row shown'; });
                showMsg(done + ' ' + skippedMessage(plan.skipped, 'pasted') + '.'
                    + (pastPage ? ' To paste further down, choose All in Rows per page first.' : ''), 'warn');
            } else showMsg(done, 'ok');
        }
        function copyText() {
            var R = lastRect(), m = [];
            for (var r = R.r1; r <= R.r2; r++) {
                var row = st.pageRows[r];
                if (!row) continue;
                var line = [];
                for (var c = R.c1; c <= R.c2; c++) line.push(formatValue(row.cells[st.columns[c].key], st.columns[c]));
                m.push(line);
            }
            return { text: toTSV(m), count: m.length * (R.c2 - R.c1 + 1) };
        }
        function doClear() {
            if (blocked()) return;
            var plan = planClear(snapshot(), selectedCells());
            applyChanges(plan.apply, true);
            var k = plan.skipped.length;
            var t = plan.apply.length || !k ? plan.apply.length + (plan.apply.length === 1 ? ' cell' : ' cells') + ' cleared.' : '';
            if (k) t += (t ? ' ' : '') + (k === 1 ? '1 cell was left as it is: it cannot be changed here.' : k + ' cells were left as they are: they cannot be changed here.');
            showMsg(t, k ? 'warn' : 'ok');
        }
        function doFill() {
            if (blocked()) return;
            var snap = snapshot(), total = { apply: [], skipped: [] };
            (st.rects.length ? st.rects : [lastRect()]).forEach(function (x) {
                var p = planFill(snap, x);
                total.apply = total.apply.concat(p.apply);
                total.skipped = total.skipped.concat(p.skipped);
            });
            applyChanges(total.apply, true);
            var done = total.apply.length + (total.apply.length === 1 ? ' cell' : ' cells') + ' filled.';
            if (total.skipped.length) showMsg(done + ' ' + skippedMessage(total.skipped, 'filled'), 'warn');
            else showMsg(done, 'ok');
        }

        /* ---------- editing */
        function startEdit(r, c, typed) {
            if (st.editing) return;
            var row = st.pageRows[r], col = st.columns[c];
            if (!row || !col) return;
            if (st.saving) { blocked(); return; }
            if (isRO(row, col)) { showMsg("Row '" + row.label + "' " + col.label + ' cannot be changed here.', 'warn'); return; }
            var td = cellEl(r, c);
            if (!td) return;
            var cur = row.cells[col.key];
            var origText = formatValue(cur, col);
            var input;
            if (col.type === 'select' || col.type === 'bool') {
                input = el('select', 'rh-sheet__editor');
                var choices = col.type === 'bool' ? [{ value: 'yes', label: 'Yes' }, { value: 'no', label: 'No' }] : (col.options || []);
                if (cur === null || cur === undefined || cur === '') {
                    var ph = el('option', null, 'Choose...');
                    ph.value = '';
                    input.appendChild(ph);
                }
                choices.forEach(function (o) { var op = el('option', null, String(o.label)); op.value = String(o.value); input.appendChild(op); });
                var curVal = col.type === 'bool' ? (origText === 'Yes' ? 'yes' : (origText === 'No' ? 'no' : '')) : (cur === null || cur === undefined ? '' : String(cur));
                input.value = curVal;
                origText = input.value;
                if (typed) {
                    var low = String(typed).toLowerCase();
                    for (var i = 0; i < choices.length; i++) {
                        if (String(choices[i].label).toLowerCase().indexOf(low) === 0) { input.value = String(choices[i].value); break; }
                    }
                }
            } else {
                input = el('input', 'rh-sheet__editor');
                input.type = 'text';
                input.autocomplete = 'off';
                input.spellcheck = col.type === 'text';
                if (col.type === 'number' || col.type === 'money') input.setAttribute('inputmode', 'decimal');
                input.value = typed !== null && typed !== undefined ? typed : origText;
            }
            input.setAttribute('aria-label', row.label + ', ' + col.label);
            td.textContent = '';
            td.classList.add('is-editing');
            td.appendChild(input);
            st.editing = { r: r, c: c, input: input, origText: origText, typed: typed !== null && typed !== undefined, td: td };
            input.focus();
            if (input.setSelectionRange && input.tagName === 'INPUT') {
                var L = input.value.length;
                try { input.setSelectionRange(L, L); } catch (e) { /* ignore */ }
            }
            input.addEventListener('keydown', onEditorKey);
            input.addEventListener('blur', onEditorBlur);
        }
        /** Saves the open editor into the cell. Returns true when the editor closed. */
        function commitEdit(fromBlur) {
            var ed = st.editing;
            if (!ed) return true;
            var row = st.pageRows[ed.r], col = st.columns[ed.c];
            var text = ed.input.value;
            if (!ed.typed && text === ed.origText) { closeEdit(); return true; }
            if (isBlank(text)) {
                closeEdit();
                if (ed.origText !== '') showMsg('An empty cell is not saved. To clear a cell, select it and press Delete.', 'warn');
                return true;
            }
            var p = parseCell(text, col);
            if (!p.ok) {
                var why = "Row '" + row.label + "' " + col.label + ": '" + text + "' " + p.reason + '.';
                if (fromBlur) { closeEdit(); showMsg(why + ' Not changed.', 'error'); return true; }
                ed.input.classList.add('is-invalid');
                ed.input.setAttribute('aria-invalid', 'true');
                showMsg(why + ' Fix it, or press Esc to cancel.', 'error');
                return false;
            }
            closeEdit();
            if (!sameValue(row.cells[col.key], p.value, col)) {
                applyChanges([{ id: row.id, key: col.key, old: row.cells[col.key] === undefined ? null : row.cells[col.key], new: p.value }], true);
            }
            if (msg.classList.contains('is-error')) showMsg('', '');
            return true;
        }
        function closeEdit() {
            var ed = st.editing;
            if (!ed) return;
            st.editing = null;
            ed.input.removeEventListener('keydown', onEditorKey);
            ed.input.removeEventListener('blur', onEditorBlur);
            render();
        }
        function cancelEdit() {
            if (!st.editing) return;
            closeEdit();
        }
        function onEditorKey(e) {
            e.stopPropagation();
            var ed = st.editing;
            if (!ed) return;
            if (e.key === 'Escape') {
                e.preventDefault();
                cancelEdit();
                showMsg('', '');
                focusActive();
            } else if (e.key === 'Enter') {
                e.preventDefault();
                var r = ed.r, c = ed.c;
                if (commitEdit(false)) { st.active = { r: r, c: c }; select(r + (e.shiftKey ? -1 : 1), c); }
            } else if (e.key === 'Tab') {
                e.preventDefault();
                var r2 = ed.r, c2 = ed.c;
                if (commitEdit(false)) { st.active = { r: r2, c: c2 }; select(r2, c2 + (e.shiftKey ? -1 : 1)); }
            } else if (ed.input.classList.contains('is-invalid')) {
                ed.input.classList.remove('is-invalid');
                ed.input.removeAttribute('aria-invalid');
            }
        }
        function onEditorBlur() {
            // Focus moving inside the editor flow (Enter / Tab) has already closed it.
            if (st.editing) commitEdit(true);
        }

        /* ---------- events */
        function cellOf(t) {
            var td = t && t.closest ? t.closest('td.rh-sheet__cell') : null;
            return td && tbody.contains(td) ? td : null;
        }
        function rc(td) { return { r: +td.getAttribute('data-r'), c: +td.getAttribute('data-c') }; }

        function onMouseDown(e) {
            if (e.button !== 0) return;
            var td = cellOf(e.target);
            if (!td) return;
            if (st.editing && st.editing.td === td) return; // clicks inside the open editor
            if (st.editing && !commitEdit(true)) return;
            td = cellEl(+td.getAttribute('data-r'), +td.getAttribute('data-c')) || td;
            var p = rc(td);
            e.preventDefault();
            if (e.shiftKey) select(p.r, p.c, 'extend');
            else if (e.ctrlKey || e.metaKey) select(p.r, p.c, 'add');
            else select(p.r, p.c);
            st.dragging = !e.shiftKey;
        }
        function onMouseOver(e) {
            if (!st.dragging) return;
            var td = cellOf(e.target);
            if (!td) return;
            var p = rc(td);
            if (p.r === st.active.r && p.c === st.active.c) return;
            select(p.r, p.c, 'extend');
        }
        function onMouseUp() { st.dragging = false; }
        function onDblClick(e) {
            var td = cellOf(e.target);
            if (!td || st.editing) return;
            var p = rc(td);
            startEdit(p.r, p.c, null);
        }
        function onFocusIn(e) {
            var td = cellOf(e.target);
            if (!td || st.editing) return;
            var p = rc(td);
            if (p.r !== st.active.r || p.c !== st.active.c) {
                st.active = p;
                if (!st.rects.length) st.anchor = p;
                paintSelection();
            }
        }
        function onKey(e) {
            var td = cellOf(e.target);
            if (!td || st.editing) return;
            var k = e.key, mod = e.ctrlKey || e.metaKey;
            var a = st.active, lastC = st.columns.length - 1;
            if (mod && !e.altKey) {
                var lk = String(k).toLowerCase();
                if (lk === 'z' && !e.shiftKey) { e.preventDefault(); doUndo(); return; }
                if (lk === 'y' || (lk === 'z' && e.shiftKey)) { e.preventDefault(); doRedo(); return; }
                if (lk === 'a') {
                    e.preventDefault();
                    st.rects = [{ r1: 0, c1: 0, r2: st.pageRows.length - 1, c2: lastC }];
                    paintSelection();
                    return;
                }
                return; // Ctrl+C / Ctrl+V reach the copy / paste events
            }
            switch (k) {
                case 'ArrowUp': e.preventDefault(); select(a.r - 1, a.c, e.shiftKey ? 'extend' : null); return;
                case 'ArrowDown': e.preventDefault(); select(a.r + 1, a.c, e.shiftKey ? 'extend' : null); return;
                case 'ArrowLeft': e.preventDefault(); select(a.r, a.c - 1, e.shiftKey ? 'extend' : null); return;
                case 'ArrowRight': e.preventDefault(); select(a.r, a.c + 1, e.shiftKey ? 'extend' : null); return;
                case 'Home': e.preventDefault(); select(a.r, 0); return;
                case 'End': e.preventDefault(); select(a.r, lastC); return;
                case 'Enter': e.preventDefault(); select(a.r + (e.shiftKey ? -1 : 1), a.c); return;
                case 'Tab':
                    // Inside the sheet Tab moves across; at the row's edge it leaves the sheet (no keyboard trap).
                    if (e.shiftKey ? a.c > 0 : a.c < lastC) { e.preventDefault(); select(a.r, a.c + (e.shiftKey ? -1 : 1)); }
                    return;
                case 'F2': e.preventDefault(); startEdit(a.r, a.c, null); return;
                case 'Escape':
                    if (st.rects.length) { e.preventDefault(); st.rects = []; paintSelection(); }
                    return;
                case 'Delete':
                case 'Backspace':
                    e.preventDefault(); doClear(); return;
            }
            if (k && k.length === 1 && !e.altKey) {
                e.preventDefault();
                startEdit(a.r, a.c, k === ' ' ? '' : k);
            }
        }
        function onCopy(e) {
            if (st.editing || !cellOf(document.activeElement)) return;
            var cp = copyText();
            st.clip = cp.text;
            if (e.clipboardData) { e.clipboardData.setData('text/plain', cp.text); e.preventDefault(); }
            showMsg(cp.count + (cp.count === 1 ? ' cell' : ' cells') + ' copied.', 'ok');
        }
        function onPaste(e) {
            if (st.editing) return;
            if (!cellOf(e.target) && !cellOf(document.activeElement)) return;
            var text = e.clipboardData ? e.clipboardData.getData('text/plain') : '';
            e.preventDefault();
            doPaste(text);
        }

        table.addEventListener('mousedown', onMouseDown);
        table.addEventListener('mouseover', onMouseOver);
        document.addEventListener('mouseup', onMouseUp);
        table.addEventListener('dblclick', onDblClick);
        table.addEventListener('focusin', onFocusIn);
        table.addEventListener('keydown', onKey);
        table.addEventListener('copy', onCopy);
        table.addEventListener('paste', onPaste);
        msgClose.addEventListener('click', function () { showMsg('', ''); });
        bUndo.addEventListener('click', doUndo);
        bRedo.addEventListener('click', doRedo);
        fInput.addEventListener('input', function () { st.query = fInput.value; refilter(); });
        szSel.addEventListener('change', function () { st.size = Number(szSel.value); st.page = 1; st.rects = []; st.active = { r: 0, c: 0 }; cancelEdit(); render(); });
        bPrev.addEventListener('click', function () { cancelEdit(); st.page--; st.rects = []; st.active = { r: 0, c: 0 }; render(); });
        bNext.addEventListener('click', function () { cancelEdit(); st.page++; st.rects = []; st.active = { r: 0, c: 0 }; render(); });
        bSave.addEventListener('click', function () {
            if (st.saving || st.readOnly) return;
            if (st.editing && !commitEdit(false)) return;
            var changes = api.getChanges();
            if (!changes.length) return;
            st.saving = true;
            updateButtons();
            root.classList.add('is-saving');
            var p;
            try { p = Promise.resolve(opts.onSave(changes)); } catch (err) { p = Promise.reject(err); }
            // The Save button is disabled while saving, so focus may have dropped to the page: bring it back.
            function refocus() {
                var a = document.activeElement;
                if (a && a !== document.body && document.contains(a)) return;
                if (!bSave.disabled) bSave.focus(); else focusActive();
            }
            p.then(function (res) {
                st.saving = false;
                if (res === false) { render(); showMsg('Not saved. Your changes are still marked.', 'warn'); refocus(); return; }
                api.clearChanges();
                showMsg('Saved.', 'ok');
                refocus();
            }, function (err) {
                st.saving = false;
                render();
                showMsg('Not saved: ' + ((err && err.message) ? err.message : 'please try again') + '. Your changes are still marked.', 'error');
                refocus();
            });
        });

        /* ---------- right-click menu */
        function menuFor(td) {
            if (st.editing) return null;
            var p = rc(td);
            var inSel = selectedCells().some(function (x) { return x.r === p.r && x.c === p.c; });
            if (!inSel) select(p.r, p.c);
            var n = selectedCells().length, R = lastRect();
            var lock = st.readOnly ? 'This sheet is read-only' : (st.saving ? 'Saving' : '');
            // After a menu action the keyboard carries on from the sheet, not from the closed menu.
            function back(fn) { return function () { fn(); focusActive(); }; }
            return {
                title: n + (n === 1 ? ' cell' : ' cells'),
                items: [
                    {
                        label: 'Copy', icon: 'fa-copy', run: function () {
                            var cp = copyText();
                            st.clip = cp.text;
                            var done = function () { showMsg(cp.count + (cp.count === 1 ? ' cell' : ' cells') + ' copied.', 'ok'); };
                            try {
                                if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(cp.text).then(done, function () { showMsg('Copy did not reach the clipboard: use Ctrl+C.', 'warn'); });
                                else showMsg('Use Ctrl+C to copy.', 'warn');
                            } catch (e) { showMsg('Use Ctrl+C to copy.', 'warn'); }
                        }
                    },
                    {
                        label: 'Paste', icon: 'fa-paste', disabled: !!lock, hint: lock, run: function () {
                            try {
                                if (navigator.clipboard && navigator.clipboard.readText) {
                                    navigator.clipboard.readText().then(doPaste, function () { showMsg('Use Ctrl+V to paste here.', 'warn'); });
                                } else showMsg('Use Ctrl+V to paste here.', 'warn');
                            } catch (e) { showMsg('Use Ctrl+V to paste here.', 'warn'); }
                        }
                    },
                    { label: 'Clear', icon: 'fa-eraser', disabled: !!lock, hint: lock, run: back(doClear) },
                    {
                        label: 'Fill down', icon: 'fa-arrow-down', disabled: !!lock || R.r2 === R.r1,
                        hint: lock || (R.r2 === R.r1 ? 'Select two or more rows first' : ''), run: back(doFill)
                    }
                ]
            };
        }

        /* ---------- API */
        var api = {
            toolbar: extra,
            getChanges: function () { return st.rows ? diffChanges(st.rows, st.columns) : []; },
            clearChanges: function () {
                (st.rows || []).forEach(function (row) {
                    row.base = {};
                    Object.keys(row.cells).forEach(function (k) { row.base[k] = row.cells[k]; });
                });
                history.clear();
                render();
            },
            setRows: function (rows) {
                cancelEdit();
                history.clear();
                loadRows(rows);
                fillColumnFilters();
                refilter();
            },
            setReadOnly: function (b) {
                cancelEdit();
                st.readOnly = !!b;
                render();
            },
            destroy: function () {
                cancelEdit();
                document.removeEventListener('mouseup', onMouseUp);
                instances = instances.filter(function (x) { return x.root !== root; });
                if (root.parentNode) root.parentNode.removeChild(root);
            }
        };
        instances.push({ root: root, menuFor: menuFor });
        api.setRows(opts.rows === undefined ? null : opts.rows);
        return api;
    }

    /** "Grid view / List view" switch, remembered per browser (grid by default). Phones: list, switch hidden. */
    function viewSwitch(storageKey, cb) {
        cb = cb || {};
        var wrap = el('div', 'rh-sheet-switch');
        wrap.setAttribute('role', 'group');
        wrap.setAttribute('aria-label', 'How to show this list');
        var bg = button('Grid view', 'rh-sheet-switch__btn', 'fa-table-cells');
        var bl = button('List view', 'rh-sheet-switch__btn', 'fa-list');
        bg.setAttribute('data-view', 'grid');
        bl.setAttribute('data-view', 'list');
        wrap.appendChild(bg);
        wrap.appendChild(bl);
        var current = null;
        function read() {
            try { return window.localStorage.getItem(storageKey) === 'list' ? 'list' : 'grid'; } catch (e) { return 'grid'; }
        }
        function write(v) { try { window.localStorage.setItem(storageKey, v); } catch (e) { /* private mode: not kept */ } }
        function apply() {
            var wide = wanted();
            var mode = wide ? read() : 'list';
            wrap.hidden = !wide;
            bg.setAttribute('aria-pressed', mode === 'grid' ? 'true' : 'false');
            bl.setAttribute('aria-pressed', mode === 'list' ? 'true' : 'false');
            if (mode === current) return;
            current = mode;
            var fn = mode === 'grid' ? cb.onGrid : cb.onList;
            if (typeof fn === 'function') fn();
        }
        bg.addEventListener('click', function () { write('grid'); apply(); });
        bl.addEventListener('click', function () { write('list'); apply(); });
        var mq = null;
        try { mq = window.matchMedia('(min-width: 768px)'); } catch (e) { mq = null; }
        if (mq) {
            if (mq.addEventListener) mq.addEventListener('change', apply);
            else if (mq.addListener) mq.addListener(apply);
        }
        apply();
        return {
            el: wrap,
            mode: function () { return current; },
            destroy: function () {
                if (mq) {
                    if (mq.removeEventListener) mq.removeEventListener('change', apply);
                    else if (mq.removeListener) mq.removeListener(apply);
                }
                if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
            }
        };
    }

    window.RhSheetGrid = { create: create, wanted: wanted, viewSwitch: viewSwitch, logic: logic };
})();
