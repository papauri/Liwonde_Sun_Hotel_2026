// New Booking step-by-step screen (Phase A, owner 2026-11-27): headless browser run on the Liwonde TEST hotel only.
// No dependencies, no MCP: its own php -S (dev-router.php) on its own port and its own headless Chrome (own CDP port,
// temp profile); both are stopped at the end, nothing else is touched.
//
//   node scripts/tests/create_booking_steps_browser.mjs                 full run at 1280 and 390 (creates + cancels)
//   node scripts/tests/create_booking_steps_browser.mjs --width=390     one width
//   node scripts/tests/create_booking_steps_browser.mjs --only=paid,part  some cases (motion paid part over credit two
//                                                                       tent returning prefill dates refused w1 w2)
//   node scripts/tests/create_booking_steps_browser.mjs --capture=FILE  3 cases filled and "Create" pressed, the POST
//                                                                       caught in the browser and NOT sent; fields to FILE
//   node scripts/tests/create_booking_steps_browser.mjs --compare=A,B   diff two capture files (csrf/uuid masked)
//
// Safety: refuses unless this checkout is Liwonde, its database is not in .claude/LIVE_TARGETS, the site name says
// Liwonde and the site is the test platform with a valid test recipient (every email goes there, never a guest).
// Bookings it creates use @example.invalid guests and are cancelled through the app (bookings.php) with the guest
// email off, by the id each Create returned (never by a search). Database reads are SELECT only.
// Emails: only the cases that must send are allowed to (the overpay credit note once at 1280, the two Edit wording
// cases at 1280); every other case must send none, and the run total must equal the cases' own counts. Rows the app
// logs as 'not_sent' (staff chose not to email, e.g. each cancel with the guest email off) are listed, not counted.
// Env: CB_HTTP_PORT (8791), CDP_PORT (9391). Takes 20+ minutes for the full run (remote database): run it in the
// background with its output sent to a log file.
import { spawn, spawnSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const ARGS = Object.fromEntries(process.argv.slice(2).map(a => { const m = a.match(/^--([^=]+)(?:=(.*))?$/); return m ? [m[1], m[2] ?? '1'] : [a, '1']; }));
const HTTP_PORT = Number(process.env.CB_HTTP_PORT || 8791);
const CDP_PORT = Number(process.env.CDP_PORT || 9391);
const BASE = `http://127.0.0.1:${HTTP_PORT}`;
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
let pass = 0, fail = 0;
function check(ok, label, detail = '') { ok ? pass++ : fail++; console.log(`[${ok ? 'PASS' : 'FAIL'}] ${label}${!ok && detail ? ': ' + detail : ''}`); return ok; }

// ── Compare two capture files (no servers) ─────────────────────────────────────
if (ARGS.compare) {
  const [a, b] = ARGS.compare.split(',');
  const A = JSON.parse(readFileSync(a, 'utf8')), B = JSON.parse(readFileSync(b, 'utf8'));
  const MASK = new Set(['csrf_token', 'client_uuid']);
  const TENTATIVE_PAYMENT = new Set(['payment_received', 'payment_method', 'amount_collected']);
  // The server reads the post by name, so fields may move between names; values under ONE name (room lines, credit
  // notes) keep their order and their count.
  const byName = (p) => p.reduce((o, [k, v]) => { (o[k] = o[k] || []).push(v); return o; }, {});
  for (const name of Object.keys(A).filter(k => !k.includes(':'))) {
    const fa = (A[name] || []).filter(([k]) => !MASK.has(k)), fb = (B[name] || []).filter(([k]) => !MASK.has(k));
    const ga = byName(fa), gb = byName(fb);
    const diff = [];
    for (const k of new Set([...Object.keys(ga), ...Object.keys(gb)])) {
      if (JSON.stringify(ga[k] || null) !== JSON.stringify(gb[k] || null)) diff.push({ k, before: ga[k] || null, after: gb[k] || null });
    }
    console.log(`\n== ${name}: ${fa.length} fields before, ${fb.length} after (csrf_token/client_uuid masked)`);
    diff.forEach(d => console.log(`   ${d.k}: before ${JSON.stringify(d.before)}  after ${JSON.stringify(d.after)}`));
    const allowed = diff.length > 0 && A[name + ':status'] === 'tentative' && diff.every(d => TENTATIVE_PAYMENT.has(d.k) && d.after === null);
    check(diff.length === 0 || allowed, `${name}: every field posted with the same values, in the same order per name${allowed ? ' (except the tentative payment fields, not posted now)' : ''}`);
  }
  console.log(`\ncompare: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
}

// ── Safety guard + read-only database helper (php, SELECT only) ────────────────
const PHP_GUARD = `
$root = getcwd();
if (stripos($root, 'Liwonde') === false) { echo json_encode(['refuse' => 'not the Liwonde checkout']); exit; }
require $root . '/config/database.php';
$db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$live = array_filter(array_map('trim', @file($root . '/.claude/LIVE_TARGETS') ?: []), function ($l) { return $l !== '' && $l[0] !== '#'; });
if ($db === '' || in_array($db, $live, true)) { echo json_encode(['refuse' => 'database is a LIVE target']); exit; }
if (stripos((string)getSetting('site_name', ''), 'liwonde') === false) { echo json_encode(['refuse' => 'site name is not Liwonde']); exit; }
if (trim((string)getSetting('site_is_test_platform', '0')) !== '1'
    || !filter_var(trim((string)getSetting('automated_email_test_recipient', '')), FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['refuse' => 'not the test platform with a valid test recipient']); exit;
}
`;
const scratch = mkdtempSync(join(tmpdir(), 'cbsteps-php-'));
function php(body, input = null) {
  const f = join(scratch, 'q.php');
  writeFileSync(f, '<?php\n' + PHP_GUARD + body);
  const r = spawnSync('php', [f], { cwd: ROOT, encoding: 'utf8', timeout: 90000, input: input === null ? '' : JSON.stringify(input) });
  const out = (r.stdout || '').trim().split('\n').pop() || '';
  try { return JSON.parse(out); } catch { return { refuse: 'php said: ' + (out || r.stderr || '').slice(0, 300) }; }
}
function select(sql, params = []) {
  if (!/^\s*SELECT\b/i.test(sql) || sql.includes(';')) throw new Error('select() runs one SELECT only');
  const r = php(`$in = json_decode(stream_get_contents(STDIN), true); $st = $pdo->prepare($in['sql']); $st->execute($in['params']);
echo json_encode(['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);`, { sql, params });
  if (r.refuse) throw new Error('refused: ' + r.refuse);
  return r.rows;
}
const guard = php(`echo json_encode(['ok' => true]);`);
if (!guard.ok) { console.log('REFUSED: ' + guard.refuse); process.exit(2); }
console.log('safety: Liwonde checkout, database not LIVE, site is the test platform with a valid test recipient');

// ── Servers: php -S (dev-router) + headless Chrome, both ours only ─────────────
const phpErrors = [];
const server = spawn('php', ['-S', `127.0.0.1:${HTTP_PORT}`, '-t', '.', 'scripts/tests/dev-router.php'], { cwd: ROOT, stdio: ['ignore', 'ignore', 'pipe'] });
server.stderr.on('data', (d) => String(d).split('\n').forEach(l => { if (/PHP (Fatal|Parse|Warning)|Uncaught/.test(l)) phpErrors.push(l.trim()); }));
const profile = mkdtempSync(join(tmpdir(), 'cbsteps-chrome-'));
const chrome = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new', `--remote-debugging-port=${CDP_PORT}`,
  `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', '--lang=en-US', '--window-size=1280,900', 'about:blank'], { stdio: 'ignore' });
let ws = null;
async function stopAll() {
  try { if (ws) { ws.send(JSON.stringify({ id: 999999, method: 'Browser.close' })); await sleep(500); } } catch {}
  try { chrome.kill(); } catch {}
  try { server.kill(); } catch {}
  await sleep(300);
  try { rmSync(profile, { recursive: true, force: true }); } catch {}
  try { rmSync(scratch, { recursive: true, force: true }); } catch {}
}
process.on('SIGINT', async () => { await stopAll(); process.exit(130); });

let up = false;
for (let i = 0; i < 60 && !up; i++) { await sleep(250); try { await fetch(BASE + '/admin/login.php'); up = true; } catch {} }
let targets = [];
for (let i = 0; i < 60; i++) { try { targets = await (await fetch(`http://127.0.0.1:${CDP_PORT}/json`)).json(); if (targets.find(t => t.type === 'page')) break; } catch {} await sleep(250); }
const pageTarget = targets.find(t => t.type === 'page');
if (!up || !pageTarget) { console.log('could not start ' + (!up ? 'php -S' : 'Chrome')); await stopAll(); process.exit(2); }

// ── CDP ─────────────────────────────────────────────────────────────────────────
ws = new WebSocket(pageTarget.webSocketDebuggerUrl);
await new Promise(r => ws.addEventListener('open', r));
let msgId = 0; const pending = new Map(); const pageErrors = []; let dialogs = [];
// What happens to the page's real form POST (Create), per case:
//   'capture'    caught and failed here: it never reaches the server
//   'no-overpay' sent only if it carries no confirm_overpay (else failed here and flagged)
//   anything else: sent unchanged, a copy kept in lastPost
let fetchMode = null; let captured = null; let lastPost = null; let postBlocked = '';
ws.addEventListener('message', (ev) => {
  const m = JSON.parse(ev.data);
  if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); return; }
  if (m.method === 'Runtime.exceptionThrown') pageErrors.push((m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text || '').split('\n')[0]);
  if (m.method === 'Page.javascriptDialogOpening') { dialogs.push(m.params.message); send('Page.handleJavaScriptDialog', { accept: true }); }
  if (m.method === 'Fetch.requestPaused') {
    const p = m.params;
    if (p.request.method === 'POST' && p.resourceType === 'Document') {
      let body = p.request.postData;
      if (body === undefined && Array.isArray(p.request.postDataEntries)) body = p.request.postDataEntries.map(e => Buffer.from(e.bytes || '', 'base64').toString('utf8')).join('');
      const pairs = Array.from(new URLSearchParams(body || '').entries());
      if (fetchMode === 'capture') {
        captured = pairs;
        send('Fetch.failRequest', { requestId: p.requestId, errorReason: 'Aborted' }); // caught here: never reaches the server
      } else if (fetchMode === 'no-overpay' && pairs.some(([k]) => k === 'confirm_overpay')) {
        postBlocked = 'the post still carried confirm_overpay, so it was stopped here (never sent)';
        send('Fetch.failRequest', { requestId: p.requestId, errorReason: 'Aborted' });
      } else {
        lastPost = pairs;
        send('Fetch.continueRequest', { requestId: p.requestId });
      }
    } else {
      send('Fetch.continueRequest', { requestId: p.requestId });
    }
  }
});
function send(method, params = {}) { const i = ++msgId; ws.send(JSON.stringify({ id: i, method, params })); return new Promise(r => pending.set(i, r)); }
async function js(expr) {
  const r = await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
  if (r.result?.exceptionDetails) throw new Error((r.result.exceptionDetails.exception?.description || r.result.exceptionDetails.text || 'js error').split('\n')[0]);
  return r.result?.result?.value;
}
async function waitFor(expr, ms = 20000, label = expr) {
  const t0 = Date.now();
  while (Date.now() - t0 < ms) { try { if (await js(expr)) return true; } catch {} await sleep(150); }
  throw new Error('timed out waiting for ' + label);
}
async function nav(url) {
  await js('window.__cbNav = 1').catch(() => {});
  await send('Page.navigate', { url });
  await waitFor('!window.__cbNav && document.readyState === "complete"', 30000, 'page load ' + url);
}
async function viewport(w) {
  await send('Emulation.setDeviceMetricsOverride', { width: w, height: w < 500 ? 844 : 900, deviceScaleFactor: 1, mobile: w < 500 });
  await send('Emulation.setTouchEmulationEnabled', { enabled: w < 500 });
}
await send('Runtime.enable'); await send('Page.enable');

// In-page helpers shared by every case: fill the form through its own fields and functions (works on the old
// one-page form and the step-by-step one, so a capture before and after the change fills the same way).
const HELPERS = `
window.__t = window.__t || (function () {
  const sleep = (ms) => new Promise(r => setTimeout(r, ms));
  const $ = (id) => document.getElementById(id);
  const fire = (n, t) => n.dispatchEvent(new Event(t, { bubbles: true }));
  const set = (n, v) => { n.value = v; fire(n, 'input'); fire(n, 'change'); };
  const form = () => $('createBookingForm');
  const busy = () => !!form() && form().dataset.cbBusy === '1';
  const until = async (fn, ms = 20000, label = '') => { const t0 = Date.now(); while (Date.now() - t0 < ms) { try { if (fn()) return true; } catch (e) {} await sleep(100); } throw new Error('timeout ' + label); };
  const lines = () => Array.from(document.querySelectorAll('.room-line:not([data-removed]) .line-room-select'));
  const availReady = () => lines().every(s => !s.disabled) && Object.keys(_availMap).length > 0 && !busy();
  const txt = (n) => n ? (n.textContent || '').replace(/\\s+/g, ' ').trim() : '';
  const money = (s) => { const n = parseFloat(String(s || '').replace(/^[^0-9-]*/, '').replace(/,/g, '')); return isNaN(n) ? null : n; };
  const ui = [];
  // What is on screen now: side-scroll, touch targets under 44px, and (phone) Continue / Back / Create hidden under
  // the fixed summary bar when the page is scrolled to its end.
  function probe(tag) {
    const vw = window.innerWidth, sw = document.documentElement.scrollWidth;
    const small = [], covered = [];
    document.querySelectorAll('.cb-step, .cb-nav-btn, .btn-create, .cb-choice, .cb-review-change').forEach(function (n) {
      const r = n.getBoundingClientRect();
      if (!r.width || !r.height) return;
      if (r.height < 43.5) small.push((n.id || String(n.className).split(' ')[0]) + '=' + Math.round(r.height));
    });
    if (vw < 500) {
      window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' });
      document.querySelectorAll('.cb-panel.is-active .cb-section-nav .cb-nav-btn, .cb-panel.is-active .btn-create').forEach(function (b) {
        const r = b.getBoundingClientRect();
        if (!r.width || r.bottom < 0 || r.top > window.innerHeight) return;
        const at = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
        if (!at || !(at === b || b.contains(at))) covered.push((b.id || txt(b).slice(0, 20)) + ' under ' + (at ? (at.id || String(at.className).split(' ')[0] || at.tagName) : 'nothing'));
      });
      window.scrollTo({ top: 0, behavior: 'instant' });
    }
    ui.push({ tag: tag, step: typeof cbNav !== 'undefined' ? cbNav.current : 0, side: sw > vw + 1 ? sw + '>' + vw : '', small: small, covered: covered });
  }
  async function next(n) { if (typeof cbNext === 'function') { await cbNext(n); } await sleep(200); await until(() => !busy() && !(typeof cbNav !== 'undefined' && cbNav.moving), 30000, 'busy after step ' + n); }
  async function settle() { await sleep(700); await until(() => !busy(), 30000, 'settle'); }
  function setStatus(v) {
    const s = document.querySelector('select[name="booking_status"]');
    if (s) { s.value = v; fire(s, 'change'); return; }
    const r = document.querySelector('input[name="booking_status"][value="' + v + '"]');
    if (!r) throw new Error('no status ' + v);
    r.click();
  }
  async function pickGuest(email) {
    const s = $('rgSearchInput');
    s.focus(); s.value = email; fire(s, 'input');
    const item = () => Array.from(document.querySelectorAll('#rgDropdown .rg-dropdown-item')).find(i => i.textContent.toLowerCase().indexOf(email.toLowerCase()) !== -1);
    await until(() => !!item(), 20000, 'returning guest in the search list');
    item().click();
    await until(() => $('guestEmail').value.trim().toLowerCase() === email.toLowerCase(), 5000, 'guest fields filled');
  }
  async function pickCredit(id) {
    $('creditLoadBtn').click();
    const box = () => document.querySelector('.credit-cn-check[value="' + id + '"]');
    await until(() => !!box(), 20000, 'credit note listed');
    if (!box().checked) box().click();
    await settle();
  }
  function emailTick(c) {
    const s = $('sendEmail');
    if (!s) return;
    if (c.sendEmail === false && s.checked) s.click();
    if (c.sendEmail === true && !s.checked) s.click();
  }
  async function fill(c) {
    if (!c.prefilled) { set($('checkInDate'), c.ci); set($('checkOutDate'), c.co); }
    await sleep(300); await until(availReady, 30000, 'availability');
    set($('numGuests'), String(c.guests));
    if (c.children) set($('childGuests'), String(c.children));
    if (c.requests) set(document.querySelector('[name="special_requests"]'), c.requests);
    probe('step 1');
    await next(1);
    if (!c.prefilled) {
      const free = roomsData.filter(r => typeLeft(r.id) >= (c.distinct ? 1 : c.rooms)).map(r => r.id);
      if (free.length < (c.distinct ? c.rooms : 1)) throw new Error('not enough free room types for the case');
      for (let i = 0; i < c.rooms; i++) {
        if (lines().length <= i) addRoomLine();
        set(lines()[i], String(free[c.distinct ? i : 0]));
      }
      await sleep(300); await until(availReady, 30000, 'rooms');
    }
    probe('step 2');
    await next(2);
    if (c.rg) { await pickGuest(c.rg); } else { set($('guestName'), c.name); set($('guestEmail'), c.email); set($('guestPhone'), c.phone); }
    probe('step 3');
    await next(3);
    setStatus(c.status);
    await sleep(200); await until(() => !busy(), 30000, 'status');
    if (c.credit) await pickCredit(c.credit);
    if (c.paid) {
      const cb = $('paymentReceivedCheck'); if (!cb.checked) cb.click();
      await sleep(200); await until(() => !busy(), 30000, 'paid');
      set($('paymentMethod'), c.method || 'cash');
      if (c.amount === 'over') {
        await settle();
        set($('amountCollected'), (Number(cbQ.quote.totals.total_with_vat) + 5000).toFixed(2));
      } else if (c.amount !== undefined && c.amount !== null) {
        set($('amountCollected'), String(c.amount));
      }
    }
    if (c.status === 'tentative') {
      if (c.validDays) set($('quotationValidDays'), String(c.validDays));
      if (c.quoteNote) set($('quotationNotes'), c.quoteNote);
      if (c.sendQuotation === false && $('sendQuotationCheck') && $('sendQuotationCheck').checked) $('sendQuotationCheck').click();
    }
    await sleep(200); await until(() => !busy(), 30000, 'payment');
    if (c.stop4) { emailTick(c); await settle(); probe('step 4'); return true; }
    probe('step 4');
    await next(4);
    if ($('cbSection5') && $('cbSection6')) await next(5); // the old form had Payment as its own section
    if (c.groupRef !== undefined) set(document.querySelector('[name="group_reference"]'), c.groupRef);
    if (c.notes !== undefined) set(document.querySelector('[name="admin_notes"]'), c.notes);
    emailTick(c);
    return true;
  }
  function reviewReady() { return typeof cbNav !== 'undefined' && cbNav.current === 5 && cbQ.fresh && !busy() && !!document.querySelector('#cbReview .cb-review-totals'); }
  // The Review figures as staff read them, the summary panel, and the price check they came from.
  function review() {
    const rows = {};
    document.querySelectorAll('#cbReview .cb-review-totals tr').forEach(function (tr) {
      const th = tr.querySelector('th'), td = tr.querySelector('td');
      if (!th || !td) return;
      rows[(th.firstChild && th.firstChild.nodeType === 3 ? th.firstChild.textContent : th.textContent).trim()] = money(td.textContent);
    });
    const ths = Array.from(document.querySelectorAll('#cbReview .cb-review-totals th'));
    const dts = Array.from(document.querySelectorAll('#cbSummary dt'));
    const q = cbQ.quote || { totals: {}, payment: {}, credit: {} };
    return {
      total: rows['Total'], paidNow: rows['Paid now'], credit: rows['Credit to apply'], owed: rows['Still owed'],
      state: txt($('cbReviewPayState')),
      helpsOk: ths.length >= 7 && ths.every(function (th) { return !!th.querySelector('i.help[data-tip]'); }),
      rooms: document.querySelectorAll('#cbReview .cb-review-table tbody tr').length,
      sum: { total: money(txt($('cbSumTotal'))), paid: money(txt($('cbSumPaid'))), owed: money(txt($('cbSumOwed'))), state: txt($('cbSumState')),
             helpsOk: dts.length >= 5 && dts.every(function (dt) { return !!dt.querySelector('i.help[data-tip]'); }) },
      q: { total: Number(q.totals.total_with_vat), final: q.payment.final_status, will: Number(q.credit && q.credit.will_apply) || 0,
           owed: Number(q.still_owed), amount: Number(q.payment.amount) || 0, over: Number(q.payment.over_by) || 0, key: q.email_key || '' },
      step: cbNav.current, fresh: !!cbQ.fresh
    };
  }
  // Edit wording on Review: the shared window (admin/js/email-wording.js), one line added at the top, "Use this wording".
  async function editWording(marker) {
    $('cbWordingBtn').click();
    const ed = () => document.querySelector('.ew-overlay .ew-editor');
    await until(() => ed() && ed().getAttribute('contenteditable') === 'true' && !ed().querySelector('.ew-loading') && ed().children.length > 0, 20000, 'wording window');
    const p = document.createElement('p'); p.textContent = marker;
    ed().insertBefore(p, ed().firstChild);
    fire(ed(), 'input');
    document.querySelector('.ew-overlay [data-ew-done]').click();
    await until(() => !document.querySelector('.ew-overlay'), 5000, 'wording window closed');
    return { key: $('cbWKey').value, has: $('cbWHtml').value.indexOf(marker) !== -1, state: txt($('cbWordingState')), locked: !!document.querySelector('.ew-chip--locked') };
  }
  // The form as staff see it (after a refused save: what came back).
  function state() {
    return {
      step: typeof cbNav !== 'undefined' ? cbNav.current : 0, err3: txt($('cbErr3')), err4: txt($('cbErr4')),
      ci: $('checkInDate').value, co: $('checkOutDate').value, guests: $('numGuests').value,
      lines: lines().map(function (s) { return s.value; }),
      name: $('guestName').value, email: $('guestEmail').value, phone: $('guestPhone').value,
      requests: (document.querySelector('[name="special_requests"]') || {}).value || '',
      paid: $('paymentReceivedCheck').checked, method: $('paymentMethod').value, amount: $('amountCollected').value,
      tick: $('overCollectedCb').checked, status: (document.querySelector('input[name="booking_status"]:checked') || {}).value || '',
      focus: document.activeElement ? (document.activeElement.id || document.activeElement.name || '') : ''
    };
  }
  return { sleep, $, fire, set, until, lines, availReady, next, settle, setStatus, fill, busy, probe, ui, review, reviewReady, editWording, state, money, pickGuest, emailTick };
})();
true;`;

function ymd(d) { return d.toISOString().slice(0, 10); }
function day(n) { const d = new Date(); d.setUTCHours(12, 0, 0, 0); d.setUTCDate(d.getUTCDate() + n); return ymd(d); }
const near = (a, b) => a !== null && a !== undefined && b !== null && b !== undefined && !isNaN(Number(a)) && !isNaN(Number(b)) && Math.abs(Number(a) - Number(b)) <= 0.01;
const f2 = (n) => (Math.round(Number(n) * 100) / 100).toFixed(2);

try {
  // ── Capture mode: what the browser posts on Create, caught before it leaves ──
  if (ARGS.capture) {
    fetchMode = 'capture';
    await send('Fetch.enable', { patterns: [{ urlPattern: '*create-booking.php*', requestStage: 'Request' }] });
    await viewport(1280);
    await nav(BASE + '/__devlogin?to=create-booking.php');
    // Fixed dates: the same stays as the capture taken before the change, so the same prices and amounts.
    const cases = {
      paid_full_auto_amount: { ci: '2027-04-26', co: '2027-04-28', guests: 2, rooms: 1, name: 'Capture Paid', email: 'capture.paid@example.invalid', phone: '+265 999 000 101', status: 'confirmed', paid: true, method: 'cash' },
      tentative_quotation:   { ci: '2027-05-06', co: '2027-05-09', guests: 1, rooms: 1, name: 'Capture Tentative', email: 'capture.tent@example.invalid', phone: '+265 999 000 102', status: 'tentative', validDays: 14, quoteNote: 'Capture note' },
      two_types_part_pay:    { ci: '2027-05-16', co: '2027-05-18', guests: 2, rooms: 2, distinct: true, name: 'Capture Two', email: 'capture.two@example.invalid', phone: '+265 999 000 103', status: 'pending', paid: true, method: 'mobile_money', amount: '1000.00', groupRef: 'CAP-GRP', notes: 'Capture internal note' },
    };
    const out = {};
    for (const [name, c] of Object.entries(cases)) {
      await nav(BASE + '/admin/create-booking.php');
      await js(HELPERS);
      captured = null;
      await js(`__t.fill(${JSON.stringify(c)})`);
      await js(`document.getElementById('cbSubmitBtn').click(); true`);
      for (let i = 0; i < 150 && !captured; i++) await sleep(200);
      check(!!captured, `${name}: Create posted the form (caught, not sent)`);
      out[name] = captured || [];
      out[name + ':status'] = c.status;
    }
    writeFileSync(ARGS.capture, JSON.stringify(out, null, 1));
    console.log('capture written: ' + ARGS.capture);
  } else {
    await fullRun();
  }
} catch (e) {
  check(false, 'run', e.message);
} finally {
  if (pageErrors.length) console.log('page errors:\n  ' + [...new Set(pageErrors)].join('\n  '));
  if (phpErrors.length) console.log('php errors:\n  ' + [...new Set(phpErrors)].slice(0, 20).join('\n  '));
  await stopAll();
}
console.log(`\ncreate_booking_steps_browser: ${pass} passed, ${fail} failed`);
process.exit(fail || pageErrors.length ? 1 : 0);

// ── Full run: every case creates through the real Create, checks the stored rows against Review, then cancels ──
async function fullRun() {
  const RUN = Date.now().toString(36).slice(-6);
  const ONLY = ARGS.only ? new Set(String(ARGS.only).split(',')) : null;
  const WIDTHS = ARGS.width ? [Number(ARGS.width)] : [1280, 390];
  const maxId = (t) => Number(select(`SELECT COALESCE(MAX(id), 0) AS m FROM ${t}`)[0].m);
  const start = { booking: maxId('bookings'), email: maxId('email_send_log') };
  console.log(`run ${RUN}: bookings after id ${start.booking}, emails after id ${start.email}`);
  const createdIds = new Set(), createdRefs = [], cancelledRefs = [], keepers = [];
  let emailCaseTotal = 0, overNote = null;

  const group = (id) => select('SELECT id, booking_reference, status, payment_status, total_with_vat, amount_paid, room_id, individual_room_id, guest_email FROM bookings WHERE id = ? OR primary_booking_id = ? ORDER BY id', [id, id]);
  const emailsAfter = (id) => select('SELECT id, status, email_kind FROM email_send_log WHERE id > ? ORDER BY id', [id]);
  const isSend = (g) => g.status === 'sent' || g.status === 'failed'; // an attempt to send; 'not_sent' / 'preview' are not
  const inList = (ids) => ids.map(() => '?').join(',');

  async function open(url) { await nav(url); await js(HELPERS); }
  function mk(ctx, from, nights, extra) {
    const o = ctx.W >= 500 ? 200 : 236;
    return Object.assign({ ci: day(o + from), co: day(o + from + nights), guests: 1, rooms: 1, name: `Steps ${ctx.key} ${ctx.W}`,
      email: `steps.${ctx.key}.${ctx.W}.${RUN}@example.invalid`, phone: '+265 999 000 ' + String(100 + from), status: 'confirmed', sendEmail: false }, extra || {});
  }
  async function toReview(ctx, c) {
    await js(`__t.fill(${JSON.stringify(c)})`);
    await waitFor('__t.reviewReady()', 60000, ctx.label + ': Review');
    return js('__t.review()');
  }
  function checkReview(ctx, rv) {
    check(rv.step === 5 && rv.fresh, `${ctx.label}: Review open on an up-to-date price check`);
    check(rv.helpsOk && rv.sum.helpsOk, `${ctx.label}: every figure on Review and on the summary has its ? help`);
    check(near(rv.total, rv.q.total) && near(rv.owed, rv.q.owed), `${ctx.label}: Review total ${f2(rv.total)} and still owed ${f2(rv.owed)} are the save's own figures`, JSON.stringify(rv.q));
    check(near(rv.sum.total, rv.total) && near(rv.sum.owed, rv.owed) && rv.sum.state === '', `${ctx.label}: the summary panel shows the same total and still owed`, JSON.stringify(rv.sum));
  }
  // Create: the real button, the real POST. Returns the new booking id, or the refusal shown on the page.
  async function create(label) {
    lastPost = null; postBlocked = '';
    await js(`window.__cbCreate = 1; document.getElementById('cbSubmitBtn').click(); true`);
    const t0 = Date.now();
    while (Date.now() - t0 < 120000) {
      await sleep(400);
      if (postBlocked) return { id: 0, refused: postBlocked, blocked: true };
      let s = null;
      try {
        s = await js(`({ u: location.href, rs: document.readyState, same: !!window.__cbCreate,
          err: (function () { var e = document.querySelector('.cb-panel.is-active .cb-section-error'); return e && e.style.display !== 'none' ? e.textContent.trim() : ''; })(),
          busy: !!(document.getElementById('cbSubmitBtn') && document.getElementById('cbSubmitBtn').disabled),
          repost: (typeof cbRepost !== 'undefined' && cbRepost && cbRepost.message) ? String(cbRepost.message) : '' })`);
      } catch { continue; }
      if (!s || s.rs !== 'complete') continue;
      if (s.same) {
        // Still the page Create was pressed on (a refused page keeps its earlier refusal in cbRepost, so it is not
        // read here): only the page's own checks can have stopped it, with nothing posted.
        if (!lastPost && s.err && !s.busy && Date.now() - t0 > 4000) return { id: 0, refused: 'page: ' + s.err };
        continue;
      }
      const m = s.u.match(/booking-details\.php\?id=(\d+)/);
      if (m) return { id: Number(m[1]) };
      if (s.repost) return { id: 0, refused: s.repost };
      return { id: 0, refused: 'unexpected page after Create: ' + s.u };
    }
    throw new Error(label + ': Create did not finish');
  }
  // Every booking is recorded by the id Create returned, before anything is checked, so it is always cancelled.
  function track(ctx, id, opts = {}) {
    const rows = group(id);
    rows.forEach(r => { createdIds.add(Number(r.id)); createdRefs.push(r.booking_reference); });
    const m = { id, group: rows.length > 1, keep: !!opts.keep, label: ctx.label, cancelled: false };
    ctx.made.push(m);
    console.log(`   created ${rows.map(r => r.booking_reference).join(' + ')} (id ${id})`);
    return m;
  }
  async function createOk(ctx, opts = {}) {
    const r = await create(ctx.label);
    if (r.id) track(ctx, r.id, opts);
    check(r.id > 0, `${ctx.label}: Create saved the booking`, r.refused || '');
    return r.id;
  }
  // Stored rows (all rooms of the booking) against what Review showed.
  function verify(ctx, id, rv, c, exp = {}) {
    const rows = group(id);
    const tot = rows.reduce((s, r) => s + Number(r.total_with_vat), 0);
    const paid = rows.reduce((s, r) => s + Number(r.amount_paid), 0);
    const expPaid = exp.paid !== undefined ? exp.paid : rv.total - rv.owed;
    check(rows.length === (c.rooms || 1), `${ctx.label}: ${c.rooms || 1} room row(s) stored`, String(rows.length));
    check(near(tot, rv.total), `${ctx.label}: stored total ${f2(tot)} = Review total ${f2(rv.total)}`);
    check(near(paid, expPaid), `${ctx.label}: stored paid ${f2(paid)} = ${f2(expPaid)} (Review: paid now ${f2(rv.paidNow)}, credit ${f2(rv.credit)}, still owed ${f2(rv.owed)})`);
    const st = rows.length === 1 ? rows[0].payment_status : (paid >= tot - 0.01 ? 'paid' : (paid > 0.01 ? 'partial' : 'unpaid'));
    check(st === rv.q.final, `${ctx.label}: payment status "${st}" = Review "${rv.state}"${rows.length > 1 ? ' (rooms: ' + rows.map(r => r.payment_status).join(', ') + ')' : ''}`);
    check(rows.every(r => r.status === c.status), `${ctx.label}: status ${c.status}`, rows.map(r => r.status).join(','));
    return rows;
  }
  async function cancel(label, m) {
    let token = '';
    try { token = await js(`(function () { if (location.pathname.indexOf('/admin/') !== 0) return ''; var a = document.querySelector('meta[name="csrf-token"]'), b = document.querySelector('input[name="csrf_token"]'); return window._rhCsrf || (a && a.content) || (b && b.value) || ''; })()`); } catch {}
    if (!token) { await nav(BASE + '/admin/create-booking.php'); token = await js(`(document.querySelector('input[name="csrf_token"]') || {}).value || ''`); }
    const res = await js(`(async function () {
      var fd = new FormData();
      fd.append('csrf_token', ${JSON.stringify(token)});
      fd.append('action', 'update_status');
      fd.append('id', '${m.id}');
      fd.append('status', 'cancelled');
      fd.append('cancellation_reason', 'Automated test run (New Booking steps check): test booking cancelled by the test');
      fd.append('email_guest', '0');
      ${m.group ? "fd.append('scope', 'group');" : ''}
      var r = await fetch('/admin/bookings.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      var t = await r.text();
      try { return JSON.parse(t); } catch (e) { return { success: false, message: 'not JSON: ' + t.replace(/<[^>]*>/g, ' ').replace(/\\s+/g, ' ').trim().slice(0, 200) }; }
    })()`);
    const rows = group(m.id);
    const ok = !!(res && res.success) && rows.length > 0 && rows.every(r => r.status === 'cancelled');
    check(ok, `${label}: cancelled through the app, guest email off (${rows.map(r => r.booking_reference).join(' + ')})`, (res && res.message) + ' / ' + rows.map(r => r.status).join(','));
    rows.filter(r => r.status === 'cancelled').forEach(r => cancelledRefs.push(r.booking_reference));
    m.cancelled = ok;
  }
  // One case: its own emails are counted (expected: ctx.emails, 'some' = at least one), its bookings cancelled at the end
  // whatever happened (the 1280 overpay booking is kept as the credit source and cancelled last).
  async function runCase(key, W, shared, fn) {
    if (ONLY && !ONLY.has(key)) return;
    const ctx = { key, W, shared, label: `${W} ${key}`, made: [], emails: 0 };
    ctx.cancelNow = async (m) => { await cancel(ctx.label, m); };
    console.log(`\n-- ${ctx.label}`);
    let e0 = null;
    try { e0 = maxId('email_send_log'); await fn(ctx); }
    catch (e) { check(false, ctx.label, e.message); }
    finally {
      fetchMode = null;
      try {
        if (e0 !== null) {
          const rowsE = emailsAfter(e0), got = rowsE.filter(isSend);
          emailCaseTotal += got.length;
          const okMail = ctx.emails === 'some' ? got.length >= 1 : got.length === ctx.emails;
          check(okMail, `${ctx.label}: ${got.length} email(s) sent (expected ${ctx.emails === 'some' ? 'at least 1' : ctx.emails})`, got.map(g => g.email_kind + '/' + g.status).join(', '));
          if (rowsE.length) console.log('   email log: ' + rowsE.map(g => `${g.email_kind}/${g.status}`).join(', ') + (got.length ? ' (sends go to the test recipient on the test platform)' : ''));
        }
      } catch (e) { check(false, ctx.label + ': email count', e.message); }
      for (const m of ctx.made) {
        if (m.cancelled) continue;
        if (m.keep) { keepers.push(m); continue; }
        try { await cancel(ctx.label, m); } catch (e) { check(false, `${ctx.label}: cancel booking id ${m.id}`, e.message); }
      }
    }
  }

  await send('Fetch.enable', { patterns: [{ urlPattern: '*create-booking.php*', requestStage: 'Request' }] });
  try {
    for (const W of WIDTHS) {
      await viewport(W);
      await nav(BASE + '/__devlogin?to=create-booking.php');
      const shared = {};

      // Reduced motion: no fade between steps when the system asks for less motion; the fade only when allowed.
      await runCase('motion', W, shared, async (ctx) => {
        await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
        try {
          await open(BASE + '/admin/create-booking.php');
          const c = mk(ctx, 40, 1);
          const rm = await js(`(async function () {
            var t = __t, a1 = getComputedStyle(document.querySelector('.cb-panel.is-active')).animationName;
            t.set(t.$('checkInDate'), '${c.ci}'); t.set(t.$('checkOutDate'), '${c.co}');
            await t.sleep(300); await t.until(t.availReady, 30000, 'availability');
            t.set(t.$('numGuests'), '1'); await t.next(1);
            return { mq: matchMedia('(prefers-reduced-motion: reduce)').matches, a1: a1, a2: getComputedStyle(document.querySelector('.cb-panel.is-active')).animationName, step: cbNav.current };
          })()`);
          check(rm.mq && rm.a1 === 'none' && rm.a2 === 'none' && rm.step === 2, `${ctx.label}: with reduced motion the steps change without the fade`, JSON.stringify(rm));
        } finally {
          await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });
        }
        await open(BASE + '/admin/create-booking.php');
        const a = await js(`getComputedStyle(document.querySelector('.cb-panel.is-active')).animationName`);
        check(a === 'cbPanelIn', `${ctx.label}: with motion allowed the step fades in`, a);
      });

      // 1 room, 2 nights, paid in full (amount filled for staff) + the screen checks on every step.
      await runCase('paid', W, shared, async (ctx) => {
        const c = mk(ctx, 0, 2, { guests: 2, paid: true, method: 'cash' });
        await open(BASE + '/admin/create-booking.php');
        const rv = await toReview(ctx, c);
        await js(`__t.probe('review')`);
        checkReview(ctx, rv);
        check(near(rv.paidNow, rv.total) && near(rv.owed, 0) && rv.q.final === 'paid', `${ctx.label}: Review shows paid in full (${f2(rv.paidNow)} of ${f2(rv.total)}, "${rv.state}")`, JSON.stringify(rv.q));
        const ui = await js('__t.ui');
        check(ui.length >= 5 && ui.every(u => !u.side), `${ctx.label}: no side-scroll on steps 1 to 5`, JSON.stringify(ui.filter(u => u.side)));
        check(ui.every(u => !u.small.length), `${ctx.label}: step bar, Continue/Back/Create, status cards and Change are 44px or taller`, JSON.stringify(ui.filter(u => u.small.length).map(u => u.tag + ': ' + u.small.join(' '))));
        if (W < 500) check(ui.every(u => !u.covered.length), `${ctx.label}: the summary bar never covers Continue, Back or Create`, JSON.stringify(ui.filter(u => u.covered.length).map(u => u.tag + ': ' + u.covered.join(' '))));
        const sp = await js(`(function () { var e = document.getElementById('cbSummary'), s = e.getBoundingClientRect(), m = document.querySelector('.cb-main').getBoundingClientRect();
          return { pos: getComputedStyle(e).position, bottom: Math.round(s.bottom), left: Math.round(s.left), right: Math.round(s.right), mainRight: Math.round(m.right), vh: innerHeight, vw: innerWidth }; })()`);
        if (W > 900) check(sp.pos === 'sticky' && sp.left >= sp.mainRight, `${ctx.label}: the summary is the right-hand column`, JSON.stringify(sp));
        else check(sp.pos === 'fixed' && Math.abs(sp.bottom - sp.vh) <= 2 && sp.right <= sp.vw + 1, `${ctx.label}: the summary is the bottom bar`, JSON.stringify(sp));
        const id = await createOk(ctx);
        if (!id) return;
        verify(ctx, id, rv, c);
        shared.paid = { email: c.email, name: c.name, phone: c.phone };
      });

      // Part payment: typed amount below the total.
      await runCase('part', W, shared, async (ctx) => {
        const c = mk(ctx, 3, 2, { paid: true, method: 'bank_transfer', amount: '1000.00' });
        await open(BASE + '/admin/create-booking.php');
        const rv = await toReview(ctx, c);
        checkReview(ctx, rv);
        check(near(rv.paidNow, 1000) && near(rv.owed, rv.total - 1000) && rv.q.final === 'partial', `${ctx.label}: Review shows part paid (1000.00 now, ${f2(rv.owed)} owed, "${rv.state}")`, JSON.stringify(rv.q));
        const id = await createOk(ctx);
        if (!id) return;
        verify(ctx, id, rv, c);
      });

      // Overpay: stopped in step 4 without the tick; with the tick it saves (1280) and the extra becomes a credit note.
      await runCase('over', W, shared, async (ctx) => {
        const c = mk(ctx, 6, 1, { paid: true, method: 'cash', amount: 'over', stop4: true });
        await open(BASE + '/admin/create-booking.php');
        await js(`__t.fill(${JSON.stringify(c)})`);
        const s4 = await js(`(async function () { await __t.next(4); return { step: cbNav.current, err: (document.getElementById('cbErr4').textContent || '').trim(), tick: document.getElementById('overCollectedCb').checked, focus: document.activeElement && document.activeElement.id }; })()`);
        check(s4.step === 4 && s4.err.indexOf('Tick "The guest paid more than owed"') !== -1 && !s4.tick, `${ctx.label}: Continue without the tick stays on step 4 and says why`, JSON.stringify(s4));
        await js(`(async function () { document.getElementById('overCollectedCb').click(); await __t.settle(); await __t.next(4); return true; })()`);
        await waitFor('__t.reviewReady()', 60000, ctx.label + ': Review');
        const rv = await js('__t.review()');
        checkReview(ctx, rv);
        check(near(rv.q.over, 5000) && rv.q.final === 'paid' && near(rv.owed, 0) && near(rv.paidNow, rv.total + 5000), `${ctx.label}: with the tick Review shows ${f2(rv.paidNow)} paid on ${f2(rv.total)}, 5000.00 over, nothing owed`, JSON.stringify(rv.q));
        if (W > 900) {
          ctx.emails = 1; // the overpay credit note email (sent whatever the guest email tick says)
          const id = await createOk(ctx, { keep: true });
          if (!id) return;
          verify(ctx, id, rv, c, { paid: rv.total });
          const cn = select('SELECT id, credit_note_number, original_amount, amount_used, status FROM credit_notes WHERE booking_id = ? ORDER BY id', [id]);
          check(cn.length === 1 && near(cn[0].original_amount, rv.q.over), `${ctx.label}: the 5000.00 extra became credit note ${cn[0] ? cn[0].credit_note_number : '(none)'}`, JSON.stringify(cn));
          if (cn.length) overNote = { id: Number(cn[0].id), number: cn[0].credit_note_number, amount: Number(cn[0].original_amount), email: c.email, name: c.name, phone: c.phone };
        } else {
          // Phone width: the stored figures are the same save as at 1280, so the post is caught here (no second credit note email).
          fetchMode = 'capture'; captured = null;
          await js(`document.getElementById('cbSubmitBtn').click(); true`);
          for (let i = 0; i < 300 && !captured; i++) await sleep(200);
          fetchMode = null;
          const p = new Map(captured || []);
          check(!!captured && p.get('confirm_overpay') === '1' && p.get('payment_received') === '1' && near(p.get('amount_collected'), rv.paidNow), `${ctx.label}: Create posts the ticked overpay and the amount (caught here, not sent)`, JSON.stringify([...p].filter(([k]) => /pay|amount|overpay/.test(k))));
        }
      });

      // Guest credit applied: the 1280 overpay credit note, on a new booking for the same guest; cancelling gives it back.
      await runCase('credit', W, shared, async (ctx) => {
        if (!overNote) { check(false, `${ctx.label}: needs the credit note from the 1280 overpay case`); return; }
        const n0 = select('SELECT original_amount - amount_used AS bal, status FROM credit_notes WHERE id = ?', [overNote.id])[0];
        check(near(n0.bal, overNote.amount), `${ctx.label}: credit note ${overNote.number} is open with ${f2(n0.bal)} (${n0.status})`);
        const c = mk(ctx, 8, 2, { name: overNote.name, email: overNote.email, phone: overNote.phone, paid: true, method: 'cash', credit: overNote.id });
        await open(BASE + '/admin/create-booking.php');
        const rv = await toReview(ctx, c);
        checkReview(ctx, rv);
        const will = Math.min(Number(n0.bal), rv.total);
        check(near(rv.credit, will) && near(rv.paidNow, rv.total - will) && near(rv.owed, 0) && rv.q.final === 'paid', `${ctx.label}: Review: credit ${f2(rv.credit)} + paid now ${f2(rv.paidNow)} = total ${f2(rv.total)}, "${rv.state}"`, JSON.stringify(rv.q));
        const id = await createOk(ctx);
        if (!id) return;
        const rows = verify(ctx, id, rv, c);
        const ids = rows.map(r => Number(r.id));
        const ap = select(`SELECT COALESCE(SUM(amount_applied), 0) AS s FROM credit_note_applications WHERE credit_note_id = ? AND applied_to_booking_id IN (${inList(ids)})`, [overNote.id, ...ids])[0];
        check(near(ap.s, rv.credit), `${ctx.label}: ${f2(ap.s)} applied from ${overNote.number}`);
        await ctx.cancelNow(ctx.made[ctx.made.length - 1]);
        const n1 = select('SELECT original_amount - amount_used AS bal, status FROM credit_notes WHERE id = ?', [overNote.id])[0];
        check(near(n1.bal, n0.bal), `${ctx.label}: cancelling gave the credit back to ${overNote.number} (${f2(n1.bal)} open, ${n1.status})`);
      });

      // Two room types, pending, part paid by mobile money, group reference and internal note.
      await runCase('two', W, shared, async (ctx) => {
        const c = mk(ctx, 11, 2, { guests: 2, rooms: 2, distinct: true, status: 'pending', paid: true, method: 'mobile_money', amount: '1000.00', groupRef: 'STEPS-' + RUN, notes: 'Steps run internal note' });
        await open(BASE + '/admin/create-booking.php');
        const rv = await toReview(ctx, c);
        checkReview(ctx, rv);
        check(rv.rooms >= 2 && near(rv.paidNow, 1000) && rv.q.final === 'partial', `${ctx.label}: Review lists ${rv.rooms} room rows, 1000.00 paid now, "${rv.state}"`, JSON.stringify(rv.q));
        const id = await createOk(ctx);
        if (!id) return;
        verify(ctx, id, rv, c);
      });

      // Tentative with the quotation options (the quotation email unticked for the test): no payment fields posted.
      await runCase('tent', W, shared, async (ctx) => {
        const c = mk(ctx, 14, 3, { status: 'tentative', validDays: 14, quoteNote: 'Steps run quotation note', sendQuotation: false });
        await open(BASE + '/admin/create-booking.php');
        const rv = await toReview(ctx, c);
        const s = await js(`({ dis: ['paymentReceivedHidden', 'paymentMethod', 'amountCollected', 'overCollectedCb'].every(function (i) { var e = document.getElementById(i); return !e || e.disabled; }),
          tick: document.getElementById('paymentReceivedCheck').disabled && !document.getElementById('paymentReceivedCheck').checked,
          quote: getComputedStyle(document.getElementById('quotationOptionGroup')).display !== 'none', send: document.getElementById('sendQuotationCheck').checked })`);
        check(s.dis && s.tick && s.quote && !s.send, `${ctx.label}: payment fields cleared and disabled, quotation options shown`, JSON.stringify(s));
        checkReview(ctx, rv);
        check(near(rv.paidNow, 0) && near(rv.owed, rv.total) && rv.q.final === 'unpaid', `${ctx.label}: Review shows nothing paid, "${rv.state}"`, JSON.stringify(rv.q));
        const id = await createOk(ctx);
        if (!id) return;
        const keys = new Set((lastPost || []).map(([k]) => k));
        check(!!lastPost && !keys.has('payment_received') && !keys.has('payment_method') && !keys.has('amount_collected') && !keys.has('send_quotation') && keys.has('quotation_valid_days'),
          `${ctx.label}: posted without payment_received, payment_method, amount_collected (and send_quotation unticked)`, [...keys].join(','));
        const rows = verify(ctx, id, rv, c, { paid: 0 });
        const ids = rows.map(r => Number(r.id));
        const pc = select(`SELECT COUNT(*) AS n FROM payments WHERE booking_id IN (${inList(ids)})`, ids)[0];
        check(Number(pc.n) === 0, `${ctx.label}: no payment rows`, String(pc.n));
      });

      // Returning guest: picked from the search (the guest of this run's paid case), the guest fields fill themselves.
      await runCase('returning', W, shared, async (ctx) => {
        if (!shared.paid) { check(false, `${ctx.label}: needs the paid case's guest`); return; }
        const c = mk(ctx, 18, 2, { rg: shared.paid.email });
        await open(BASE + '/admin/create-booking.php');
        const rv = await toReview(ctx, c);
        const g = await js(`({ name: __t.$('guestName').value, email: __t.$('guestEmail').value, phone: __t.$('guestPhone').value })`);
        check(g.name === shared.paid.name && g.email.toLowerCase() === shared.paid.email && g.phone.replace(/D/g, '') === shared.paid.phone.replace(/D/g, ''), `${ctx.label}: picking the guest filled name, email and phone`, JSON.stringify(g));
        checkReview(ctx, rv);
        const id = await createOk(ctx);
        if (!id) return;
        const rows = verify(ctx, id, rv, c, { paid: 0 });
        check(String(rows[0].guest_email).toLowerCase() === shared.paid.email, `${ctx.label}: stored for the returning guest`);
      });

      // Calendar prefill link: opens on step 1 with the dates, the room type and the room already picked.
      await runCase('prefill', W, shared, async (ctx) => {
        const c = mk(ctx, 21, 2, { prefilled: true });
        await open(BASE + '/admin/create-booking.php');
        const pick = await js(`(async function () {
          var t = __t; t.set(t.$('checkInDate'), '${c.ci}'); t.set(t.$('checkOutDate'), '${c.co}');
          await t.sleep(300); await t.until(t.availReady, 30000, 'availability');
          var type = roomsData.map(function (r) { return r.id; }).find(function (id) { return typeLeft(id) >= 1; });
          if (!type) return null;
          if (!t.lines().length) addRoomLine();
          t.set(t.lines()[0], String(type));
          await t.sleep(300); t.$('irModePick').click();
          try { await t.until(function () { return !!document.querySelector('input[name="individual_room_id"]:not([disabled])'); }, 20000, 'rooms of the type'); } catch (e) { return { type: Number(type), room: 0 }; }
          return { type: Number(type), room: Number(document.querySelector('input[name="individual_room_id"]:not([disabled])').value) };
        })()`);
        if (!pick) throw new Error('no free room type for the prefill dates');
        await open(`${BASE}/admin/create-booking.php?check_in=${c.ci}&check_out=${c.co}&room_type=${pick.type}${pick.room ? '&room=' + pick.room : ''}`);
        const s1 = await js(`({ step: cbNav.current, ci: __t.$('checkInDate').value, co: __t.$('checkOutDate').value })`);
        check(s1.step === 1 && s1.ci === c.ci && s1.co === c.co, `${ctx.label}: the Calendar link opens step 1 with its dates`, JSON.stringify(s1));
        await waitFor('__t.availReady()', 30000, 'availability');
        if (pick.room) await waitFor(`!!document.querySelector('input[name="individual_room_id"]:checked')`, 20000, 'prefilled room');
        const s2 = await js(`({ line: (__t.lines()[0] || {}).value, room: (document.querySelector('input[name="individual_room_id"]:checked') || {}).value || '' })`);
        check(s2.line === String(pick.type) && (!pick.room || s2.room === String(pick.room)), `${ctx.label}: room type ${pick.type}${pick.room ? ' and room ' + pick.room : ''} already picked`, JSON.stringify(s2));
        const rv = await toReview(ctx, c);
        checkReview(ctx, rv);
        const id = await createOk(ctx);
        if (!id) return;
        const rows = verify(ctx, id, rv, c, { paid: 0 });
        check(Number(rows[0].room_id) === pick.type && (!pick.room || Number(rows[0].individual_room_id) === pick.room), `${ctx.label}: stored on the linked room type and room`, JSON.stringify(rows[0]));
      });

      // Dates changed after paying: the figures go stale at once, the next check refreshes them and the amount follows.
      await runCase('dates', W, shared, async (ctx) => {
        const c = mk(ctx, 24, 2, { paid: true, method: 'cash' });
        await open(BASE + '/admin/create-booking.php');
        const rv1 = await toReview(ctx, c);
        checkReview(ctx, rv1);
        const newCo = day((W >= 500 ? 200 : 236) + 24 + 3);
        const st = await js(`(async function () { cbJumpTo(1); await __t.sleep(300); __t.set(__t.$('checkOutDate'), '${newCo}'); await __t.sleep(60);
          return { step: cbNav.current, fresh: cbQ.fresh, stale: __t.$('cbSummary').classList.contains('is-stale'), sum: (__t.$('cbSumState').textContent || '').trim() }; })()`);
        check(st.step === 1 && !st.fresh && st.stale && /checking|not up to date/.test(st.sum), `${ctx.label}: changing the dates marks the figures stale at once ("${st.sum}")`, JSON.stringify(st));
        await js(`(async function () { await __t.sleep(300); await __t.until(__t.availReady, 30000, 'availability'); await __t.next(1); await __t.next(2); await __t.next(3); await __t.settle(); return true; })()`);
        const s4 = await js(`({ step: cbNav.current, fresh: cbQ.fresh, amount: Number(__t.$('amountCollected').value), total: Number(cbQ.quote.totals.total_with_vat) })`);
        check(s4.step === 4 && s4.fresh && near(s4.amount, s4.total) && !near(s4.total, rv1.total), `${ctx.label}: step 4 refreshed: amount ${f2(s4.amount)} follows the new total ${f2(s4.total)} (was ${f2(rv1.total)})`, JSON.stringify(s4));
        await js('__t.next(4)');
        await waitFor('__t.reviewReady()', 60000, ctx.label + ': Review');
        const rv = await js('__t.review()');
        checkReview(ctx, rv);
        check(near(rv.total, s4.total) && near(rv.paidNow, rv.total) && rv.q.final === 'paid', `${ctx.label}: Review shows the new total paid in full`, JSON.stringify(rv.q));
        const id = await createOk(ctx);
        if (!id) return;
        verify(ctx, id, { ...rv }, { ...c, co: newCo });
      });

      // Refused save: the real POST leaves without the overpay tick, the save refuses it (nothing saved, nothing sent),
      // the page comes back on step 4 with everything typed, the tick never restored; then a correct amount saves.
      await runCase('refused', W, shared, async (ctx) => {
        const c = mk(ctx, 28, 2, { guests: 2, paid: true, method: 'mobile_money', amount: 'over', stop4: true, requests: 'Steps run: late arrival' });
        await open(BASE + '/admin/create-booking.php');
        await js(`__t.fill(${JSON.stringify(c)})`);
        await js(`(async function () { document.getElementById('overCollectedCb').click(); await __t.settle(); await __t.next(4); return true; })()`);
        await waitFor('__t.reviewReady()', 60000, ctx.label + ': Review');
        const before = await js('__t.state()');
        await js(`(function () { var f = document.getElementById('createBookingForm'); f.addEventListener('submit', function h() { f.removeEventListener('submit', h); document.getElementById('overCollectedCb').checked = false; }); return true; })()`);
        fetchMode = 'no-overpay';
        const r = await create(ctx.label);
        fetchMode = null;
        if (r.id) track(ctx, r.id);
        check(!r.id && !r.blocked && !!r.refused, `${ctx.label}: the save refused the post, no booking ("${String(r.refused || '').slice(0, 90)}")`, r.id ? 'booking ' + r.id : String(r.refused));
        if (r.id || r.blocked) return;
        await js(HELPERS);
        await waitFor('typeof cbQ !== "undefined" && !!cbQ.quote && !__t.busy()', 60000, 'price check after the refusal');
        const after = await js('__t.state()');
        check(after.step === 4 && after.err4.indexOf(String(r.refused).slice(0, 40)) !== -1, `${ctx.label}: the page opens step 4 with the refusal`, JSON.stringify({ step: after.step, err4: after.err4 }));
        const same = ['ci', 'co', 'guests', 'name', 'email', 'phone', 'requests', 'method', 'status'].filter(k => after[k] !== before[k]);
        check(same.length === 0 && JSON.stringify(after.lines) === JSON.stringify(before.lines) && after.paid && near(after.amount, before.amount),
          `${ctx.label}: nothing lost: dates, guests, rooms, guest, requests, payment ${after.method} ${after.amount}`, JSON.stringify({ changed: same, before, after }));
        check(!after.tick, `${ctx.label}: the overpay tick is not restored (staff confirm it again)`);
        check(after.focus === 'overCollectedCb', `${ctx.label}: the overpay tick has the focus`, after.focus);
        // A refused page comes back with the guest email ticked (its markup default, as on the old form): untick it again.
        await js(`(async function () { __t.emailTick(${JSON.stringify(c)}); __t.set(__t.$('amountCollected'), Number(cbQ.quote.totals.total_with_vat).toFixed(2)); await __t.settle(); await __t.next(4); return true; })()`);
        await waitFor('__t.reviewReady()', 60000, ctx.label + ': Review after the fix');
        const rv = await js('__t.review()');
        checkReview(ctx, rv);
        check(near(rv.paidNow, rv.total) && near(rv.q.over, 0) && rv.q.final === 'paid', `${ctx.label}: with the amount lowered to the total Review shows paid in full`, JSON.stringify(rv.q));
        const id = await createOk(ctx);
        if (!id) return;
        verify(ctx, id, rv, c);
        const cn = select('SELECT COUNT(*) AS n FROM credit_notes WHERE booking_id = ?', [id])[0];
        check(Number(cn.n) === 0, `${ctx.label}: no credit note`);
      });

      // Edit wording on Review (1280): the edited email goes out, History says so (email_send_log keeps no body).
      await runCase('w1', W, shared, async (ctx) => {
        if (W < 500) return;
        const c = mk(ctx, 32, 1, { sendEmail: true });
        await open(BASE + '/admin/create-booking.php');
        const rv = await toReview(ctx, c);
        checkReview(ctx, rv);
        const marker = 'Steps run wording check ' + RUN;
        const ew = await js(`__t.editWording(${JSON.stringify(marker)})`);
        check(!!ew.key && ew.key === rv.q.key && ew.has && /edited wording will be used/.test(ew.state), `${ctx.label}: Edit email wording on Review opens the same window and keeps the edit for the ${ew.key} email`, JSON.stringify(ew));
        ctx.emails = 'some';
        const id = await createOk(ctx);
        if (!id) return;
        const p = new Map(lastPost || []);
        check(p.get('email_wording_key') === ew.key && String(p.get('email_wording_html') || '').indexOf(marker) !== -1 && p.has('send_email'), `${ctx.label}: Create posted the edited wording with the guest email ticked`);
        verify(ctx, id, rv, c, { paid: 0 });
        const au = select("SELECT id, note FROM booking_audit_log WHERE booking_id = ? AND action = 'email_sent' AND note LIKE 'Email wording edited before sending by %'", [id]);
        check(au.length === 1, `${ctx.label}: History: "${au[0] ? au[0].note : ''}"`, JSON.stringify(au));
      });

      // Same, but the payment changes after the edit: the edit is cleared with a plain note, the booking still saves.
      await runCase('w2', W, shared, async (ctx) => {
        if (W < 500) return;
        const c = mk(ctx, 34, 1, { sendEmail: true });
        await open(BASE + '/admin/create-booking.php');
        const rv1 = await toReview(ctx, c);
        checkReview(ctx, rv1);
        const ew = await js(`__t.editWording(${JSON.stringify('Steps run wording check (cleared) ' + RUN)})`);
        check(!!ew.key && ew.has, `${ctx.label}: wording edited on Review`, JSON.stringify(ew));
        const st = await js(`(async function () { cbJumpTo(4); await __t.sleep(300); __t.$('paymentReceivedCheck').click(); await __t.settle();
          return { note: (__t.$('cbWordingState').textContent || '').trim(), key: __t.$('cbWKey').value, html: __t.$('cbWHtml').value, paid: __t.$('paymentReceivedCheck').checked }; })()`);
        check(st.paid && !st.key && !st.html && /cleared\. Edit it again if needed\.$/.test(st.note), `${ctx.label}: ticking the payment after the edit cleared it: "${st.note}"`, JSON.stringify(st));
        await js('__t.next(4)');
        await waitFor('__t.reviewReady()', 60000, ctx.label + ': Review');
        const rv = await js('__t.review()');
        const note5 = await js(`(document.getElementById('cbWordingState').textContent || '').trim()`);
        check(note5 === st.note, `${ctx.label}: the note is still shown next to the email tick on Review`, note5);
        checkReview(ctx, rv);
        check(rv.q.final === 'paid', `${ctx.label}: Review shows paid in full`, JSON.stringify(rv.q));
        ctx.emails = 'some';
        const id = await createOk(ctx);
        if (!id) return;
        const p = new Map(lastPost || []);
        check(!p.get('email_wording_key') && !p.get('email_wording_html') && p.has('send_email'), `${ctx.label}: Create posted no edited wording (the default email goes)`);
        verify(ctx, id, rv, c);
        const au = select("SELECT id FROM booking_audit_log WHERE booking_id = ? AND action = 'email_sent' AND note LIKE 'Email wording edited before sending by %'", [id]);
        check(au.length === 0, `${ctx.label}: no "edited wording" History line`);
      });
    }
  } finally {
    fetchMode = null;
    for (const m of keepers) {
      if (m.cancelled) continue;
      try { await cancel(m.label + ' (kept as the credit source, cancelled last)', m); } catch (e) { check(false, `${m.label}: cancel booking id ${m.id}`, e.message); }
    }
    try {
      if (overNote) {
        const n = select('SELECT credit_note_number, original_amount, amount_used, status FROM credit_notes WHERE id = ?', [overNote.id])[0];
        console.log(`\ncredit note ${n.credit_note_number} after the run: ${f2(Number(n.original_amount) - Number(n.amount_used))} open, status ${n.status} (test guest ${overNote.email})`);
      }
      const live = select("SELECT id, booking_reference, status FROM bookings WHERE id > ? AND status <> 'cancelled' ORDER BY id", [start.booking]);
      const ours = live.filter(r => createdIds.has(Number(r.id)));
      check(ours.length === 0, 'every booking this run created is cancelled', ours.map(r => r.booking_reference + ' ' + r.status).join(', '));
      const others = live.filter(r => !createdIds.has(Number(r.id)));
      if (others.length) console.log('other new bookings since the start (not this run, left alone): ' + others.map(r => r.booking_reference + ' ' + r.status).join(', '));
      const all = emailsAfter(start.email), mails = all.filter(isSend), skipped = all.filter(g => !isSend(g));
      check(mails.length === emailCaseTotal, `emails: ${mails.length} sent during the run, all from the cases that must send (cancels sent none)`, `${emailCaseTotal} counted inside the cases: ` + mails.map(g => g.email_kind + '/' + g.status).join(', '));
      console.log(`emails sent during the run: ${mails.length}${mails.length ? ' (' + mails.map(g => g.email_kind + '/' + g.status).join(', ') + ')' : ''}`);
      console.log(`logged as not sent (staff chose not to email): ${skipped.length}${skipped.length ? ' (' + skipped.map(g => g.email_kind + '/' + g.status).join(', ') + ')' : ''}`);
      console.log('created:   ' + (createdRefs.join(', ') || 'none'));
      console.log('cancelled: ' + (cancelledRefs.join(', ') || 'none'));
    } catch (e) { check(false, 'end of run report', e.message); }
  }
}
