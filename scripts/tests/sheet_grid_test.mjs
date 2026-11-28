// Pure logic of admin/js/sheet-grid.js and admin/js/sheet-review.js (SHEET_VIEW_PLAN Phase S1, Bundle A).
// No DOM, no DB. Run: node scripts/tests/sheet_grid_test.mjs
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
function load(rel) {
    const sandbox = { module: { exports: {} } };
    vm.runInNewContext(readFileSync(join(root, rel), 'utf8'), sandbox);
    return sandbox.module.exports;
}
const L = load('admin/js/sheet-grid.js');
const R = load('admin/js/sheet-review.js');

let pass = 0, fail = 0;
function ok(label, cond, detail) {
    if (cond) { pass++; console.log('[PASS] ' + label); }
    else { fail++; console.log('[FAIL] ' + label + (detail !== undefined ? ' :: ' + JSON.stringify(detail) : '')); }
}
const eq = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const num = (t, col) => { const p = L.parseNumber(t, col); return p.ok ? p.value : 'REFUSED'; };
const COUNT = { key: 'counted', label: 'Counted', type: 'number', min: 0, step: 0.01 };
const COST = { key: 'cost', label: 'Unit cost', type: 'money' };

// ---- clipboard
ok('clipboard: trailing newline does not add a blank row', eq(L.parseClipboard('1\t2\n3\t4\n'), [['1', '2'], ['3', '4']]));
ok('clipboard: \\r\\n and \\n both split rows', eq(L.parseClipboard('a\r\nb\nc'), [['a'], ['b'], ['c']]));
ok('clipboard: trailing \\r\\n does not add a blank row', eq(L.parseClipboard('a\tb\r\n'), [['a', 'b']]));
ok('clipboard: only ONE trailing newline is dropped', eq(L.parseClipboard('a\n\n'), [['a'], ['']]));
ok('clipboard: quoted cell holding a tab is one cell', eq(L.parseClipboard('"a\tb"\tc'), [['a\tb', 'c']]));
ok('clipboard: quoted cell holding a newline is one cell', eq(L.parseClipboard('"line1\nline2"\tx\ny\tz'), [['line1\nline2', 'x'], ['y', 'z']]));
ok('clipboard: "" inside quotes is one quote', eq(L.parseClipboard('"say ""hi"""\t2'), [['say "hi"', '2']]));
ok('clipboard: a quote mid-cell is literal (12" pizza)', eq(L.parseClipboard('12" pizza\t5'), [['12" pizza', '5']]));
ok('clipboard: an unclosed quote is literal', eq(L.parseClipboard('"abc\tdef'), [['"abc', 'def']]));
ok('clipboard: empty text = no rows', eq(L.parseClipboard(''), []));
ok('clipboard: empty cells kept in place', eq(L.parseClipboard('1\t\t3'), [['1', '', '3']]));
ok('TSV round trip (tab, newline and quote cells)', eq(L.parseClipboard(L.toTSV([['a\tb', 'x"y'], ['"q', 'l1\nl2']])), [['a\tb', 'x"y'], ['"q', 'l1\nl2']]));

