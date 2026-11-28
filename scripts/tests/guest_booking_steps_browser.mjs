// Guest booking page, the five steps (G-A): headless browser run on the Liwonde TEST hotel only. Read-only.
//
//   node scripts/tests/guest_booking_steps_browser.mjs                 1280 and 390, reduced motion
//   node scripts/tests/guest_booking_steps_browser.mjs --width=390     one width
//
// Checks per width: the landing step of a query prefill (none, dates, dates + party, dates + party + room), one step on
// screen in order with aria-current on the step bar, Continue refused with a message next to the field and the focus
// on it (empty dates, too many children, empty name), Back keeps the values, the step bar goes back freely and forward
// only to steps reached, Review shows the server's quote (= the price check reply total) with Book enabled only then,
// a date change on Review makes the quote stale (Book disabled) and it refreshes to the new reply, a server refusal
// (a past check-in, which the page's own checks do not catch) opens step 1 with its message, no horizontal scroll at
// 390, no console errors.
// Book (G-C), answered in the browser with canned replies: three presses send one request (Book off meanwhile), a
// non-JSON reply and a network failure show "We could not confirm your booking..." with Book back, a retry keeps the
// booking id, a step-3 refusal opens step 3 with its message and the typed text kept (its csrf_token replaces the used
// one), a price-changed refusal shows the new total with Book on, success opens the redirect (a canned page too).
//
// Safety: refuses unless this checkout is Liwonde, its database is not in .claude/LIVE_TARGETS, the site name says
// Liwonde and the site is the test platform. Database reads are SELECT / availability reads only. Own php -S
// (dev-router.php) and own headless Chrome (temp profile) on their own ports, refused if either port already answers;
// both stopped at the end. No booking is ever saved: Book's background POST (response=wizard, no dry_run) is answered
// inside the browser (CDP Fetch) and never reaches the server, any other form POST to booking.php is failed in the
// browser, the confirmation page Book opens is a canned page too, and the only POSTs let through are the page's own
// price checks (dry_run=1, read-only). The service worker is bypassed. Before the
// run the public price check rate limit of 127.0.0.1 / ::1 is cleared (cache entry + temp file), as the total guard does.
// The page loads log site_visitors / session_logs rows (HeadlessChrome) like any visit.
// Env: GBSB_HTTP_PORT (8795), GBSB_CDP_PORT (9395).
import { spawn, spawnSync } from 'node:child_process';
import { createConnection } from 'node:net';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const ARGS = Object.fromEntries(process.argv.slice(2).map(a => { const m = a.match(/^--([^=]+)(?:=(.*))?$/); return m ? [m[1], m[2] ?? '1'] : [a, '1']; }));
if (!/liwonde/i.test(ROOT)) { console.log('REFUSED: not the Liwonde checkout'); process.exit(2); }
const HTTP_PORT = Number(process.env.GBSB_HTTP_PORT || 8795);
const CDP_PORT = Number(process.env.GBSB_CDP_PORT || 9395);
const BASE = `http://127.0.0.1:${HTTP_PORT}`;
const WIDTHS = ARGS.width ? [Number(ARGS.width)] : [1280, 390];
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
let pass = 0, fail = 0;
function check(ok, label, detail = '') { ok ? pass++ : fail++; console.log(`[${ok ? 'PASS' : 'FAIL'}] ${label}${!ok && detail ? ': ' + detail : ''}`); return ok; }

