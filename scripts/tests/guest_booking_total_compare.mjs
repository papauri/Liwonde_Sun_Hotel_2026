// Guest booking page: the total the guest SEES before submit (helper of scripts/tests/guest_booking_total_compare.php,
// which picks the cases and compares with what the save stores). Liwonde TEST hotel only; read-only.
//
//   node scripts/tests/guest_booking_total_compare.mjs <cases.json> <out.json>
//
// cases.json: [{ key, room_id, check_in, check_out, guests, children, booking_type, inject_package }]
//   inject_package (optional) { id, name, price_type, price_amount }: added to the packages list of the page's own
//   check-availability.php answers (caught in the browser), then ticked, so the page's own JS prices it. Used when the
//   hotel has no active package; the PHP side seeds the same package inside its rolled-back save.
// out.json: per case { key, shown_text, shown, rate_text, child_text, package_text, occupancy_text, split_pricing,
//   fields: [[name, value], ...] (the form as it would be posted), errors }.
//
// Own php -S (dev-router.php) on its own port and its own headless Chrome (own CDP port, temp profile); both stopped at
// the end. Every POST to booking.php is failed in the browser: nothing is ever submitted. No booking is written; the
// page loads do log site_visitors / session_logs rows (HeadlessChrome) like any visit.
// Env: GBTC_HTTP_PORT (8793), GBTC_CDP_PORT (9393); the PHP side passes two free ports. Refuses if either port already
// answers, so it never drives someone else's Chrome or reads someone else's server (e.g. a Rosalyn's dev server).
import { spawn } from 'node:child_process';
import { createConnection } from 'node:net';
import { mkdtempSync, writeFileSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const [casesFile, outFile] = process.argv.slice(2);
if (!casesFile || !outFile) { console.log('usage: node guest_booking_total_compare.mjs <cases.json> <out.json>'); process.exit(2); }
if (!/liwonde/i.test(ROOT)) { console.log('REFUSED: not the Liwonde checkout'); process.exit(2); }
const CASES = JSON.parse(readFileSync(casesFile, 'utf8'));
const HTTP_PORT = Number(process.env.GBTC_HTTP_PORT || 8793);
const CDP_PORT = Number(process.env.GBTC_CDP_PORT || 9393);
const BASE = `http://127.0.0.1:${HTTP_PORT}`;
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
const portAnswers = (port) => new Promise(r => {
  const s = createConnection({ host: '127.0.0.1', port });
  s.once('connect', () => { s.destroy(); r(true); });
  s.once('error', () => r(false));
  s.setTimeout(1000, () => { s.destroy(); r(false); });
});
for (const p of [HTTP_PORT, CDP_PORT]) {
  if (await portAnswers(p)) { console.log(`REFUSED: port ${p} is already in use by another program`); process.exit(2); }
}

const phpErrors = [];
const server = spawn('php', ['-S', `127.0.0.1:${HTTP_PORT}`, '-t', '.', 'scripts/tests/dev-router.php'], { cwd: ROOT, stdio: ['ignore', 'ignore', 'pipe'] });
server.stderr.on('data', (d) => String(d).split('\n').forEach(l => { if (/PHP (Fatal|Parse|Warning)|Uncaught/.test(l)) phpErrors.push(l.trim()); }));
const profile = mkdtempSync(join(tmpdir(), 'gbtc-chrome-'));
const chrome = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new', `--remote-debugging-port=${CDP_PORT}`,
  `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', '--lang=en-US', '--window-size=1280,900', 'about:blank'], { stdio: 'ignore' });
let ws = null;
async function stopAll() {
  try { if (ws) { ws.send(JSON.stringify({ id: 999999, method: 'Browser.close' })); await sleep(500); } } catch {}
  try { chrome.kill(); } catch {}
  try { server.kill(); } catch {}
  await sleep(300);
  try { rmSync(profile, { recursive: true, force: true }); } catch {}
}
process.on('SIGINT', async () => { await stopAll(); process.exit(130); });
const crashed = async (e) => { console.log('helper crashed: ' + String(e && e.message || e)); await stopAll(); process.exit(2); };
process.on('uncaughtException', crashed);
process.on('unhandledRejection', crashed);

let up = false;
for (let i = 0; i < 60 && !up; i++) { await sleep(250); try { await fetch(BASE + '/booking.php', { redirect: 'manual' }); up = true; } catch {} }
let targets = [];
for (let i = 0; i < 60; i++) { try { targets = await (await fetch(`http://127.0.0.1:${CDP_PORT}/json`)).json(); if (targets.find(t => t.type === 'page')) break; } catch {} await sleep(250); }
const pageTarget = targets.find(t => t.type === 'page');
// Our own processes must still be running: if php -S or Chrome failed to bind, whatever answered is not ours.
if (!up || !pageTarget || server.exitCode !== null || chrome.exitCode !== null) {
  console.log('could not start ' + (!up || server.exitCode !== null ? 'php -S' : 'Chrome') + ' on its own port'); await stopAll(); process.exit(2);
}

ws = new WebSocket(pageTarget.webSocketDebuggerUrl);
await new Promise(r => ws.addEventListener('open', r));
let msgId = 0; const pending = new Map(); let pageErrors = []; let postsBlocked = 0; let inject = null;
// Every CDP call gives up after 15 s: a reply that never comes must not hang the run.
function send(method, params = {}) {
  const i = ++msgId; ws.send(JSON.stringify({ id: i, method, params }));
  return new Promise(r => { pending.set(i, r); setTimeout(() => { if (pending.has(i)) { pending.delete(i); r({ error: { message: 'no reply to ' + method } }); } }, 15000); });
}
ws.addEventListener('message', async (ev) => {
  const m = JSON.parse(ev.data);
  if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); return; }
  if (m.method === 'Runtime.exceptionThrown') pageErrors.push((m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text || '').split('\n')[0]);
  if (m.method === 'Page.javascriptDialogOpening') send('Page.handleJavaScriptDialog', { accept: true });
  if (m.method !== 'Fetch.requestPaused') return;
  const p = m.params;
  const isAvail = p.request.url.includes('check-availability.php');
  if (process.env.GBTC_DEBUG && isAvail) console.log('[debug] paused', p.responseStatusCode !== undefined ? 'response ' + p.responseStatusCode : 'request', p.request.url.slice(0, 160));
  if (p.responseStatusCode !== undefined) { // response stage: check-availability.php only
    if (!isAvail || !inject) { send('Fetch.continueRequest', { requestId: p.requestId }); return; }
    const b = await send('Fetch.getResponseBody', { requestId: p.requestId });
    if (!b.result) { send('Fetch.continueRequest', { requestId: p.requestId }); return; }
    let text = b.result ? (b.result.base64Encoded ? Buffer.from(b.result.body, 'base64').toString('utf8') : b.result.body) : '';
    try {
      const j = JSON.parse(text);
      // The page reads packages per room type (payload.rooms[id].packages; a single-room answer carries .packages).
      const add = (r) => { if (r && typeof r === 'object' && (Array.isArray(r.packages) || r.split_pricing)) {
        r.packages = (Array.isArray(r.packages) ? r.packages : []).filter(x => Number(x.id) !== Number(inject.id)).concat([{ id: inject.id, name: inject.name,
          short_description: '', icon: 'fas fa-gift', price_type: inject.price_type, price_amount: Number(inject.price_amount), inclusions: [], is_featured: 0 }]);
      } };
      if (j && typeof j === 'object') {
        add(j);
        if (j.rooms && typeof j.rooms === 'object') Object.values(j.rooms).forEach(add);
        text = JSON.stringify(j);
        if (process.env.GBTC_DEBUG) console.log('[debug] reply keys', Object.keys(j).join(','), 'rooms:', j.rooms ? Object.keys(j.rooms).join(',') : '-');
      }
    } catch (e) { if (process.env.GBTC_DEBUG) console.log('[debug] reply not JSON', String(e).slice(0, 100), text.slice(0, 120)); }
    send('Fetch.fulfillRequest', { requestId: p.requestId, responseCode: p.responseStatusCode, responseHeaders: p.responseHeaders || [], body: Buffer.from(text, 'utf8').toString('base64') });
    return;
  }
  if (p.request.method === 'POST' && /\/booking\.php(\?|$)/.test(p.request.url)) { // never submit
    postsBlocked++;
    send('Fetch.failRequest', { requestId: p.requestId, errorReason: 'Aborted' });
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
async function waitFor(expr, ms = 30000, label = expr) {
  const t0 = Date.now();
  while (Date.now() - t0 < ms) { try { if (await js(expr)) return true; } catch {} await sleep(200); }
  throw new Error('timed out waiting for ' + label);
}
await send('Runtime.enable'); await send('Page.enable');
// No HTTP/memory cache: a cached check-availability.php answer never reaches the Fetch hook (the package would be missing).
// Nor the site's service worker (js/pwa-install.js): once it controls the page its fetches skip this tab's Fetch hook.
await send('Network.enable'); await send('Network.setCacheDisabled', { cacheDisabled: true });
await send('Network.setBypassServiceWorker', { bypass: true });
await send('Fetch.enable', { patterns: [{ urlPattern: '*booking.php*', requestStage: 'Request' }, { urlPattern: '*check-availability.php*', requestStage: 'Request' },
  { urlPattern: '*check-availability.php*', requestStage: 'Response' }] });
await send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });

const READ = `(() => {
  const t = (id) => { const n = document.getElementById(id); return n ? (n.textContent || '').replace(/\\s+/g, ' ').trim() : null; };
  const vis = (id) => { const n = document.getElementById(id); return !!n && n.style.display !== 'none'; };
  const ci = document.getElementById('check_in_date').value, co = document.getElementById('check_out_date').value;
  const g = parseInt(document.getElementById('number_of_guests').value || '0', 10), c = parseInt(document.getElementById('child_guests').value || '0', 10);
  let sp = null;
  try { sp = (roomAvailabilityStatus[buildAvailabilityStatusKey(selectedRoomId, ci, co, c, g)] || {}).split_pricing || null; } catch (e) {}
  const fd = new FormData(document.getElementById('bookingForm'));
  const fields = []; fd.forEach((v, k) => fields.push([k, String(v)]));
  return { shown_text: t('summaryTotal'), rate_text: t('summaryRatePerNight'), child_text: vis('summaryChildChargeRow') ? t('summaryChildCharge') : null,
    package_text: vis('summaryPackageTotalRow') ? t('summaryPackageTotal') : null, occupancy_text: t('summaryOccupancyType'),
    rate_plan_text: vis('summaryRatePlanRow') ? (t('summaryRatePlanLabel') + ' ' + t('summaryRatePlanValue')) : null,
    summary_visible: vis('bookingSummary'), pending: typeof availabilityCheckPending !== 'undefined' ? !!availabilityCheckPending : null,
    check_in: ci, check_out: co, guests: g, children: c, split_pricing: sp, fields };
})()`;
const money = (s) => { const m = String(s || '').replace(/,/g, '').match(/-?\d+(\.\d+)?/); return m ? Number(m[0]) : null; };
const priced = `(() => { const s = document.getElementById('summaryTotal'); const v = s ? s.textContent : ''; return /\\d/.test(v) && !(typeof availabilityCheckPending !== 'undefined' && availabilityCheckPending); })()`;