// ---- numbers
ok('8,500.00 -> 8500 (money)', num('8,500.00', COST) === 8500);
ok('MK 8,500 -> 8500', num('MK 8,500', COST) === 8500);
ok('MWK8,500.50 -> 8500.5', num('MWK8,500.50', COST) === 8500.5);
ok('K 250 -> 250 and $12.5 -> 12.5', num('K 250', COST) === 250 && num('$12.5', COST) === 12.5);
ok('1,234,567.5 -> 1234567.5 (number, no step)', num('1,234,567.5', { type: 'number' }) === 1234567.5);
ok('whitespace (and a non-breaking space) trimmed', num('  12.5 \t', COUNT) === 12.5 && num('\u00a07\u00a0', COUNT) === 7);
for (const bad of ['8,5', '1.234,56', '8.500,00', '1,23', '12abc', '', '--5', '1,2345', '0,500', '1 000', 'MK', '1.2.3', ',500']) {
    ok(`refused, never guessed: '${bad}'`, num(bad, bad === '' ? COST : { type: 'number' }) === 'REFUSED', L.parseNumber(bad, { type: 'number' }));
}
ok("'8,5' reason says it is not a clear number", L.parseNumber('8,5', COUNT).reason === 'is not a clear number');
ok('blank is reported as blank', L.parseNumber('   ', COUNT).blank === true);
ok('negative refused at min 0', num('-5', COUNT) === 'REFUSED' && num('-5', COST) === 'REFUSED');
ok('negative refused by default (no min given)', num('-1', { type: 'number' }) === 'REFUSED' && num('MK -3', { type: 'money' }) === 'REFUSED');
ok('negative allowed when min is below 0', num('-5', { type: 'number', min: -10 }) === -5 && num('-11', { type: 'number', min: -10 }) === 'REFUSED');
ok('-0 is 0, not a negative', Object.is(num('-0', { type: 'number', min: -1 }), 0));
ok('max honoured', num('101', { type: 'number', max: 100 }) === 'REFUSED' && num('100', { type: 'number', max: 100 }) === 100);
ok('step 0.01: 2 decimals ok, 3 refused', num('1.25', COUNT) === 1.25 && num('1.255', COUNT) === 'REFUSED');
ok('step 1: whole numbers only', num('3', { type: 'number', step: 1 }) === 3 && L.parseNumber('3.5', { type: 'number', step: 1 }).reason === 'must be a whole number');
ok('step 0.001 (written form) allows 3 decimals', num('0.125', { type: 'number', step: '0.001' }) === 0.125);
ok('stepDecimals of 1e-7 is 7', L.stepDecimals(1e-7) === 7 && L.stepDecimals('any') === null);
ok('money: 3 decimals refused, 2 kept exactly', num('12.345', COST) === 'REFUSED' && num('12.34', COST) === 12.34 && num('0.1', COST) === 0.1);
ok('.5 accepted as 0.5', num('.5', COUNT) === 0.5);

// ---- select / bool / text
const CAT = { key: 'cat', label: 'Category', type: 'select', options: [{ value: 'bar', label: 'Bar' }, { value: 'kitchen', label: 'Kitchen stores' }] };
ok('select by label, case-insensitive, trimmed', eq(L.parseCell('  kitchen STORES ', CAT), { ok: true, value: 'kitchen' }));
ok('select by value', L.parseCell('BAR', CAT).value === 'bar');
ok('select: unknown refused', L.parseCell('Cellar', CAT).ok === false);
const B = { type: 'bool' };
ok('bool yes/no/true/false/1/0/y/n', ['yes', 'TRUE', '1', 'Y'].every(t => L.parseCell(t, B).value === true) && ['no', 'False', '0', 'n'].every(t => L.parseCell(t, B).value === false));
ok('bool: other text refused', L.parseCell('maybe', B).ok === false);
ok('text kept (trimmed)', L.parseCell('  Hello  ', { type: 'text' }).value === 'Hello');

// ---- display
ok('money shows 2 decimals and commas', L.formatMoney(8500) === '8,500.00' && L.formatMoney(1234567.5) === '1,234,567.50');
ok('money keeps extra decimals of an old value (never hidden)', L.formatMoney(0.0125) === '0.0125');
ok('number shown plainly', L.formatValue(7.5, COUNT) === '7.5' && L.formatValue(12, COUNT) === '12');
ok('displayed money parses back to the same value', num(L.formatMoney(98765.43), COST) === 98765.43);