// ── Safety guard + read-only helper (php) ──────────────────────────────────────
const PHP_GUARD = `
$root = getcwd();
if (stripos($root, 'Liwonde') === false) { echo json_encode(['refuse' => 'not the Liwonde checkout']); exit; }
require $root . '/config/database.php';
$db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$live = array_filter(array_map('trim', @file($root . '/.claude/LIVE_TARGETS') ?: []), function ($l) { return $l !== '' && $l[0] !== '#'; });
if ($db === '' || in_array($db, $live, true)) { echo json_encode(['refuse' => 'database is a LIVE target']); exit; }
if (stripos((string)getSetting('site_name', ''), 'liwonde') === false) { echo json_encode(['refuse' => 'site name is not Liwonde']); exit; }
if (trim((string)getSetting('site_is_test_platform', '0')) !== '1') { echo json_encode(['refuse' => 'not the test platform']); exit; }
`;
const scratch = mkdtempSync(join(tmpdir(), 'gbsb-php-'));
function php(body) {
  const f = join(scratch, 'q.php');
  writeFileSync(f, '<?php\n' + PHP_GUARD + body);
  const r = spawnSync('php', [f], { cwd: ROOT, encoding: 'utf8', timeout: 120000, input: '' });
  const out = (r.stdout || '').trim().split('\n').pop() || '';
  try { return JSON.parse(out); } catch { return { refuse: 'php said: ' + (out || r.stderr || '').slice(0, 300) }; }
}
// A plain room type (not a joined one) taking 2 guests, free for 3 nights from about 75 days out; yesterday for the refusal.
const data = php(`
$rooms = $pdo->query("SELECT r.id, r.name, r.max_guests, (SELECT COUNT(*) FROM room_combinations rc WHERE rc.combined_room_type_id = r.id AND rc.is_active = 1) AS n_combos
    FROM rooms r WHERE r.is_active = 1 AND r.single_occupancy_enabled = 1 AND r.max_guests >= 2 ORDER BY r.id")->fetchAll(PDO::FETCH_ASSOC);
$found = null;
foreach ($rooms as $r) {
    if ((int)$r['n_combos'] > 0) { continue; }
    $d = (new DateTime('today'))->modify('+75 day');
    for ($i = 0; $i < 45 && !$found; $i++, $d->modify('+1 day')) {
        $ci = $d->format('Y-m-d');
        $a = checkRoomAvailability((int)$r['id'], $ci, (clone $d)->modify('+3 day')->format('Y-m-d'));
        if (!empty($a['available'])) {
            $found = ['room_id' => (int)$r['id'], 'room' => $r['name'], 'ci' => $ci, 'co' => (clone $d)->modify('+2 day')->format('Y-m-d'),
                'co2' => (clone $d)->modify('+3 day')->format('Y-m-d')];
        }
    }
    if ($found) { break; }
}
if (!$found) { echo json_encode(['refuse' => 'no free room type / dates found']); exit; }
$found['yesterday'] = (new DateTime('yesterday'))->format('Y-m-d');
echo json_encode(['ok' => true] + $found);`);
if (!data.ok) { console.log('REFUSED: ' + data.refuse); try { rmSync(scratch, { recursive: true, force: true }); } catch {} process.exit(2); }
console.log(`safety: Liwonde checkout, database not LIVE, site is the test platform. Room ${data.room} (#${data.room_id}), ${data.ci} to ${data.co} / ${data.co2}`);
const cleared = php(`
foreach (['booking_form', 'booking_quote'] as $action) {
    foreach (['127.0.0.1', '::1'] as $ip) {
        $key = 'iprl_' . $action . '_' . substr(hash('sha256', $ip . '|' . $action), 0, 24);
        if (function_exists('deleteCache')) { @deleteCache($key); }
        $f = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $key . '.json';
        if (is_file($f)) { @unlink($f); }
    }
}
echo json_encode(['ok' => true]);`);
if (!cleared.ok) console.log('note: the rate limit was not cleared (' + (cleared.refuse || '?') + ')');

// ── Servers: ours only ──────────────────────────────────────────────────────────
const portAnswers = (port) => new Promise(r => {
  const s = createConnection({ host: '127.0.0.1', port });
  s.once('connect', () => { s.destroy(); r(true); });
  s.once('error', () => r(false));
  s.setTimeout(1000, () => { s.destroy(); r(false); });
});
for (const p of [HTTP_PORT, CDP_PORT]) {
  if (await portAnswers(p)) { console.log(`REFUSED: port ${p} is already in use by another program`); try { rmSync(scratch, { recursive: true, force: true }); } catch {} process.exit(2); }
}
const phpErrors = [];
const server = spawn('php', ['-S', `127.0.0.1:${HTTP_PORT}`, '-t', '.', 'scripts/tests/dev-router.php'], { cwd: ROOT, stdio: ['ignore', 'ignore', 'pipe'] });
server.stderr.on('data', (d) => String(d).split('\n').forEach(l => { if (/PHP (Fatal|Parse|Warning)|Uncaught/.test(l)) phpErrors.push(l.trim()); }));
const profile = mkdtempSync(join(tmpdir(), 'gbsb-chrome-'));
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
const crashed = async (e) => { console.log('test crashed: ' + String(e && e.message || e)); await stopAll(); process.exit(2); };
process.on('uncaughtException', crashed);
process.on('unhandledRejection', crashed);

let up = false;
for (let i = 0; i < 60 && !up; i++) { await sleep(250); try { await fetch(BASE + '/booking.php', { redirect: 'manual' }); up = true; } catch {} }
let targets = [];
for (let i = 0; i < 60; i++) { try { targets = await (await fetch(`http://127.0.0.1:${CDP_PORT}/json`)).json(); if (targets.find(t => t.type === 'page')) break; } catch {} await sleep(250); }
const pageTarget = targets.find(t => t.type === 'page');
if (!up || !pageTarget || server.exitCode !== null || chrome.exitCode !== null) {
  console.log('could not start ' + (!up || server.exitCode !== null ? 'php -S' : 'Chrome') + ' on its own port'); await stopAll(); process.exit(2);
}

// ── CDP ─────────────────────────────────────────────────────────────────────────
ws = new WebSocket(pageTarget.webSocketDebuggerUrl);
await new Promise(r => ws.addEventListener('open', r));
let msgId = 0; const pending = new Map(); let consoleErrors = []; let postsBlocked = 0; let dryReplies = []; let htmlNext = false; let navLog = [];
function send(method, params = {}) {
  const i = ++msgId; ws.send(JSON.stringify({ id: i, method, params }));
  return new Promise(r => { pending.set(i, r); setTimeout(() => { if (pending.has(i)) { pending.delete(i); r({ error: { message: 'no reply to ' + method } }); } }, 15000); });
}
const isDryBody = (body) => /name="dry_run"\r?\n\r?\n1\r?\n/.test(body) && /name="response"\r?\n\r?\nwizard\r?\n/.test(body);
// Book (G-C): the background save. Answered here per scenario (bookMode), never continued to the server.
const isBookBody = (body) => /name="response"\r?\n\r?\nwizard\r?\n/.test(body) && !/name="dry_run"\r?\n/.test(body);
const formVal = (body, k) => { const m = body.match(new RegExp('name="' + k + '"\\r?\\n\\r?\\n([^\\r\\n]*)\\r?\\n')); return m ? m[1] : null; };
const b64 = (s) => Buffer.from(s, 'utf8').toString('base64');
const fulfil = (requestId, type, text) => send('Fetch.fulfillRequest', { requestId, responseCode: 200,
  responseHeaders: [{ name: 'Content-Type', value: type + '; charset=utf-8' }], body: b64(text) });
