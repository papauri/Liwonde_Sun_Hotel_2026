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
//   (Phase C: Create is a background POST with response=wizard; a POST with dry_run=1 is the price check and always
//   passes untouched. The plain form post (Document) is still a Create.)
//   'no-overpay'   Create sent with confirm_overpay taken out of the body (as if the tick was lost on the way)
//   'tamper-quote' Create sent with quote_total raised by 500 (as if the price changed after the Review)
//   'hold'         Create held here (held = { requestId, pairs }) until the case continues it
//   'nocookie'     the browser's cookies cleared just before Create leaves and put back when its answer arrives (the
//                  server sees no sign-in at Create; needs the Response stage on)
//   'nocookie-clear' the cookies cleared at Create and left cleared
//   'lose-reply'   Create sent and saved; its answer is dropped here (the reply never reaches the page)
let fetchMode = null; let captured = null; let lastPost = null; let postBlocked = ''; let createPosts = 0; let held = null; let loseIds = new Set(); let cookieStash = null;
const b64 = (s) => Buffer.from(s, 'utf8').toString('base64');
ws.addEventListener('message', (ev) => {
  const m = JSON.parse(ev.data);
  if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); return; }
  if (m.method === 'Runtime.exceptionThrown') pageErrors.push((m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text || '').split('\n')[0]);
  if (m.method === 'Page.javascriptDialogOpening') { dialogs.push(m.params.message); send('Page.handleJavaScriptDialog', { accept: true }); }
  if (m.method === 'Fetch.requestPaused') {
    const p = m.params;
    if (p.responseStatusCode !== undefined || p.responseErrorReason !== undefined) { // response stage ('lost' case only)
      const rid = p.networkId || p.requestId;
      if (loseIds.has(rid)) send('Fetch.failRequest', { requestId: p.requestId, errorReason: 'ConnectionReset' });
      else if (cookieStash && cookieStash.id === rid) { // sent without cookies: put them back before the page sees the answer
        const params = cookieStash.cookies.map(c => ({ name: c.name, value: c.value, domain: c.domain, path: c.path, secure: c.secure, httpOnly: c.httpOnly, sameSite: c.sameSite, expires: c.session ? undefined : c.expires }));
        cookieStash = null;
        send('Network.setCookies', { cookies: params }).then(() => send('Fetch.continueRequest', { requestId: p.requestId }));
      } else send('Fetch.continueRequest', { requestId: p.requestId });
      return;
    }
    let body = p.request.postData;
    if (body === undefined && Array.isArray(p.request.postDataEntries)) body = p.request.postDataEntries.map(e => Buffer.from(e.bytes || '', 'base64').toString('utf8')).join('');
    const pairs = p.request.method === 'POST' ? Array.from(new URLSearchParams(body || '').entries()) : [];
    const has = (k, v) => pairs.some(([a, b]) => a === k && (v === undefined || b === v));
    const isCreate = p.request.method === 'POST' && !has('dry_run', '1') && (p.resourceType === 'Document' || has('response', 'wizard'));
    if (!isCreate) { send('Fetch.continueRequest', { requestId: p.requestId }); return; }
    createPosts++;
    if (fetchMode === 'capture') {
      captured = pairs;
      send('Fetch.failRequest', { requestId: p.requestId, errorReason: 'Aborted' }); // caught here: never reaches the server
    } else if (fetchMode === 'no-overpay') {
      const out = pairs.filter(([k]) => k !== 'confirm_overpay');
      lastPost = out;
      send('Fetch.continueRequest', { requestId: p.requestId, postData: b64(new URLSearchParams(out).toString()) });
    } else if (fetchMode === 'tamper-quote') {
      const out = pairs.map(([k, v]) => k === 'quote_total' ? [k, (Number(v) + 500).toFixed(2)] : [k, v]);
      lastPost = out;
      send('Fetch.continueRequest', { requestId: p.requestId, postData: b64(new URLSearchParams(out).toString()) });
    } else if (fetchMode === 'hold') {
      lastPost = pairs;
      held = { requestId: p.requestId, pairs };
    } else if (fetchMode === 'nocookie' || fetchMode === 'nocookie-clear') {
      lastPost = pairs;
      const keep = fetchMode === 'nocookie', rid = p.networkId || p.requestId;
      send('Network.getAllCookies').then((g) => {
        if (keep) cookieStash = { id: rid, cookies: (g.result && g.result.cookies) || [] };
        return send('Network.clearBrowserCookies');
      }).then(() => send('Fetch.continueRequest', { requestId: p.requestId }));
    } else if (fetchMode === 'lose-reply') {
      lastPost = pairs;
      loseIds.add(p.networkId || p.requestId);
      send('Fetch.continueRequest', { requestId: p.requestId });
    } else {
      lastPost = pairs;
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
      if (c.leastLeft) free.sort((a, b) => typeLeft(a) - typeLeft(b)); // the type with the fewest rooms left first
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
        set($('amountCollected'), (Number(cbQ.quote.totals.total_with_vat) + (c.overBy || 5000)).toFixed(2));
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
  return { sleep, $, fire, set, until, lines, availReady, next, settle, setStatus, fill, busy, probe, ui, review, reviewReady, editWording, state, money, pickGuest, pickCredit, emailTick };
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
  // clicks > 1: a double click, plus one more press (Enter / requestSubmit) while the first is still saving.
  async function create(label, clicks = 1) {
    lastPost = null; postBlocked = '';
    await js(`window.__cbCreate = 1; (function () { var b = document.getElementById('cbSubmitBtn'), f = document.getElementById('createBookingForm');
      for (var i = 0; i < ${clicks}; i++) b.click();
      if (${clicks} > 1) setTimeout(function () { try { f.requestSubmit(); } catch (e) {} b.click(); }, 1500); })(); true`);
    return awaitCreate(label);
  }
  async function awaitCreate(label) {
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
        // read here). Nothing posted: the page's own checks stopped it. Posted (Phase C, Create in the background):
        // the save's answer is shown in the step it belongs to, with the button back.
        if (!lastPost && s.err && !s.busy && Date.now() - t0 > 4000) return { id: 0, refused: 'page: ' + s.err };
        if (lastPost && s.err && !s.busy) return { id: 0, refused: 'server: ' + s.err, server: true };
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
        // Phase C: Create saves in the background; the tick is taken out of the request on its way (never the form).
        fetchMode = 'no-overpay';
        const r = await create(ctx.label);
        fetchMode = null;
        if (r.id) track(ctx, r.id);
        const sentNoTick = !!lastPost && !lastPost.some(([k]) => k === 'confirm_overpay') && lastPost.some(([k, v]) => k === 'response' && v === 'wizard');
        check(!r.id && r.server && sentNoTick, `${ctx.label}: the save refused the post without the tick, no booking ("${String(r.refused || '').slice(0, 90)}")`, r.id ? 'booking ' + r.id : String(r.refused));
        if (r.id || !r.server) return;
        const nb = select('SELECT COUNT(*) AS n FROM bookings WHERE guest_email = ? AND id > ?', [c.email, start.booking])[0];
        check(Number(nb.n) === 0, `${ctx.label}: nothing stored for the guest`, String(nb.n));
        const after = await js('__t.state()');
        check(after.step === 4 && after.err4.indexOf(String(r.refused).replace(/^server: /, '').slice(0, 40)) !== -1 && /paid more than owed|more than/i.test(after.err4),
          `${ctx.label}: the page opens step 4 with the refusal, no reload`, JSON.stringify({ step: after.step, err4: after.err4 }));
        const same = ['ci', 'co', 'guests', 'name', 'email', 'phone', 'requests', 'method', 'status'].filter(k => after[k] !== before[k]);
        check(same.length === 0 && JSON.stringify(after.lines) === JSON.stringify(before.lines) && after.paid && near(after.amount, before.amount) && after.tick === before.tick,
          `${ctx.label}: nothing lost: dates, guests, rooms, guest, requests, payment ${after.method} ${after.amount}, the tick as staff left it`, JSON.stringify({ changed: same, before, after }));
        check(after.focus === 'overCollectedCb', `${ctx.label}: the overpay tick has the focus`, after.focus);
        check(await js(`!document.getElementById('cbSubmitBtn').disabled`), `${ctx.label}: Create is back`);
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

      // ── Phase C: Create saves in the background (response=wizard) ──
      const forGuest = (email) => Number(select('SELECT COUNT(*) AS n FROM bookings WHERE guest_email = ? AND id > ?', [email, start.booking])[0].n);
      const sameForm = (a, b) => ['ci', 'co', 'guests', 'name', 'email', 'phone', 'requests', 'method', 'amount', 'paid', 'status'].filter(k => a[k] !== b[k]).concat(JSON.stringify(a.lines) === JSON.stringify(b.lines) ? [] : ['lines']);
      const errOn = (n) => js(`(function () { var e = document.getElementById('cbErr${n}'); return e && e.style.display !== 'none' ? e.textContent.replace(/\\s+/g, ' ').trim() : ''; })()`);

      // Success: a double click (and one more press while saving) sends ONE Create; the booking page shows the success
      // flash; the same Create sent again answers with the same booking (same client_uuid), still one booking.
      await runCase('wiz', W, shared, async (ctx) => {
        const c = mk(ctx, 44, 2, { paid: true, method: 'cash' });
        await open(BASE + '/admin/create-booking.php');
        const rv = await toReview(ctx, c);
        checkReview(ctx, rv);
        createPosts = 0; dialogs = [];
        const id = (await create(ctx.label, 2)).id;
        if (id) track(ctx, id);
        check(id > 0, `${ctx.label}: Create saved the booking and opened booking-details.php?id=${id}`);
        if (!id) return;
        const p = new Map(lastPost || []);
        check(createPosts === 1, `${ctx.label}: double click + a press while saving sent one Create`, String(createPosts));
        check(p.get('response') === 'wizard' && !p.has('dry_run') && near(p.get('quote_total'), rv.total) && !!p.get('client_uuid'), `${ctx.label}: Create posted response=wizard with quote_total ${p.get('quote_total')} (Review ${f2(rv.total)})`);
        check(forGuest(c.email) === 1, `${ctx.label}: one booking for the guest`, String(forGuest(c.email)));
        await sleep(800);
        const flash = await js(`document.body.innerText.replace(/\\s+/g, ' ')`);
        const ref = group(id)[0].booking_reference;
        // The save keeps $_SESSION['flash_message'] exactly as after a plain post; booking-details.php shows it (later
        // items 2026-11-28), as plain text, once.
        check(flash.indexOf(`Booking ${ref} created successfully`) !== -1, `${ctx.label}: the booking page shows "Booking ${ref} created successfully"`, flash.slice(0, 300));
        await nav(BASE + '/admin/booking-details.php?id=' + id);
        check((await js(`document.body.innerText`)).indexOf('created successfully') === -1, `${ctx.label}: the message is shown once (gone after a reload)`);
        check(dialogs.length === 0, `${ctx.label}: no leave-page dialog`, dialogs.join(' | '));
        verify(ctx, id, rv, c);
        const again = await js(`(async function () { var r = await fetch('/admin/create-booking.php', { method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' }, body: ${JSON.stringify(new URLSearchParams(lastPost || []).toString())} });
          var t = await r.text(); try { return JSON.parse(t); } catch (e) { return { raw: t.slice(0, 200) }; } })()`);
        check(again && again.ok === true && again.duplicate === true && again.redirect === 'booking-details.php?id=' + id && forGuest(c.email) === 1,
          `${ctx.label}: the same Create sent again answers with the same booking, still one booking`, JSON.stringify(again));
      });

      // Room taken between Review and Create: Create is held, another booking takes every room left of the type, then
      // Create goes on: the save refuses at step 2, nothing is lost, nothing stored for the guest.
      await runCase('taken', W, shared, async (ctx) => {
        if (W < 500) return;
        const c = mk(ctx, 47, 2, { leastLeft: true });
        await open(BASE + '/admin/create-booking.php');
        const rv = await toReview(ctx, c);
        checkReview(ctx, rv);
        const before = await js('__t.state()');
        const type = Number(before.lines[0]);
        fetchMode = 'hold'; held = null; lastPost = null; postBlocked = '';
        await js(`window.__cbCreate = 1; document.getElementById('cbSubmitBtn').click(); true`);
        for (let i = 0; i < 300 && !held; i++) await sleep(200);
        if (!held) throw new Error('Create was not posted');
        const bl = await js(`(async function () {
          var fd = new FormData(document.getElementById('createBookingForm')), left = typeLeft(${type}) + 1; // typeLeft counts this form's own room as taken
          ['payment_method', 'amount_collected', 'send_email', 'confirm_overpay', 'email_wording_key', 'email_wording_html', 'apply_credit_note_ids[]', 'apply_credit_override', 'apply_credit_override_reason', 'send_quotation'].forEach(function (k) { fd.delete(k); });
          fd.set('payment_received', '0'); fd.set('room_line_qty[]', String(left)); fd.set('number_of_guests', String(Math.max(1, left)));
          fd.set('guest_name', 'Steps blocker ${W}'); fd.set('guest_email', 'steps.blocker.${W}.${RUN}@example.invalid');
          fd.set('client_uuid', Array.from(crypto.getRandomValues(new Uint8Array(16))).map(function (b) { return b.toString(16).padStart(2, '0'); }).join(''));
          fd.set('response', 'json');
          var r = await fetch('/admin/create-booking.php', { method: 'POST', body: new URLSearchParams(fd), credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
          var t = await r.text(); try { var j = JSON.parse(t); j.left = left; return j; } catch (e) { return { success: false, message: 'not JSON: ' + t.slice(0, 200), left: left }; }
        })()`);
        if (bl && bl.booking_id) track(ctx, Number(bl.booking_id));
        check(!!(bl && bl.success), `${ctx.label}: another booking took the ${bl && bl.left} room(s) left of type ${type}`, JSON.stringify(bl));
        fetchMode = null;
        await send('Fetch.continueRequest', { requestId: held.requestId });
        const r = await awaitCreate(ctx.label);
        if (r.id) track(ctx, r.id);
        const after = await js('__t.state()');
        const e2 = await errOn(2);
        check(!r.id && r.server && after.step === 2 && /remain available|no longer available|not available|unavailable/i.test(e2), `${ctx.label}: the save refused at step 2: "${e2.slice(0, 110)}"`, JSON.stringify({ r, step: after.step }));
        check(sameForm(before, after).length === 0, `${ctx.label}: nothing lost`, JSON.stringify(sameForm(before, after)));
        check(forGuest(c.email) === 0, `${ctx.label}: nothing stored for the guest`);
      });

      // Price changed after the Review (quote_total raised on the way): refused at step 5, nothing saved.
      await runCase('tamper', W, shared, async (ctx) => {
        if (W < 500) return;
        const c = mk(ctx, 50, 2, { paid: true, method: 'cash' });
        await open(BASE + '/admin/create-booking.php');
        const rv = await toReview(ctx, c);
        checkReview(ctx, rv);
        const before = await js('__t.state()');
        fetchMode = 'tamper-quote';
        const r = await create(ctx.label);
        fetchMode = null;
        if (r.id) track(ctx, r.id);
        const after = await js('__t.state()');
        const e5 = await errOn(5);
        check(!r.id && r.server && after.step === 5 && /^The price changed since you checked it: .+ -> .+\. Check the Review again\./.test(e5), `${ctx.label}: refused at step 5: "${e5}"`, JSON.stringify(r));
        check(sameForm(before, after).length === 0 && forGuest(c.email) === 0, `${ctx.label}: nothing lost, nothing stored`);
      });

      // Signed out at Create (the cookies cleared while Create leaves, put back when its answer comes): "It may have
      // saved", the form untouched, nothing saved; after signing in again a re-press saves ONE booking. A Create whose
      // answer is lost after it saved: a re-press finds that booking (same client_uuid) and shows it, still one booking.
      // Last, the cookies cleared for good at Create: nothing is saved, and a re-press never makes a booking.
      await runCase('lost', W, shared, async (ctx) => {
        if (W < 500) return;
        await send('Fetch.enable', { patterns: [{ urlPattern: '*create-booking.php*', requestStage: 'Request' }, { urlPattern: '*create-booking.php*', requestStage: 'Response' }] });
        try {
          const c = mk(ctx, 53, 2);
          await open(BASE + '/admin/create-booking.php');
          const rv = await toReview(ctx, c);
          checkReview(ctx, rv);
          const before = await js('__t.state()');
          createPosts = 0;
          fetchMode = 'nocookie';
          const r = await create(ctx.label);
          fetchMode = null;
          if (r.id) track(ctx, r.id);
          const where1 = await js('location.pathname');
          if (!where1.endsWith('/create-booking.php')) throw new Error('the tab left for ' + where1 + ' after Create: ' + JSON.stringify(r));
          const after = await js('__t.state()');
          const e5 = await errOn(5);
          check(!r.id && r.server && e5.indexOf('It may have saved. Check Bookings before pressing Create again.') === 0 && after.step === 5, `${ctx.label}: signed out at Create: "${e5}"`, JSON.stringify(r));
          check(sameForm(before, after).length === 0 && await js(`!document.getElementById('cbSubmitBtn').disabled`), `${ctx.label}: the form is untouched and Create is back`);
          check(forGuest(c.email) === 0, `${ctx.label}: nothing saved while signed out`);
          const st = await js(`fetch('/__devlogin?to=dashboard.php', { credentials: 'same-origin' }).then(function (r) { return r.status; })`);
          check(st === 200, `${ctx.label}: signed in again (another request, the form stays)`, String(st));
          const r2 = await create(ctx.label);
          if (r2.id) track(ctx, r2.id);
          check(r2.id > 0 && forGuest(c.email) === 1 && createPosts === 2, `${ctx.label}: re-press after signing in again saved ONE booking`, JSON.stringify({ r2, n: forGuest(c.email), createPosts }));

          const c2 = mk(ctx, 56, 2, { email: `steps.lost2.${W}.${RUN}@example.invalid` });
          await open(BASE + '/admin/create-booking.php');
          const rv2 = await toReview(ctx, c2);
          checkReview(ctx, rv2);
          loseIds = new Set();
          fetchMode = 'lose-reply';
          const r3 = await create(ctx.label);
          fetchMode = null;
          if (r3.id) track(ctx, r3.id);
          const saved = select('SELECT id FROM bookings WHERE guest_email = ? AND id > ? AND primary_booking_id IS NULL ORDER BY id', [c2.email, start.booking]);
          saved.forEach(b => track(ctx, Number(b.id)));
          const e5b = await errOn(5);
          check(!r3.id && r3.server && e5b.indexOf('It may have saved.') === 0 && saved.length === 1, `${ctx.label}: answer lost after the save: "${e5b.slice(0, 70)}", ${saved.length} booking saved`);
          const r4 = await create(ctx.label);
          if (r4.id) track(ctx, r4.id);
          const link = await js(`(function () { var a = document.querySelector('#cbErr5 a'); return a ? a.getAttribute('href') : ''; })()`);
          check(!r4.id && /Booking already created/.test(r4.refused || '') && saved.length === 1 && link === 'booking-details.php?id=' + saved[0].id && forGuest(c2.email) === 1,
            `${ctx.label}: re-press shows the booking already made (link ${link}), still one booking`, JSON.stringify({ r4, link, n: forGuest(c2.email) }));

          const c3 = mk(ctx, 59, 1, { email: `steps.lost3.${W}.${RUN}@example.invalid` });
          await open(BASE + '/admin/create-booking.php');
          const rv3 = await toReview(ctx, c3);
          checkReview(ctx, rv3);
          fetchMode = 'nocookie-clear';
          const r5 = await create(ctx.label);
          fetchMode = null;
          if (r5.id) track(ctx, r5.id);
          const where = await js('location.pathname');
          console.log(`   cookies cleared for good at Create: ${JSON.stringify(r5).slice(0, 160)}; tab on ${where}`);
          const onPage = where.endsWith('/create-booking.php');
          const e5c = onPage ? await errOn(5) : '';
          check(!r5.id && forGuest(c3.email) === 0 && (!onPage || e5c.indexOf('It may have saved.') === 0),
            `${ctx.label}: cookies cleared for good at Create: nothing saved${onPage ? ', "' + e5c.slice(0, 60) + '"' : ' (the tab went to ' + where + ')'}`);
          const st2 = await js(`fetch('/__devlogin?to=dashboard.php', { credentials: 'same-origin' }).then(function (r) { return r.status; })`);
          if (onPage) {
            const r6 = await create(ctx.label);
            if (r6.id) track(ctx, r6.id);
            check(!r6.id && forGuest(c3.email) === 0, `${ctx.label}: re-press after signing in again (a new session, status ${st2}): "${String(r6.refused || '').slice(0, 80)}", no booking`, JSON.stringify(r6));
          } else {
            check(st2 === 200 && forGuest(c3.email) === 0, `${ctx.label}: signed in again (status ${st2}): still no booking`);
          }
        } finally {
          loseIds = new Set(); cookieStash = null; fetchMode = null;
          await send('Fetch.enable', { patterns: [{ urlPattern: '*create-booking.php*', requestStage: 'Request' }] });
        }
      });

      // Plain post vs Create in the background: the same form, the same dates, one after the other (each cancelled
      // before the next, which gives the credit back): the stored money, status, payments, credit applied and the
      // email log are identical. The credit comes from a small overpay booking (one credit note email).
      await runCase('diff', W, shared, async (ctx) => {
        if (W < 500) return;
        const guest = { name: 'Steps diff ' + RUN, email: `steps.diff.${RUN}@example.invalid`, phone: '+265 999 000 160' };
        const srcC = mk(ctx, 60, 1, Object.assign({ paid: true, method: 'cash', amount: 'over', overBy: 300, stop4: true }, guest));
        await open(BASE + '/admin/create-booking.php');
        await js(`__t.fill(${JSON.stringify(srcC)})`);
        await js(`(async function () { document.getElementById('overCollectedCb').click(); await __t.settle(); await __t.next(4); return true; })()`);
        await waitFor('__t.reviewReady()', 60000, ctx.label + ': Review (credit source)');
        ctx.emails = 1; // the credit note email for the 300.00 extra (to the test recipient on the test platform)
        const srcId = await createOk(ctx, { keep: true });
        if (!srcId) return;
        const note = select('SELECT id, credit_note_number, original_amount - amount_used AS bal FROM credit_notes WHERE booking_id = ? ORDER BY id', [srcId])[0];
        check(!!note && near(note.bal, 300), `${ctx.label}: credit source ${note ? note.credit_note_number : '(none)'} with ${note ? f2(note.bal) : '-'}`);
        if (!note) return;
        const strip = (rows, refs, drop) => rows.map(r => {
          const o = {};
          for (const [k, v] of Object.entries(r)) {
            if (drop.has(k) || (typeof v === 'string' && /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/.test(v))) continue;
            o[k] = typeof v === 'string' ? refs.reduce((s, ref) => s.split(ref).join('REF'), v) : v;
          }
          return o;
        });
        const DROP = new Set(['id', 'booking_reference', 'primary_booking_id', 'individual_room_id', 'client_uuid', 'booking_id', 'applied_to_booking_id', 'payment_id', 'receipt_number', 'invoice_number', 'payment_reference']);
        const snapshot = (id, e0) => {
          const rows = group(id), ids = rows.map(r => Number(r.id)), refs = rows.map(r => r.booking_reference);
          const bk = select(`SELECT * FROM bookings WHERE id IN (${inList(ids)}) ORDER BY id`, ids);
          const pay = select(`SELECT * FROM payments WHERE booking_id IN (${inList(ids)}) ORDER BY id`, ids);
          const app = select(`SELECT * FROM credit_note_applications WHERE applied_to_booking_id IN (${inList(ids)}) ORDER BY id`, ids);
          const em = select('SELECT * FROM email_send_log WHERE id > ? ORDER BY id', [e0]);
          const emKeys = em.length ? Object.keys(em[0]).filter(k => /^(email_kind|status)$|recipient|to_email|email_to/i.test(k)) : [];
          return { bookings: strip(bk, refs, DROP), payments: strip(pay, refs, DROP), credit: strip(app, refs, DROP),
            emails: em.map(e => Object.fromEntries(emKeys.map(k => [k, typeof e[k] === 'string' ? refs.reduce((s, ref) => s.split(ref).join('REF'), e[k]) : e[k]]))) };
        };
        const subs = [
          { tag: 'paid in full', c: mk(ctx, 63, 2, Object.assign({ paid: true, method: 'cash' }, guest)) },
          { tag: 'part pay with credit', c: mk(ctx, 66, 3, Object.assign({ paid: true, method: 'bank_transfer', amount: '1000.00', credit: note.id }, guest)) },
        ];
        for (const s of subs) {
          const snaps = {}, posts = {}, flashShown = {};
          for (const mode of ['plain', 'wizard']) {
            await open(BASE + '/admin/create-booking.php');
            const rv = await toReview(ctx, s.c);
            checkReview(ctx, rv);
            const e0 = maxId('email_send_log');
            let r;
            if (mode === 'plain') {
              lastPost = null; postBlocked = '';
              await js(`window.__cbCreate = 1; HTMLFormElement.prototype.submit.call(document.getElementById('createBookingForm')); true`);
              r = await awaitCreate(ctx.label);
            } else {
              r = await create(ctx.label);
            }
            if (!r.id) { check(false, `${ctx.label}: ${s.tag} ${mode}: saved`, r.refused || ''); return; }
            const m = track(ctx, r.id);
            await sleep(800);
            flashShown[mode] = (await js(`document.body.innerText`)).indexOf('created successfully') !== -1;
            posts[mode] = lastPost || [];
            snaps[mode] = snapshot(r.id, e0);
            if (s.c.credit) check(snaps[mode].credit.length > 0, `${ctx.label}: ${s.tag} ${mode}: credit applied (${snaps[mode].credit.map(a => a.amount_applied).join(', ')})`);
            await ctx.cancelNow(m);
          }
          check(flashShown.plain === flashShown.wizard, `${ctx.label}: ${s.tag}: the booking page shows the same after both (success message ${flashShown.plain ? 'shown' : 'not shown'} both times)`);
          const pp = new Map(posts.plain), pw = new Map(posts.wizard);
          const extra = posts.wizard.filter(([k]) => !pp.has(k)).map(([k]) => k).sort();
          check(!pp.has('response') && !pp.has('quote_total') && JSON.stringify(extra) === JSON.stringify(['quote_total', 'response']) && pw.get('response') === 'wizard',
            `${ctx.label}: ${s.tag}: the plain post carries no response/quote_total; Create adds only those two`, JSON.stringify(extra));
          for (const part of ['bookings', 'payments', 'credit', 'emails']) {
            const a = JSON.stringify(snaps.plain[part]), b = JSON.stringify(snaps.wizard[part]);
            const diffs = [];
            (snaps.plain[part] || []).forEach((row, i) => { const o = (snaps.wizard[part] || [])[i] || {}; Object.keys(Object.assign({}, row, o)).forEach(k => { if (JSON.stringify(row[k]) !== JSON.stringify(o[k])) diffs.push(`${k}: ${JSON.stringify(row[k])} vs ${JSON.stringify(o[k])}`); }); });
            check(a === b, `${ctx.label}: ${s.tag}: ${part} identical, plain vs Create (${(snaps.plain[part] || []).length} row(s))`, diffs.slice(0, 12).join('; ') || `${a.slice(0, 200)} vs ${b.slice(0, 200)}`);
          }
          console.log(`   ${s.tag}: bookings ${JSON.stringify(snaps.plain.bookings.map(b => ({ total_with_vat: b.total_with_vat, amount_paid: b.amount_paid, status: b.status, payment_status: b.payment_status })))}; payments ${JSON.stringify(snaps.plain.payments.map(p => p.amount))}; credit ${JSON.stringify(snaps.plain.credit.map(a => a.amount_applied))}; emails ${JSON.stringify(snaps.plain.emails)}`);
        }
      });

      // ── Later items (2026-11-28) ──
      // The form as Create posts it (csrf_token / client_uuid masked; Create adds only response and quote_total).
      const formNow = () => js(`Array.from(new FormData(document.getElementById('createBookingForm')).entries()).filter(function (e) { return e[0] !== 'csrf_token' && e[0] !== 'client_uuid'; }).map(function (e) { return [e[0], typeof e[1] === 'string' ? e[1] : '(file)']; })`);
      const quoteNow = async () => { await sleep(1500); await waitFor('cbQ.fresh && !__t.busy()', 60000, 'price check'); await sleep(300); return js(`({ received: !!cbQ.quote.payment.received, final: cbQ.quote.payment.final_status, will: Number(cbQ.quote.credit && cbQ.quote.credit.will_apply) || 0, total: Number(cbQ.quote.totals.total_with_vat), owed: Number(cbQ.quote.still_owed), paid: document.getElementById('paymentReceivedCheck').checked, amount: document.getElementById('amountCollected').value, note: !document.getElementById('cbCreditCoversNote').hidden, warn4: document.getElementById('cbWarn4').hidden ? '' : document.getElementById('cbWarn4').textContent.trim() })`); };

      // Guest credit covering the total unticks "Payment received now" once, with a plain note; the form then posts the
      // same fields as a manual untick; staff can tick it again. The override ticked with no reason gets a step 4
      // warning from the price check. The credit comes from an overpay booking (one credit note email, to the test
      // recipient); the covered booking itself is never created.
      await runCase('cover', W, shared, async (ctx) => {
        if (W < 500) return;
        const guest = { name: 'Steps cover ' + RUN, email: `steps.cover.${RUN}@example.invalid`, phone: '+265 999 000 170' };
        const srcC = mk(ctx, 70, 1, Object.assign({ paid: true, method: 'cash', amount: 'over', overBy: 1000000, stop4: true }, guest));
        await open(BASE + '/admin/create-booking.php');
        await js(`__t.fill(${JSON.stringify(srcC)})`);
        await js(`(async function () { document.getElementById('overCollectedCb').click(); await __t.settle(); await __t.next(4); return true; })()`);
        await waitFor('__t.reviewReady()', 60000, ctx.label + ': Review (credit source)');
        ctx.emails = 1; // the credit note email for the 1,000,000.00 extra
        const srcId = await createOk(ctx);
        if (!srcId) return;
        const note = select('SELECT id, credit_note_number, original_amount - amount_used AS bal FROM credit_notes WHERE booking_id = ? ORDER BY id', [srcId])[0];
        check(!!note && near(note.bal, 1000000), `${ctx.label}: credit source ${note ? note.credit_note_number : '(none)'} with ${note ? f2(note.bal) : '-'}`);
        if (!note) return;
        const c = mk(ctx, 73, 1, Object.assign({ paid: true, method: 'cash', stop4: true }, guest));
        await open(BASE + '/admin/create-booking.php');
        await js(`__t.fill(${JSON.stringify(c)})`);
        const s0 = await quoteNow();
        check(s0.paid && near(s0.amount, s0.total) && !s0.note && s0.received, `${ctx.label}: before the credit: payment ticked, amount ${s0.amount} = total, no note`, JSON.stringify(s0));
        await js(`__t.pickCredit(${note.id})`);
        const s1 = await quoteNow();
        check(!s1.paid && s1.amount === '' && s1.note, `${ctx.label}: credit covers the total: "Payment received now" unticked, amount empty, note "Credit covers the total; no payment needed." shown`, JSON.stringify(s1));
        check(!s1.received && s1.final === 'paid' && near(s1.will, s1.total) && near(s1.owed, 0), `${ctx.label}: the price check: no payment, ${f2(s1.will)} credit applied, nothing owed, "paid"`, JSON.stringify(s1));
        const auto = await formNow();
        await js(`(async function () { __t.$('paymentReceivedCheck').click(); return true; })()`);
        const s2 = await quoteNow();
        check(s2.paid && !s2.note, `${ctx.label}: staff can tick it again (left ticked, the note hides)`, JSON.stringify(s2));
        await js(`(async function () { __t.$('paymentReceivedCheck').click(); return true; })()`);
        const s3 = await quoteNow();
        const manual = await formNow();
        const diffs = [...new Set([...auto, ...manual].map(([k]) => k))].filter(k => JSON.stringify(auto.filter(e => e[0] === k)) !== JSON.stringify(manual.filter(e => e[0] === k)));
        check(!s3.paid && diffs.length === 0 && JSON.stringify(auto) === JSON.stringify(manual), `${ctx.label}: the form posts the same ${auto.length} fields after the automatic untick as after a manual untick (csrf/uuid masked)`, JSON.stringify(diffs.map(k => [k, auto.filter(e => e[0] === k), manual.filter(e => e[0] === k)])));
        check(s3.received === s1.received && s3.final === s1.final && near(s3.will, s1.will) && near(s3.owed, s1.owed), `${ctx.label}: the price check answers the same for both`, JSON.stringify({ s1, s3 }));
        console.log(`   posted fields (auto untick): ${JSON.stringify(auto.filter(([k]) => /pay|amount|credit|status|email/.test(k)))}`);
        // Override ticked, the guest's own note picked through it, no reason: the price check warns on step 4.
        await js(`(async function () { __t.$('creditOverrideCheck').click(); await __t.sleep(200); __t.set(__t.$('creditOverrideEmail'), ${JSON.stringify(guest.email)}); return true; })()`);
        await js(`__t.pickCredit(${note.id})`);
        const s4 = await quoteNow();
        check(s4.warn4.indexOf('Credit from a different account is used only with a reason.') !== -1, `${ctx.label}: override without a reason: step 4 warns "${s4.warn4}"`, JSON.stringify(s4));
        const e4 = await js(`(async function () { await __t.next(4); return { step: cbNav.current, err: (__t.$('cbErr4').textContent || '').trim() }; })()`);
        check(e4.step === 4 && /Enter the reason/.test(e4.err), `${ctx.label}: Continue still stops on step 4 until a reason is entered`, JSON.stringify(e4));
        await js(`(async function () { __t.set(__t.$('creditOverrideReason'), 'Steps run reason'); return true; })()`);
        const s5 = await quoteNow();
        check(s5.warn4 === '', `${ctx.label}: with a reason the warning goes`, JSON.stringify(s5));
        check(forGuest(guest.email) === 1, `${ctx.label}: only the credit source booking stored (the covered booking was never created)`, String(forGuest(guest.email)));
      });

      // A refused plain save (the page reloads) brings back the status and the guest email tick as staff left them; a
      // fresh page keeps its defaults (Confirmed, guest email ticked).
      await runCase('repost', W, shared, async (ctx) => {
        await open(BASE + '/admin/create-booking.php');
        const d = await js(`({ st: (document.querySelector('input[name="booking_status"]:checked') || {}).value || '', mail: document.getElementById('sendEmail').checked })`);
        check(d.st === 'confirmed' && d.mail === true, `${ctx.label}: a fresh page starts on Confirmed with the guest email ticked`, JSON.stringify(d));
        const c = mk(ctx, 80, 1, { status: 'pending', paid: true, method: 'cash', amount: 'over', stop4: true });
        await js(`__t.fill(${JSON.stringify(c)})`);
        await js(`(async function () { document.getElementById('overCollectedCb').click(); await __t.settle(); await __t.next(4); return true; })()`);
        await waitFor('__t.reviewReady()', 60000, ctx.label + ': Review');
        const before = await js(`({ st: (document.querySelector('input[name="booking_status"]:checked') || {}).value || '', mail: document.getElementById('sendEmail').checked })`);
        fetchMode = 'no-overpay'; lastPost = null; postBlocked = '';
        await js(`window.__cbCreate = 1; HTMLFormElement.prototype.submit.call(document.getElementById('createBookingForm')); true`);
        const r = await awaitCreate(ctx.label);
        fetchMode = null;
        if (r.id) track(ctx, r.id);
        check(!r.id && !!lastPost && !lastPost.some(([k]) => k === 'confirm_overpay') && /more than/i.test(String(r.refused)), `${ctx.label}: the plain post without the tick was refused, the page reloaded ("${String(r.refused || '').slice(0, 80)}")`, r.id ? 'booking ' + r.id : String(r.refused));
        if (r.id) return;
        await sleep(800);
        const after = await js(`({ st: (document.querySelector('input[name="booking_status"]:checked') || {}).value || '', mail: document.getElementById('sendEmail').checked, name: document.getElementById('guestName').value, repost: typeof cbRepost !== 'undefined' && !!cbRepost })`);
        check(before.st === 'pending' && !before.mail && after.repost && after.st === 'pending' && after.mail === false && after.name === c.name,
          `${ctx.label}: after the refusal: status Pending and the guest email unticked, as staff left them`, JSON.stringify({ before, after }));
        check(forGuest(c.email) === 0, `${ctx.label}: nothing stored for the guest`, String(forGuest(c.email)));
      });

      // Tentative with the guest email and the quotation ticked: the quotation email with its PDF goes to the test
      // recipient (the test platform redirect), the booking records it, then it is cancelled.
      await runCase('tquote', W, shared, async (ctx) => {
        if (W < 500) return;
        const c = mk(ctx, 84, 2, { status: 'tentative', validDays: 7, quoteNote: 'Steps run quotation email', sendEmail: true });
        await open(BASE + '/admin/create-booking.php');
        const rv = await toReview(ctx, c);
        checkReview(ctx, rv);
        const s = await js(`({ q: document.getElementById('sendQuotationCheck').checked, mail: document.getElementById('sendEmail').checked })`);
        check(s.q && s.mail, `${ctx.label}: guest email and quotation ticked`, JSON.stringify(s));
        ctx.emails = 'some';
        const e0 = maxId('email_send_log');
        const id = await createOk(ctx);
        if (!id) return;
        const p = new Map(lastPost || []);
        check(p.get('send_quotation') === '1' && p.has('send_email') && p.get('booking_status') === 'tentative', `${ctx.label}: Create posted send_quotation=1 with the guest email ticked`);
        verify(ctx, id, rv, c, { paid: 0 });
        await sleep(800);
        const page = await js(`document.body.innerText.replace(/\\s+/g, ' ')`);
        check(page.indexOf('created successfully') !== -1 && page.indexOf('Quotation PDF sent.') !== -1, `${ctx.label}: the booking page says the booking was created and "Quotation PDF sent."`, page.slice(0, 300));
        const em = select("SELECT e.email_kind, e.status, (e.to_email = (SELECT s.setting_value FROM site_settings s WHERE s.setting_key = 'automated_email_test_recipient')) AS to_test FROM email_send_log e WHERE e.id > ? ORDER BY e.id", [e0]);
        console.log('   email log: ' + em.map(g => `${g.email_kind}/${g.status}${Number(g.to_test) === 1 ? ' (test recipient)' : ' (OTHER recipient)'}`).join(', '));
        check(em.some(g => g.email_kind === 'tentative_quotation' && g.status === 'sent'), `${ctx.label}: email_send_log has the tentative_quotation email as sent`);
        check(em.filter(isSend).length > 0 && em.filter(isSend).every(g => Number(g.to_test) === 1), `${ctx.label}: every email sent went to the test recipient`);
        const b = select('SELECT last_quotation_sent_at FROM bookings WHERE id = ?', [id])[0];
        check(!!(b && b.last_quotation_sent_at), `${ctx.label}: bookings.last_quotation_sent_at recorded`, JSON.stringify(b));
        console.log(`   tquote booking id ${id}`);
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