// ---- paste plan
const cols = [
    { key: 'name', label: 'Name', type: 'text' },
    COUNT, COST,
    { key: 'value', label: 'Value', type: 'money', readOnly: true }
];
const snap = () => ({
    readOnly: false, columns: cols,
    rows: [
        { id: 1, label: 'Gin', cells: { name: 'Gin', counted: 5, cost: 1000, value: 5000 }, readOnly: false },
        { id: 2, label: 'Rum', cells: { name: 'Rum', counted: null, cost: 2000, value: null }, readOnly: true },
        { id: 3, label: 'Vodka', cells: { name: 'Vodka', counted: 2, cost: 1500, value: 3000 }, readOnly: ['cost'] }
    ]
});
{
    const p = L.planPaste(snap(), { r: 0, c: 1 }, L.parseClipboard('7\t8,500.00\n9\t1\n3\t2500\n'));
    ok('paste: good cells applied with old values', eq(p.apply, [{ id: 1, key: 'counted', old: 5, new: 7 }, { id: 1, key: 'cost', old: 1000, new: 8500 }, { id: 3, key: 'counted', old: 2, new: 3 }]), p.apply);
    ok('paste: read-only row and read-only cell skipped and listed', p.skipped.length === 3 && p.skipped.every(s => s.reason === 'cannot be changed here'), p.skipped);
}
{
    const p = L.planPaste(snap(), { r: 0, c: 1 }, L.parseClipboard('\t\n'));
    ok('paste: a blank cell is skipped, never clears', p.apply.length === 0 && p.skipped.length === 0);
    const p2 = L.planPaste(snap(), { r: 0, c: 1 }, [['', '  ']]);
    ok('paste: whitespace-only cell counts as blank', p2.apply.length === 0 && p2.skipped.length === 0);
}
{
    const p = L.planPaste(snap(), { r: 0, c: 1 }, [['8,5']]);
    ok('paste: refused value skipped with its reason', p.apply.length === 0 && p.skipped[0].reason === 'is not a clear number');
    ok('message: "1 cell not pasted: Row \'Gin\' Counted: \'8,5\' is not a clear number"',
        L.skippedMessage(p.skipped, 'pasted') === "1 cell not pasted: Row 'Gin' Counted: '8,5' is not a clear number", L.skippedMessage(p.skipped));
}
{
    const p = L.planPaste(snap(), { r: 2, c: 2 }, [['1', '2', '3'], ['4', '5', '6']]);
    const edge = p.skipped.filter(s => s.edge);
    ok('paste never goes past the last row or column (listed as skipped)', edge.length === 4 && p.apply.length === 0, p.skipped);
    ok('past-edge reasons name row or column', edge.some(s => s.reason === 'is past the last row shown') && edge.some(s => s.reason === 'is past the last column'));
    ok('message lists the count and cuts long lists', /^6 cells not pasted: /.test(L.skippedMessage(p.skipped, 'pasted', 2)) && /and 4 more$/.test(L.skippedMessage(p.skipped, 'pasted', 2)));
}
{
    const s = snap(); s.readOnly = true;
    const p = L.planPaste(s, { r: 0, c: 1 }, [['9']]);
    ok('paste into a read-only grid applies nothing', p.apply.length === 0 && p.skipped.length === 1);
}
ok('paste of the same value is not a change', L.planPaste(snap(), { r: 0, c: 1 }, [['5']]).apply.length === 0);
ok('paste into a computed column is skipped', L.planPaste(snap(), { r: 0, c: 3 }, [['1']]).skipped[0].reason === 'cannot be changed here');

// ---- fill down
{
    const s = snap();
    s.rows.push({ id: 4, label: 'Wine', cells: { name: 'Wine', counted: 1, cost: 900 }, readOnly: false });
    const p = L.planFill(s, { r1: 0, c1: 1, r2: 3, c2: 2 });
    ok('fill down copies the top value to editable cells below', eq(p.apply, [{ id: 3, key: 'counted', old: 2, new: 5 }, { id: 4, key: 'counted', old: 1, new: 5 }, { id: 4, key: 'cost', old: 900, new: 1000 }]), p.apply);
    ok('fill down skips and lists read-only cells', p.skipped.length === 3, p.skipped);
    const blankTop = L.planFill(s, { r1: 1, c1: 1, r2: 3, c2: 1 });
    ok('fill down from a blank top cell changes nothing', blankTop.apply.length === 0);
}

// ---- clear
{
    const p = L.planClear(snap(), [{ r: 0, c: 1 }, { r: 1, c: 1 }, { r: 2, c: 2 }, { r: 0, c: 3 }]);
    ok('clear: only editable, non-blank cells', eq(p.apply, [{ id: 1, key: 'counted', old: 5, new: null }]) && p.skipped.length === 3, p);
}