let bookMode = null, bookBodies = [], heldBook = null, confirmGets = [], netFailAllowed = false;
ws.addEventListener('message', async (ev) => {
  const m = JSON.parse(ev.data);
  if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); return; }
  if (m.method === 'Runtime.exceptionThrown') consoleErrors.push('exception: ' + (m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text || '').split('\n')[0]);
  if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') consoleErrors.push('console.error: ' + (m.params.args || []).map(a => a.value ?? a.description ?? '').join(' ').slice(0, 200));
  if (m.method === 'Log.entryAdded' && m.params.entry.level === 'error' && netFailAllowed && m.params.entry.source === 'network' && /\/booking\.php/.test(m.params.entry.url || '')) return; // the network failure we cause
  if (m.method === 'Log.entryAdded' && m.params.entry.level === 'error') consoleErrors.push('log: ' + String(m.params.entry.text).slice(0, 200) + (m.params.entry.url ? ' (' + m.params.entry.url + ')' : ''));
  if (m.method === 'Page.javascriptDialogOpening') { consoleErrors.push('dialog: ' + m.params.message); send('Page.handleJavaScriptDialog', { accept: true }); }
  if (m.method === 'Page.frameNavigated' && !m.params.frame.parentId) navLog.push((Date.now() % 100000) + ' ' + m.params.frame.url.replace(BASE, ''));
  if (m.method !== 'Fetch.requestPaused') return;
  const p = m.params;
  if (p.responseStatusCode === undefined && /\/booking-confirmation\.php(\?|$)/.test(p.request.url)) { // the page Book opens: canned
    confirmGets.push(p.request.url);
    fulfil(p.requestId, 'text/html', '<!doctype html><html lang="en"><head><title>Zz canned confirmation</title><link rel="icon" href="data:,"></head><body><p>Zz canned confirmation</p></body></html>');
    return;
  }
  const isPost = p.request.method === 'POST' && /\/booking\.php(\?|$)/.test(p.request.url);
  const body = String(p.request.postData || '');
  if (p.responseStatusCode !== undefined) { // response stage: keep each price check reply
    if (isPost && isDryBody(body)) {
      const b = await send('Fetch.getResponseBody', { requestId: p.requestId });
      try {
        const text = b.result.base64Encoded ? Buffer.from(b.result.body, 'base64').toString('utf8') : b.result.body;
        const j = JSON.parse(text);
        const upto = (body.match(/name="dry_run_upto"\r?\n\r?\n(\d)\r?\n/) || [])[1] || '?';
        dryReplies.push({ upto: Number(upto), ok: !!j.ok, step: j.step, field: j.field, message: j.message || '', csrf: typeof j.csrf_token === 'string' ? j.csrf_token : null, total: j.quote && j.quote.totals ? Number(j.quote.totals.total_with_vat) : null });
      } catch (e) { dryReplies.push({ upto: null, ok: false, broken: true }); }
    }
    send('Fetch.continueRequest', { requestId: p.requestId });
    return;
  }
  if (isPost && p.resourceType !== 'Document' && isBookBody(body)) { // Book: answered here, never sent on
    bookBodies.push({ uuid: formVal(body, 'client_uuid') || '', quote: formVal(body, 'quote_total'), token: formVal(body, 'csrf_token') || '' });
    const mode = bookMode;
    if (mode === 'hold') { heldBook = p.requestId; return; }
    if (mode === 'fail') { send('Fetch.failRequest', { requestId: p.requestId, errorReason: 'InternetDisconnected' }); return; }
    if (mode && typeof mode === 'object') { fulfil(p.requestId, mode.html !== undefined ? 'text/html' : 'application/json', mode.html !== undefined ? mode.html : JSON.stringify(mode.json)); return; }
    postsBlocked++; // no scenario set: fail it like any other form POST
    send('Fetch.failRequest', { requestId: p.requestId, errorReason: 'Aborted' });
    return;
  }
  if (isPost && (p.resourceType === 'Document' || !isDryBody(body))) { // never submit
    postsBlocked++;
    send('Fetch.failRequest', { requestId: p.requestId, errorReason: 'Aborted' });
    return;
  }
  if (isPost && htmlNext) { // one price check answered with a broken (HTML) page, in the browser only
    htmlNext = false;
    dryReplies.push({ html: true });
    send('Fetch.fulfillRequest', { requestId: p.requestId, responseCode: 200, responseHeaders: [{ name: 'Content-Type', value: 'text/html; charset=utf-8' }],
      body: Buffer.from('<!doctype html><html><body>Error</body></html>', 'utf8').toString('base64') });
    return;
  }
  send('Fetch.continueRequest', { requestId: p.requestId });
});
async function js(expr) {
  const r = await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true, timeout: 10000 });
  if (r.error) throw new Error(r.error.message);
  if (r.result?.exceptionDetails) throw new Error((r.result.exceptionDetails.exception?.description || r.result.exceptionDetails.text || 'js error').split('\n')[0]);
  return r.result?.result?.value;
}
async function waitFor(expr, ms = 30000) {
  const t0 = Date.now();
  while (Date.now() - t0 < ms) { try { if (await js(expr)) return true; } catch {} await sleep(150); }
  return false;
}
await send('Runtime.enable'); await send('Page.enable'); await send('Log.enable');
await send('Network.enable'); await send('Network.setCacheDisabled', { cacheDisabled: true });
await send('Network.setBypassServiceWorker', { bypass: true });
await send('Fetch.enable', { patterns: [{ urlPattern: '*booking.php*', requestStage: 'Request' }, { urlPattern: '*booking.php*', requestStage: 'Response' },
  { urlPattern: '*booking-confirmation.php*', requestStage: 'Request' }] });