const out = [];
// Hard stop: the browser part gives up after GBTC_RUN_MS (240 s), keeps what it measured and stops its own php -S + Chrome.
const RUN_MS = Number(process.env.GBTC_RUN_MS || 240000);
const watchdog = setTimeout(async () => {
  console.log('browser run timed out: the remaining cases could not be measured');
  try { writeFileSync(outFile, JSON.stringify({ results: out, posts_blocked: postsBlocked, php_errors: phpErrors, timed_out: true }, null, 1)); } catch {}
  await stopAll(); process.exit(0);
}, RUN_MS);
for (const c of CASES) {
  const res = { key: c.key, errors: [] };
  pageErrors = [];
  inject = c.inject_package || null;
  try {
    const q = new URLSearchParams({ room_id: String(c.room_id), check_in: c.check_in, check_out: c.check_out, guests: String(c.guests), children: String(c.children || 0) });
    await js('window.__gbNav = 1').catch(() => {});
    await send('Page.navigate', { url: `${BASE}/booking.php?${q}` });
    await waitFor('!window.__gbNav && document.readyState === "complete"', 30000, 'page load');
    await waitFor(priced, 30000, 'a priced total');
    if (c.booking_type === 'tentative') {
      await js(`(() => { const r = document.querySelector('input[name="booking_type"][value="tentative"]'); if (!r) throw new Error('no tentative option'); r.click(); if (typeof selectBookingType === 'function') selectBookingType('tentative'); return 1; })()`);
      await sleep(400);
    }
    if (inject) {
      await waitFor(`!!document.querySelector('#packagesList input[name="package_ids[]"][value="${inject.id}"]')`, 20000, 'the package option');
      await js(`(() => { const cb = document.querySelector('#packagesList input[name="package_ids[]"][value="${inject.id}"]'); if (!cb.checked) cb.click(); return 1; })()`);
      await sleep(400);
    }
    await sleep(600);
    await waitFor(priced, 30000, 'a priced total after the choices');
    Object.assign(res, await js(READ));
    res.shown = money(res.shown_text);
  } catch (e) {
    res.errors.push('could not measure: ' + String(e.message || e));
    try { Object.assign(res, await js(READ)); res.shown = money(res.shown_text); } catch {}
  }
  if (pageErrors.length) res.errors.push(...pageErrors.map(x => 'page js: ' + x));
  out.push(res);
  console.log(`${c.key}: shown ${res.shown_text}${res.errors.length ? '  (' + res.errors.join(' | ') + ')' : ''}`);
}
clearTimeout(watchdog);
writeFileSync(outFile, JSON.stringify({ results: out, posts_blocked: postsBlocked, php_errors: phpErrors }, null, 1));
await stopAll();
process.exit(0);