// ---- undo / redo
{
    const h = L.createHistory();
    const cells = { 1: { counted: 5 }, 2: { counted: 1 } };
    const b1 = [{ id: 1, key: 'counted', old: 5, new: 7 }, { id: 2, key: 'counted', old: 1, new: 2 }];
    L.applyBatch(cells, b1); h.push(b1);
    const b2 = [{ id: 1, key: 'counted', old: 7, new: 9 }];
    L.applyBatch(cells, b2); h.push(b2);
    L.applyBatch(cells, h.undo());
    ok('undo reverts the last step', cells[1].counted === 7 && cells[2].counted === 2);
    L.applyBatch(cells, h.undo());
    ok('undo again reverts the paste as ONE step', cells[1].counted === 5 && cells[2].counted === 1);
    ok('nothing left to undo', h.undo() === null && !h.canUndo());
    L.applyBatch(cells, h.redo());
    ok('redo puts the paste back', cells[1].counted === 7 && cells[2].counted === 2);
    const b3 = [{ id: 2, key: 'counted', old: 2, new: 4 }];
    L.applyBatch(cells, b3); h.push(b3);
    ok('a new edit empties redo', !h.canRedo() && h.redo() === null);
}

// ---- changes list
{
    const rows = [{ id: 1, label: 'Gin', base: { name: 'Gin', counted: 5, cost: 1000, value: 5000 }, cells: { name: 'Gin', counted: 5, cost: 1100, value: 5500 } },
        { id: 2, label: 'Rum', base: { name: 'Rum', counted: 0.0125, cost: 0.0125 }, cells: { name: 'Rum', counted: 0.0125, cost: 0.0125 } }];
    const d = L.diffChanges(rows, cols);
    ok('changes: only edited cells, computed column left out, with labels and kind', eq(d, [{ id: 1, key: 'cost', old: 1000, new: 1100, item: 'Gin', field: 'Unit cost', kind: 'money' }]), d);
    rows[0].cells.cost = 1000;
    ok('editing back to the old value drops the change', L.diffChanges(rows, cols).length === 0);
}

// ---- filter / pages
{
    const rows = [{ cells: { name: 'Gin', counted: 5 } }, { cells: { name: 'Gin tonic', counted: null } }, { cells: { name: 'Rum', counted: 2 } }];
    ok('filter box matches words', L.filterRows(rows, cols, 'gin', {}).length === 2);
    ok('column filter: not counted', L.filterRows(rows, cols, '', { counted: '\u0000blank' }).length === 1);
    ok('column filter: counted', L.filterRows(rows, cols, '', { counted: '\u0000set' }).length === 2);
    ok('pages of 10 from 60 rows', eq(L.pageSlice(60, 2, 10), { start: 10, end: 20, page: 2, pages: 6 }));
    ok('page size All', eq(L.pageSlice(60, 3, 0), { start: 0, end: 60, page: 1, pages: 1 }));
}

// ---- review list (money)
ok('review: money first, order kept', eq(R.sortChanges([{ kind: 'text', item: 'a' }, { kind: 'money', item: 'b' }, { kind: 'number', item: 'c' }, { kind: 'money', item: 'd' }]).map(c => c.item), ['b', 'd', 'a', 'c']));
ok('review: "12 changes, 5 prices"', R.summary(Array.from({ length: 12 }, (_, i) => ({ kind: i < 5 ? 'money' : 'text' }))) === '12 changes, 5 prices');
ok('review: "1 change, 1 price" and "3 changes"', R.summary([{ kind: 'money' }]) === '1 change, 1 price' && R.summary([{ kind: 'text' }, { kind: 'text' }, { kind: 'number' }]) === '3 changes');
const m = (o, n) => R.flag({ kind: 'money', old: o, new: n });
ok('flag: +50% exactly is not flagged, more is', !m(100, 150) && m(100, 150.01));
ok('flag: -50% exactly is not flagged, more is', !m(100, 50) && m(100, 49.99));
ok('flag: from or to 0 (blank = 0)', m(0, 10) && m(10, 0) && m(null, 10) && m(10, ''));
ok('flag: small move not flagged; non-money never', !m(8500, 9000) && !R.flag({ kind: 'number', old: 1, new: 100 }));
ok('review: money shown "8,500.00 -> 9,000.00" style', R.formatValue(8500, 'money') === '8,500.00' && R.formatValue(null, 'money') === '(blank)');
ok('review and grid format money the same', [0, 0.5, 12.3, 8500, 1234567.89, 0.0125].every(v => R.formatValue(v, 'money') === L.formatMoney(v)));

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