await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });

// ── Page helpers ────────────────────────────────────────────────────────────────
async function open(query = '') {
  await js('window.__gbNav = 1').catch(() => {});
  await send('Page.navigate', { url: `${BASE}/booking.php${query ? '?' + query : ''}` });
  const ok = await waitFor('!window.__gbNav && document.readyState === "complete" && typeof gbReady !== "undefined" && gbReady && typeof gbCurrent !== "undefined" && gbCurrent > 0', 40000);
  if (!ok) throw new Error('the booking page did not load');
}
const STATE = `(() => {
  const visible = Array.from(document.querySelectorAll('#bookingForm .gb-step')).filter(s => s.offsetParent !== null || getComputedStyle(s).display !== 'none').map(s => Number(s.dataset.step));
  const cur = document.querySelector('#gbStepBar [aria-current="step"]');
  const a = document.activeElement;
  const errs = Array.from(document.querySelectorAll('.gb-step__error:not([hidden]), .gb-field-error')).map(n => ({ id: n.id, text: n.textContent.trim(), step: Number((n.closest('.gb-step') || { dataset: {} }).dataset.step || 0) }));
  const btn = document.querySelector('.btn-submit');
  return { step: gbCurrent, reached: gbReached, visible, bar: cur ? Number(cur.getAttribute('data-gb-go')) : null,
    active: a ? (a.id || a.name || a.className || a.tagName) : null, activeStep: a && a.closest('.gb-step') ? Number(a.closest('.gb-step').dataset.step) : null,
    errs, quote: document.getElementById('bookingSummary').getAttribute('data-quote'),
    total: (typeof gbQuote !== 'undefined' && gbQuote && gbQuote.totals) ? Number(gbQuote.totals.total_with_vat) : null,
    shown: document.getElementById('summaryTotal').textContent.replace(/\\s+/g, ' ').trim(), book: !!btn && !btn.disabled,
    ci: document.getElementById('check_in_date').value, co: document.getElementById('check_out_date').value,
    guests: document.getElementById('number_of_guests').value, kids: document.getElementById('child_guests').value,
    scroll: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth), inner: window.innerWidth,
    reduce: matchMedia('(prefers-reduced-motion: reduce)').matches };
})()`;
const state = () => js(STATE);
const formToken = () => js(`(document.querySelector('#bookingForm [name="csrf_token"]') || {}).value || null`);
const money = (s) => { const m = String(s || '').replace(/,/g, '').match(/-?\d+(\.\d+)?/); return m ? Number(m[0]) : null; };
const click = (sel) => js(`(() => { const b = document.querySelector(${JSON.stringify(sel)}); if (!b) throw new Error('missing ' + ${JSON.stringify(sel)}); b.click(); return 1; })()`);
const setField = (id, v) => js(`(() => { const el = document.getElementById(${JSON.stringify(id)}); el.value = ${JSON.stringify(String(v))};
  el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); return 1; })()`);
const onStep = (n) => `gbCurrent === ${n} && !gbBusy`;
const fresh = `document.getElementById('bookingSummary').getAttribute('data-quote') === 'fresh'`;
let maxScroll = { w: 0, where: '' };
async function noScroll(where) { const s = await state(); if (s.scroll > s.inner + 1 && s.scroll > maxScroll.w) maxScroll = { w: s.scroll, inner: s.inner, where }; return s; }
const one = (s, n) => s.visible.length === 1 && s.visible[0] === n && s.bar === n && s.step === n;

// ── Run ─────────────────────────────────────────────────────────────────────────
const watchdog = setTimeout(async () => { console.log('run timed out (GBSB_RUN_MS)'); await stopAll(); process.exit(1); }, Number(process.env.GBSB_RUN_MS || 600000));
const R = data;
for (const W of WIDTHS) {
  console.log(`\n== width ${W} (reduced motion)`);
  await send('Emulation.setDeviceMetricsOverride', W < 600 ? { width: W, height: 844, deviceScaleFactor: 2, mobile: true } : { width: W, height: 900, deviceScaleFactor: 1, mobile: false });
  consoleErrors = []; dryReplies = []; maxScroll = { w: 0, where: '' };
  try {
    // Prefill: the landing step is the first incomplete one (never past Room)
    await open(`check_in=${R.ci}&check_out=${R.co}`);
    let s = await state();
    check(s.step === 2 && one(s, 2), `${W}: dates in the query land on step 2 (Who)`, JSON.stringify({ step: s.step, visible: s.visible, bar: s.bar }));
    await open(`check_in=${R.ci}&check_out=${R.co}&guests=2&children=0`);
    s = await state();
    check(s.step === 3 && one(s, 3), `${W}: dates + party land on step 3 (Room)`, JSON.stringify({ step: s.step, visible: s.visible }));
    await open(`room_id=${R.room_id}&check_in=${R.ci}&check_out=${R.co}&guests=2&children=0`);
    s = await state();
    check(s.step === 3 && one(s, 3), `${W}: room + dates + party land on step 3 (Room)`, JSON.stringify({ step: s.step, visible: s.visible }));

    // No query: step 1, one step on screen
    await open('');
    s = await noScroll('step 1');
    check(s.reduce, `${W}: prefers-reduced-motion is on`);
    check(one(s, 1) && s.reached === 1, `${W}: no query opens step 1 only, aria-current on step 1`, JSON.stringify({ step: s.step, visible: s.visible, bar: s.bar }));
    check(await js(`document.querySelectorAll('#gbStepBar [aria-disabled="true"]').length === 4 && document.getElementById('gbStepBar').hidden === false`), `${W}: the step bar shows; steps 2-5 not reached yet`);
    // Forward through the bar to a step not reached: nothing happens
    await click('#gbStepBar [data-gb-go="3"]'); await sleep(300);
    s = await state();
    check(s.step === 1, `${W}: the step bar does not jump to a step not reached`);
    // Continue with no dates: refused, message next to check-in, focus there
    await click('#gbStep1 [data-gb-next]');
    await waitFor(`!gbBusy`, 5000); s = await state();
    const e1 = s.errs.find(e => e.id === 'gbErr_check_in_date');
    check(s.step === 1 && !!e1 && e1.text.length > 0 && s.active === 'check_in_date', `${W}: Continue without dates stays on step 1, message next to check-in, focus on it`, JSON.stringify({ step: s.step, errs: s.errs, active: s.active }));
    check(await js(`document.getElementById('check_in_date').getAttribute('aria-invalid') === 'true' && (document.getElementById('check_in_date').getAttribute('aria-describedby') || '').includes('gbErr_check_in_date')`), `${W}: check-in marked aria-invalid, described by its message`);
    // Dates through the calendars, Continue
    await js(`(checkInCalendar.setDate(${JSON.stringify(R.ci)}, true), checkOutCalendar.setDate(${JSON.stringify(R.co)}, true), 1)`);
    await click('#gbStep1 [data-gb-next]');
    check(await waitFor(onStep(2), 8000), `${W}: Continue on step 1 opens step 2`);
    s = await noScroll('step 2');
    check(one(s, 2) && s.errs.length === 0 && s.activeStep === 2, `${W}: step 2 alone on screen, messages cleared, focus moved into it`, JSON.stringify({ visible: s.visible, errs: s.errs, active: s.active, activeStep: s.activeStep }));
    // Too many children: the page lowers the number on change; typed and Continue pressed before any change event, the
    // step check refuses it on step 2 with the focus on children
    await setField('number_of_guests', 2);
    await setField('child_guests', 2);
    check(await js(`document.getElementById('child_guests').value`) === '1', `${W}: the page lowers children = guests to guests - 1 on change`);
    await js(`(document.getElementById('child_guests').value = '2', 1)`);
    await click('#gbStep2 [data-gb-next]');
    await waitFor(`!gbBusy`, 5000); s = await state();
    check(s.step === 2 && s.errs.some(e => e.id === 'gbErr_child_guests' && e.text) && s.active === 'child_guests', `${W}: children = guests is refused on step 2, focus on children`, JSON.stringify({ step: s.step, errs: s.errs, active: s.active }));
    await setField('child_guests', 0);
    await click('#gbStep2 [data-gb-next]');
    check(await waitFor(onStep(3), 8000), `${W}: Continue on step 2 opens step 3`);
    s = await noScroll('step 3');
    // Back keeps the values; the step bar goes forward again to a step reached (checks run on the way)
    await click('#gbStep3 [data-gb-back]'); await waitFor(onStep(2), 3000);
    s = await state();
    check(s.step === 2 && s.guests === '2' && s.kids === '0', `${W}: Back from step 3 opens step 2 with the party kept`, JSON.stringify({ step: s.step, guests: s.guests, kids: s.kids }));
    await click('#gbStep2 [data-gb-back]'); await waitFor(onStep(1), 3000);
    s = await state();
    check(s.step === 1 && s.ci === R.ci && s.co === R.co, `${W}: Back again opens step 1 with the dates kept`, JSON.stringify({ step: s.step, ci: s.ci, co: s.co }));
    await click('#gbStepBar [data-gb-go="3"]');
    check(await waitFor(onStep(3), 8000), `${W}: the step bar goes forward to step 3 (reached), checking steps 1-2 on the way`);
    // Step 3: no room yet, refused; then choose the room
    await waitFor(`!availabilityCheckPending`, 20000);
    await click('#gbStep3 [data-gb-next]');
    await waitFor(`!gbBusy`, 25000); s = await state();
    check(s.step === 3 && s.errs.some(e => e.id === 'gbErr_room_id' && e.text), `${W}: Continue without a room stays on step 3 with a message`, JSON.stringify({ step: s.step, errs: s.errs }));
    await js(`(() => { const r = document.querySelector('#roomSectionWrapper input[name="room_id"][value="${R.room_id}"]'); if (!r) throw new Error('room card missing'); selectRoom(r.closest('.room-option')); return 1; })()`);
    await waitFor(`!!selectedRoomId && !availabilityCheckPending`, 20000);
    const before3 = dryReplies.length;
    await click('#gbStep3 [data-gb-next]');
    check(await waitFor(onStep(4), 45000), `${W}: Continue on step 3 (price check) opens step 4`, JSON.stringify((await state()).errs));
    const d3 = dryReplies.slice(before3);
    check(d3.length === 1 && d3[0].upto === 3 && d3[0].ok, `${W}: step 3 Continue asked one price check (upto 3), accepted`, JSON.stringify(d3));
    s = await noScroll('step 4');
    // Step 4: empty name refused
    await click('#gbStep4 [data-gb-next]');
    await waitFor(`!gbBusy`, 5000); s = await state();
    check(s.step === 4 && s.errs.some(e => e.id === 'gbErr_guest_name' && e.text) && s.active === 'guest_name', `${W}: Continue with no name stays on step 4, focus on the name`, JSON.stringify({ step: s.step, errs: s.errs, active: s.active }));
    await setField('guest_name', 'Zz Steps Browser'); await setField('guest_email', 'not-an-email');
    await setField('guest_phone', '+265999000222');
    await click('#gbStep4 [data-gb-next]');
    await waitFor(`!gbBusy`, 5000); s = await state();
    check(s.step === 4 && s.errs.some(e => e.id === 'gbErr_guest_email') && s.active === 'guest_email', `${W}: a bad email is refused on step 4, focus on it`, JSON.stringify({ step: s.step, errs: s.errs, active: s.active }));
    await setField('guest_email', 'zz.steps.browser@example.invalid');
    const before5 = dryReplies.length;
    await click('#gbStep4 [data-gb-next]');
    check(await waitFor(onStep(5), 8000), `${W}: Continue on step 4 opens Review`);
    check(await waitFor(fresh, 45000), `${W}: Review gets a fresh price check`);
    s = await noScroll('step 5');
    const d5 = dryReplies.slice(before5);
    const last5 = d5[d5.length - 1] || {};
    check(d5.length === 1 && last5.upto === 5 && last5.ok, `${W}: Review asked one price check (upto 5), accepted`, JSON.stringify(d5));
    check(last5.total > 0 && s.total === last5.total && money(s.shown) === last5.total, `${W}: Review total = the price check reply total`, JSON.stringify({ shown: s.shown, quote: s.total, reply: last5.total }));
    check(s.book && one(s, 5), `${W}: Book enabled on Review with the fresh quote`);
    const tok5 = await formToken();
    check(!!last5.csrf && tok5 === last5.csrf, `${W}: the form carries the csrf_token of the last price check reply`, JSON.stringify({ reply: !!last5.csrf, same: tok5 === last5.csrf }));
    // A date change on Review: stale at once (Book off), then refreshed to the new reply
    const beforeD = dryReplies.length;
    const now = await js(`(() => { checkOutCalendar.setDate(${JSON.stringify(R.co2)}, true); const b = document.querySelector('.btn-submit');
      return { quote: document.getElementById('bookingSummary').getAttribute('data-quote'), book: !b.disabled, shown: document.getElementById('summaryTotal').textContent.trim() }; })()`);
    check((now.quote === 'stale' || now.quote === 'pending') && !now.book && money(now.shown) !== last5.total, `${W}: a date change on Review makes the quote stale, Book off, old total gone`, JSON.stringify(now));
    check(await waitFor(fresh, 45000), `${W}: Review refreshes the quote by itself`);
    s = await state();
    const dD = dryReplies.slice(beforeD); const lastD = dD[dD.length - 1] || {};
    check(dD.length === 1 && lastD.ok && lastD.total > last5.total && money(s.shown) === lastD.total && s.total === lastD.total && s.book,
      `${W}: the refreshed total (3 nights) = the new reply, Book on again`, JSON.stringify({ replies: dD, shown: s.shown }));
    const tokD = await formToken();
    check(!!lastD.csrf && tokD === lastD.csrf, `${W}: after the refresh the form carries the new reply's csrf_token`, JSON.stringify({ reply: !!lastD.csrf, same: tokD === lastD.csrf }));
    // Back from Review keeps everything; Review again needs no new check (same form)
    await click('#gbStep5 [data-gb-back]'); await waitFor(onStep(4), 3000);
    s = await state();
    check(s.step === 4 && await js(`document.getElementById('guest_name').value === 'Zz Steps Browser'`), `${W}: Back from Review keeps the details`);
    const beforeR = dryReplies.length;
    await click('#gbStepBar [data-gb-go="5"]');
    await waitFor(onStep(5), 8000); await waitFor(fresh, 20000); await sleep(400);
    check(dryReplies.length === beforeR && (await state()).book, `${W}: Review again with nothing changed reuses the fresh quote`, `${dryReplies.length - beforeR} new check(s)`);
    // A broken reply (not JSON): a plain "refresh the page" message on Review, Book off; the guest's edit asks again
    htmlNext = true;
    await setField('special_requests', 'Zz steps browser');
    const broken = await waitFor(`/refresh/i.test((document.querySelector('#gbStep5 .gb-step__error:not([hidden])') || {}).textContent || '')`, 20000);
    s = await state();
    check(broken && s.step === 5 && !s.book && s.quote !== 'fresh', `${W}: a non-JSON price check reply shows "refresh the page" on Review, Book off`, JSON.stringify({ step: s.step, errs: s.errs, quote: s.quote }));
    await setField('special_requests', '');
    check(await waitFor(fresh, 45000) && (await state()).book, `${W}: the next edit asks again and Review is fresh, Book on`);
    check(await js(`!document.querySelector('#gbStep5 .gb-step__error:not([hidden])')`), `${W}: the accepted check clears the Review message`);

    // ── Book (G-C): canned replies inside the browser; nothing reaches the server ──
    const bookState = () => js(`(() => { const e = document.querySelector('#gbStep5 .gb-step__error:not([hidden])'); const b = document.querySelector('.btn-submit');
      return { step: gbCurrent, book: !!b && !b.disabled, submitting: gbSubmitting, msg: e ? e.textContent.trim() : '' }; })()`);
    const UNCLEAR = /could not confirm your booking\. It may have been saved: check your email or contact us before booking again/i;
    const uuid0 = await js(`(document.querySelector('#bookingForm [name="client_uuid"]') || {}).value || ''`);
    const t0 = (await state()).total;
    bookBodies = [];
    // Three presses (two clicks + a form submit): one request, Book off while it runs; then a non-JSON reply
    bookMode = 'hold'; heldBook = null;
    await js(`(() => { const b = document.querySelector('.btn-submit'); b.click(); b.click(); document.getElementById('bookingForm').requestSubmit(); return 1; })()`);
    await sleep(800);
    let bs = await bookState();
    check(bookBodies.length === 1 && !!heldBook && !bs.book && bs.submitting, `${W}: Book pressed three times sends one request, Book off while it runs`, JSON.stringify({ requests: bookBodies.length, held: !!heldBook, bs }));
    const bk0 = bookBodies[0] || {};
    check(uuid0 !== '' && bk0.uuid === uuid0 && t0 > 0 && bk0.quote === t0.toFixed(2), `${W}: Book sends response=wizard, the form's booking id and quote_total = the Review total`, JSON.stringify({ uuid: bk0.uuid === uuid0, quote: bk0.quote, review: t0 }));
    bookMode = null;
    if (heldBook) await fulfil(heldBook, 'text/html', '<!doctype html><html><body>Fatal error</body></html>');
    await waitFor(`!gbSubmitting`, 8000);
    bs = await bookState();
    check(bs.step === 5 && UNCLEAR.test(bs.msg) && bs.book, `${W}: a non-JSON Book reply shows "We could not confirm your booking... may have been saved", Book comes back`, JSON.stringify(bs));
    // A network failure: the same message, Book back; the retry keeps the booking id
    bookMode = 'fail'; netFailAllowed = true;
    let nb = bookBodies.length;
    await click('.btn-submit');
    await waitFor(`!gbSubmitting`, 8000); await sleep(400);
    netFailAllowed = false; bookMode = null;
    bs = await bookState();
    check(bookBodies.length === nb + 1 && bs.step === 5 && UNCLEAR.test(bs.msg) && bs.book, `${W}: a network failure on Book shows the same message, Book comes back`, JSON.stringify({ requests: bookBodies.length - nb, bs }));
    check((bookBodies[nb] || {}).uuid === uuid0, `${W}: pressing Book again keeps the same booking id (client_uuid)`);
    await noScroll('Book unclear');
    // A step-3 refusal: step 3 opens with its message, the typed text kept, the reply's csrf_token replaces the used one
    await setField('special_requests', 'Zz kept words');
    check(await waitFor(`${fresh} && !gbSubmitting`, 45000), `${W}: an edit on Review refreshes the quote before the next Book`);
    const tokReal = await formToken();
    await js(`(document.querySelector('#bookingForm [name="csrf_token"]').value = 'zz-used-token', 1)`);
    bookMode = { json: { ok: false, step: 3, field: 'room_id', message: 'Zz canned: this room is no longer free for these dates.', csrf_token: tokReal } };
    nb = bookBodies.length;
    await click('.btn-submit');
    const at3 = await waitFor(`gbCurrent === 3 && !gbSubmitting`, 8000);
    bookMode = null;
    s = await noScroll('Book step 3 refusal');
    check(at3 && bookBodies.length === nb + 1 && s.errs.some(e => e.step === 3 && /Zz canned: this room/.test(e.text)) && s.activeStep === 3,
      `${W}: a step-3 refusal of Book opens step 3 with its message, focus there`, JSON.stringify({ step: s.step, errs: s.errs, active: s.active }));
    const kept = await js(`({ name: document.getElementById('guest_name').value, email: document.getElementById('guest_email').value, req: document.getElementById('special_requests').value, room: String(selectedRoomId), co: document.getElementById('check_out_date').value })`);
    check(kept.name === 'Zz Steps Browser' && kept.email === 'zz.steps.browser@example.invalid' && kept.req === 'Zz kept words' && kept.room === String(R.room_id) && kept.co === R.co2,
      `${W}: the refusal keeps everything typed`, JSON.stringify(kept));
    check(await formToken() === tokReal && (bookBodies[nb] || {}).token === 'zz-used-token', `${W}: the refusal's csrf_token replaces the used one in the form`);
    // Back to Review through the step bar, then a price-changed refusal with the save's new figures
    await click('#gbStepBar [data-gb-go="5"]');
    check(await waitFor(`${onStep(5)} && ${fresh}`, 60000), `${W}: the step bar goes back to Review with a fresh quote`, JSON.stringify((await state()).errs));
    const q = await js(`JSON.parse(JSON.stringify(gbQuote))`);
    const tOld = Number(q.totals.total_with_vat); q.totals.total_with_vat = tOld + 1000;
    bookMode = { json: { ok: false, step: 5, field: '', price_changed: true, message: 'Zz canned: the price changed since you checked it.', quote: q, csrf_token: await formToken() } };
    const beforeP = dryReplies.length;
    await click('.btn-submit');
    await waitFor(`!gbSubmitting`, 8000); await sleep(500);
    bookMode = null;
    s = await noScroll('Book price changed'); bs = await bookState();
    check(s.step === 5 && s.total === tOld + 1000 && money(s.shown) === tOld + 1000 && s.quote === 'fresh' && s.book && /Zz canned: the price changed/.test(bs.msg),
      `${W}: a price-changed refusal shows the new total on Review with its message, Book on`, JSON.stringify({ step: s.step, total: s.total, shown: s.shown, quote: s.quote, book: s.book, msg: bs.msg }));
    check(dryReplies.length === beforeP && await js(`(document.getElementById('gbQuoteStatus') || {}).textContent === ''`), `${W}: no price check and no "checking again" after a refusal that carries the figures`, `${dryReplies.length - beforeP} check(s)`);
    // A server refusal: a past check-in (the page's own checks do not catch it) opens step 1 with the server message
    const beforeX = dryReplies.length;
    await js(`(() => { const el = document.getElementById('check_in_date'); el.value = ${JSON.stringify(R.yesterday)}; el.dispatchEvent(new Event('change', { bubbles: true })); return 1; })()`);
    const refused = await waitFor(`gbCurrent === 1 && document.querySelectorAll('#gbStep1 .gb-field-error, #gbStep1 .gb-step__error:not([hidden])').length > 0`, 45000);
    s = await state();
    const dX = dryReplies.slice(beforeX); const lastX = dX[dX.length - 1] || {};
    check(refused && lastX.ok === false && lastX.step === 1 && s.errs.some(e => e.step === 1 && e.text === String(lastX.message).replace(/<\/?[^>]+(>|$)/g, '').trim()) && s.activeStep === 1 && !s.book,
      `${W}: a past check-in refused by the server opens step 1 with its message, focus there, Book off`, JSON.stringify({ step: s.step, errs: s.errs, reply: lastX, active: s.active }));
    await noScroll('step 1 refusal');
    // Book success, last: good dates again, Review, Book → the redirect opens (a canned page)
    await js(`(checkInCalendar.setDate(${JSON.stringify(R.ci)}, true), checkOutCalendar.setDate(${JSON.stringify(R.co2)}, true), 1)`);
    await waitFor(`!gbBusy && !availabilityCheckPending`, 20000);
    for (let st = 1; st <= 4; st++) {
      if (!(await waitFor(onStep(st), 3000))) break;
      await click(`#gbStep${st} [data-gb-next]`);
      if (!(await waitFor(onStep(st + 1), 45000))) break;
    }
    const sR = await state();
    check(await waitFor(`${onStep(5)} && ${fresh}`, 45000), `${W}: good dates again reach Review with a fresh quote`, JSON.stringify({ step: sR.step, ci: sR.ci, co: sR.co, quote: sR.quote, errs: sR.errs }));
    const tS = (await state()).total;
    const ref = 'ZZGCBROWSER' + W;
    bookMode = { json: { ok: true, step: 5, message: '', field: '', redirect: 'booking-confirmation.php?ref=' + ref, csrf_token: await formToken() } };
    nb = bookBodies.length; confirmGets = [];
    await js('window.__gbNav = 1');
    await click('.btn-submit');
    const went = await waitFor(`!window.__gbNav && location.pathname.endsWith('/booking-confirmation.php') && document.readyState === 'complete'`, 15000);
    bookMode = null;
    const href = await js('location.href');
    check(went && href === `${BASE}/booking-confirmation.php?ref=${ref}` && confirmGets.length === 1, `${W}: a successful Book opens its redirect`, JSON.stringify({ href, gets: confirmGets.length }));
    check(bookBodies.length === nb + 1 && (bookBodies[nb] || {}).quote === Number(tS).toFixed(2) && (bookBodies[nb] || {}).uuid === uuid0,
      `${W}: that Book sent quote_total = the Review total on screen and the same booking id`, JSON.stringify({ sent: (bookBodies[nb] || {}).quote, review: tS }));
  } catch (e) {
    check(false, `${W}: run`, String(e.message || e) + " | last navigations: " + navLog.slice(-6).join(" ; ") + " | now " + (Date.now() % 100000));
  }
  if (W < 600) check(maxScroll.w === 0, `${W}: no horizontal scroll on any step`, JSON.stringify(maxScroll));
  check(consoleErrors.length === 0, `${W}: no console errors`, consoleErrors.slice(0, 5).join(' | '));
}
clearTimeout(watchdog);
check(postsBlocked === 0, 'no form POST reached the server (Book answered in the browser only)', `${postsBlocked} blocked`);
check(phpErrors.length === 0, 'no PHP errors from php -S', phpErrors.slice(0, 3).join(' | '));
console.log(`\nguest booking steps: ${pass} passed, ${fail} failed`);
await stopAll();
process.exit(fail ? 1 : 0);
