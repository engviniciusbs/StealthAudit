// SPDX-License-Identifier: GPL-3.0-or-later
// Browser end-to-end verification of the StealthAudit panel (phase 6c). Development only.
//
//   npm run test:e2e
//
// Requires the existing wp-env test environment (npm run env:test:start), Docker, OpenSSL on PATH
// and a local Google Chrome (or CHROME_PATH). Everything synthetic is created by
// tests/e2e/fixture.php and restored in a finally block, including on failure.
// Output: a numbered list of checks, artifacts/e2e/results.json, masked screenshots and
// sanitized HTML captures in artifacts/e2e/render (gitignored; no QR, codes or nonces).
import { execFileSync } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { setTimeout as sleep } from 'node:timers/promises';
import { chromium } from 'playwright-core';
import { base32, decodeQr, parseOtpauth, selfTest, totp } from './otp.mjs';
import { startAttacker, startProxy } from './proxy.mjs';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = join(ROOT, 'artifacts/e2e');
const RENDER = join(OUT, 'render');
const SHOTS = join(OUT, 'shots');
const BASE = 'https://localhost:8443';
// The compatibility matrix (tools/matrix.mjs) points the run at its own isolated environment;
// by default this is the wp-env test environment on 8880.
const ENV_PREFIX = process.env.STEALTHAUDIT_E2E_ENV || 'wp-env-stealthaudit-tests';
const HTTP_PORT = Number(process.env.STEALTHAUDIT_E2E_HTTP_PORT || 8880);
// Where the repository (fixture + artifacts/) is visible inside the CLI container. The packaged-ZIP
// site (tests/package/run.mjs) does not map the repository into WordPress; it mounts it at
// /opt/stealthaudit-dev, outside the webroot, and the plugin under test is the installed ZIP.
const WORKDIR = process.env.STEALTHAUDIT_E2E_WORKDIR || '/var/www/html/wp-content/mu-plugins/stealthaudit';
const WIDTHS = [320, 375, 768, 1280, 1920];
const results = [];
let last = 0;
let usedStep = 0;

// ---------- infrastructure ----------
function container() {
  if (!/^[a-z0-9-]+$/.test(ENV_PREFIX)) throw new Error(`invalid STEALTHAUDIT_E2E_ENV ${ENV_PREFIX}`);
  // Exact compose project (<name>-<8 hex>): one matrix slug can be a prefix of another
  // (php84-mysql8413 and php84-mysql8413-pwpx9-...), so "starts with" could pick the wrong site.
  const exact = new RegExp(`^${ENV_PREFIX}-[0-9a-f]{8}-cli-1$`);
  const names = execFileSync('docker', ['ps', '--filter', `name=${ENV_PREFIX}`, '--format', '{{.Names}}']).toString().split(/\s+/);
  const cli = names.find((n) => exact.test(n));
  if (!cli) throw new Error('wp-env test environment is not running (npm run env:test:start)');
  return cli;
}
const CLI = container();
function wp(...args) {
  if (!/^\/[A-Za-z0-9/_.-]+$/.test(WORKDIR)) throw new Error(`invalid STEALTHAUDIT_E2E_WORKDIR ${WORKDIR}`);
  return execFileSync('docker', ['exec', '-w', WORKDIR, CLI, 'wp', '--path=/var/www/html', ...args], { env: { ...process.env, MSYS_NO_PATHCONV: '1' } }).toString().trim();
}
function fixture(action, input) {
  return wp('eval-file', 'tests/e2e/fixture.php', action, ...(input ? [JSON.stringify(input)] : []));
}
function check(condition, message) {
  if (!condition) throw new Error(message);
}
async function step(title, fn) {
  const started = Date.now();
  const id = ++last;
  try {
    const detail = await fn();
    results.push({ id, title, ok: true, ms: Date.now() - started, detail: detail ?? null });
    console.log(`ok ${id} - ${title}${detail ? ` (${typeof detail === 'string' ? detail : JSON.stringify(detail)})` : ''}`);
  } catch (failure) {
    results.push({ id, title, ok: false, ms: Date.now() - started, detail: String(failure.message || failure) });
    console.log(`not ok ${id} - ${title}: ${failure.message || failure}`);
    throw failure;
  }
}

// ---------- page helpers ----------
const panelUrl = (query = '') => `${BASE}/?stealthaudit=panel${query ? `&${query}` : ''}`;
const screen = (page) => page.evaluate(() => [...document.querySelectorAll('[data-sa-screen]')].filter((s) => !s.hidden).map((s) => s.getAttribute('data-sa-screen')).join(','));
async function ready(page) {
  await page.waitForLoadState('load');
  await page.waitForFunction(() => !document.querySelector('fieldset[data-sa-js][disabled]') || !document.querySelector('[data-sa-root]'));
}
async function records(page) {
  await page.waitForFunction(() => ['full', 'empty', 'error'].includes(document.querySelector('[data-sa-records]')?.getAttribute('data-state')));
  return page.evaluate(() => document.querySelector('[data-sa-records]').getAttribute('data-state'));
}
async function goto(page, url) {
  const response = await page.goto(url);
  await ready(page);
  return response;
}
async function login(page, user, start = panelUrl()) {
  await page.goto(start);
  check(page.url().includes('/wp-login.php'), `expected wp-login redirect, got ${page.url()}`);
  await page.fill('#user_login', user.login);
  await page.fill('#user_pass', user.password);
  await Promise.all([page.waitForURL((u) => !u.pathname.endsWith('wp-login.php')), page.click('#wp-submit')]);
  await ready(page);
}
async function submit(page, action, fields = {}) {
  const form = page.locator(`form[data-sa-action="${action}"]`).first();
  for (const [selector, value] of Object.entries(fields)) await form.locator(selector).fill(value);
  await form.locator('button[type="submit"]').click();
}
// Run an action that ends in window.location.reload() and wait for the new document.
async function reloads(page, action) {
  const loaded = page.waitForEvent('load');
  await action();
  await loaded;
  await ready(page);
}
async function formStatus(page, action) {
  await page.waitForFunction((a) => (document.querySelector(`form[data-sa-action="${a}"] [data-sa-form-status]`)?.textContent || '') !== '', action);
  return page.locator(`form[data-sa-action="${action}"] [data-sa-form-status]`).textContent();
}
// Same-origin call from the page, with the nonces the page received; overrides drop or forge them.
function api(page, method, route, { body, params, nonce, restNonce, headers } = {}) {
  return page.evaluate(async ({ method, route, body, params, nonce, restNonce, headers }) => {
    const root = document.querySelector('[data-sa-root]');
    const url = new URL(root.getAttribute('data-rest') + route, location.href);
    for (const [k, v] of Object.entries(params || {})) url.searchParams.set(k, v);
    const h = { Accept: 'application/json', ...(headers || {}) };
    const sa = nonce === undefined ? root.getAttribute('data-nonce') : nonce;
    const wpn = restNonce === undefined ? root.getAttribute('data-rest-nonce') : restNonce;
    if (sa !== null) h['X-StealthAudit-Nonce'] = sa;
    if (wpn !== null) h['X-WP-Nonce'] = wpn;
    if (method === 'POST') h['Content-Type'] = 'application/json';
    const r = await fetch(url, { method, headers: h, credentials: 'same-origin', cache: 'no-store', body: method === 'POST' ? JSON.stringify(body || {}) : undefined });
    return { status: r.status, cache: r.headers.get('cache-control'), json: await r.json().catch(() => null) };
  }, { method, route, body, params, nonce, restNonce, headers });
}
function listing(page, kind) {
  const response = page.waitForResponse((r) => decodeURIComponent(r.url()).includes(`/stealthaudit/v1/${kind}`) && r.request().method() === 'GET');
  // A step that fails before awaiting it must not leave an unhandled rejection that kills the
  // process before the finally block restores the site; awaiting it still rejects normally.
  response.catch(() => {});
  return response;
}
async function readQr(page) {
  const box = page.locator('[data-sa-qr]');
  await box.locator('img').waitFor();
  await page.waitForFunction(() => document.querySelector('[data-sa-qr] img')?.complete);
  const decoded = decodeQr(await box.screenshot());
  return { ...decoded, otp: parseOtpauth(decoded.text) };
}
// A step above every step already consumed (the server rejects replays and lower steps).
async function freshCode(secret) {
  let now = Date.now() / 1000;
  if (Math.floor(now / 30) <= usedStep) {
    await sleep(((usedStep + 1) * 30 - now + 1) * 1000);
    now = Date.now() / 1000;
  }
  usedStep = Math.floor(now / 30);
  return totp(base32(secret), now);
}

// ---------- evidence helpers ----------
const ASSETS = ['tokens.css', 'base.css', 'panel.css'];
const PLACEHOLDER_QR = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 29 29" width="256" height="256"><rect width="29" height="29" fill="#fff"/><rect x="4" y="4" width="21" height="21" fill="none" stroke="#999" stroke-width="1"/></svg>');
async function capture(page, name) {
  const html = await page.evaluate((placeholder) => {
    const doc = document.documentElement.cloneNode(true);
    doc.querySelectorAll('script[src], script:not([type="application/json"])').forEach((s) => s.remove());
    doc.querySelectorAll('[data-sa-qr] img').forEach((img) => { img.src = placeholder; });
    doc.querySelectorAll('[data-sa-codes] code').forEach((c) => { c.textContent = '0000…0000'; });
    doc.querySelectorAll('[data-nonce]').forEach((n) => { n.setAttribute('data-nonce', 'redacted'); n.setAttribute('data-rest-nonce', 'redacted'); });
    doc.querySelectorAll('input').forEach((i) => { if (i.type === 'password' || ['code', 'recovery_code'].includes(i.name)) i.removeAttribute('value'); });
    doc.querySelectorAll('link[rel="stylesheet"]').forEach((l) => { const file = new URL(l.href).searchParams.get('file'); l.setAttribute('href', `./${file}`); });
    return '<!doctype html>\n' + doc.outerHTML;
  }, PLACEHOLDER_QR);
  check(!/otpauth|data-nonce="[0-9a-f]{10}"/.test(html), `capture ${name} still holds a secret`);
  writeFileSync(join(RENDER, `${name}.html`), html);
  await page.screenshot({ path: join(SHOTS, `${name}-1280.png`), fullPage: true, mask: [page.locator('[data-sa-qr]'), page.locator('[data-sa-codes]')] });
  return name;
}
// Page overflow: any visible element beyond the viewport edge outside the table's own scroll region.
async function overflow(page) {
  const out = {};
  for (const width of WIDTHS) {
    await page.setViewportSize({ width, height: 900 });
    out[width] = await page.evaluate(() => {
      const vw = document.documentElement.clientWidth;
      const bad = [];
      for (const el of document.body.querySelectorAll('*')) {
        if (el.closest('.records__scroll') && !el.matches('.records__scroll')) continue;
        if (el.closest('.visually-hidden, .skip, [hidden], noscript, script')) continue;
        const r = el.getBoundingClientRect();
        if (r.width && (r.right > vw + 0.5 || r.left < -0.5)) bad.push(`${el.tagName.toLowerCase()}.${el.className}`);
      }
      return { scroll: document.documentElement.scrollWidth - vw, bad: bad.slice(0, 5) };
    });
  }
  await page.setViewportSize({ width: 1280, height: 900 });
  const failing = Object.entries(out).filter(([, v]) => v.scroll > 0 || v.bad.length);
  check(!failing.length, `horizontal overflow: ${JSON.stringify(failing)}`);
  return out;
}
async function keyboard(page, max = 40) {
  await page.evaluate(() => { document.activeElement?.blur(); window.scrollTo(0, 0); });
  await page.keyboard.press('Tab');
  const seen = [];
  for (let i = 0; i < max; i++) {
    const info = await page.evaluate(() => {
      const el = document.activeElement;
      if (!el || el === document.body) return null;
      const cs = getComputedStyle(el);
      const r = el.getBoundingClientRect();
      const name = (el.getAttribute('aria-label') || el.labels?.[0]?.textContent || el.textContent || el.name || '').trim().replace(/\s+/g, ' ').slice(0, 40);
      return { tag: el.tagName.toLowerCase(), name, ring: cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) >= 2 && !/rgba\(0, 0, 0, 0\)|transparent/.test(cs.outlineColor), inView: r.bottom > 0 && r.top < innerHeight };
    });
    if (!info) break;
    const key = `${info.tag}:${info.name}`;
    if (seen.length && seen[0].key === key) break;
    seen.push({ key, ...info });
    await page.keyboard.press('Tab');
  }
  const bad = seen.filter((s) => !s.ring || !s.inView);
  check(seen.length > 0 && !bad.length, `focus without visible ring or off screen: ${JSON.stringify(bad)}`);
  return seen.map((s) => s.key);
}
async function contrast(page) {
  return page.evaluate(() => {
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = 1;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    const rgb = (color) => { ctx.clearRect(0, 0, 1, 1); ctx.fillStyle = '#000'; ctx.fillStyle = color; ctx.fillRect(0, 0, 1, 1); return [...ctx.getImageData(0, 0, 1, 1).data.slice(0, 3)]; };
    const lum = ([r, g, b]) => { const f = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
    const ratio = (a, b) => { const [x, y] = [lum(rgb(a)), lum(rgb(b))].sort((p, q) => q - p); return Math.round(((x + 0.05) / (y + 0.05)) * 100) / 100; };
    const v = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    const paper = v('--color-paper');
    return {
      'ink/paper': ratio(v('--color-ink'), paper),
      'ink-2/paper': ratio(v('--color-ink-2'), paper),
      'muted/paper': ratio(v('--color-muted'), paper),
      'accent-ink/accent (primary button)': ratio(v('--color-accent-ink'), v('--color-accent')),
      'focus/paper (ring)': ratio(v('--color-focus'), paper),
      'muted/paper-3 (skeleton row text)': ratio(v('--color-muted'), v('--color-paper-3')),
      'qr dark/qr paper': ratio('#000', v('--color-qr-paper')),
    };
  });
}
async function reducedMotion(browser) {
  const out = {};
  for (const mode of ['no-preference', 'reduce']) {
    const ctx = await browser.newContext({ reducedMotion: mode });
    const page = await ctx.newPage();
    await page.setContent(`<style>${ASSETS.map((f) => readFileSync(join(ROOT, 'assets', f), 'utf8')).join('\n')}</style><a class="btn" href="#">x</a><ul class="tabs"><li><a href="#">y</a></li></ul>`);
    out[mode] = await page.evaluate(() => ({ btn: getComputedStyle(document.querySelector('.btn')).transitionDuration, tab: getComputedStyle(document.querySelector('.tabs a')).transitionDuration, query: matchMedia('(prefers-reduced-motion: reduce)').matches }));
    await ctx.close();
  }
  check(out.reduce.query && /^(1e-05s|0\.00001s)/.test(out.reduce.btn), `reduced motion not applied: ${JSON.stringify(out)}`);
  return out;
}

// ---------- the run ----------
const RECOVER = 'node tools/wp-env.cjs run cli --env-cwd=wp-content/mu-plugins/stealthaudit wp eval-file tests/e2e/fixture.php teardown (then restore)';
// A snapshot left by an interrupted run is the only way back to the original site: never delete it.
if (existsSync(join(OUT, 'snapshot.json'))) {
  console.log(`A previous run left artifacts/e2e/snapshot.json. Restore the site first: ${RECOVER}`);
  process.exit(1);
}
rmSync(OUT, { recursive: true, force: true });
mkdirSync(RENDER, { recursive: true });
mkdirSync(SHOTS, { recursive: true });
for (const f of ASSETS) copyFileSync(join(ROOT, 'assets', f), join(RENDER, f));

let proxy, attacker, browser;
let setUp = false;
try {
  await step('RFC 6238 SHA-1 vectors with the independent TOTP implementation', () => `${selfTest()} vectors`);
  // Set before setup: a failure after the snapshot is written still runs teardown/restore,
  // which are no-ops when no snapshot exists.
  setUp = true;
  fixture('setup');
  const state = JSON.parse(readFileSync(join(OUT, 'state.json'), 'utf8'));
  const { operator, second, outsider } = state.users;
  proxy = await startProxy({ upstream: { host: '127.0.0.1', port: HTTP_PORT } });
  attacker = await startAttacker({ target: BASE });
  browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : { channel: 'chrome' }) });
  const newContext = () => browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 900 }, locale: 'pt-BR' });
  const anon = await newContext();
  const req = anon.request;
  const problems = [];
  const watch = (page) => {
    page.on('dialog', (d) => { problems.push(`dialog: ${d.message()}`); d.dismiss(); });
    page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
    // The test site's active theme ("default") is missing, so WordPress core answers /favicon.ico/ with a 500 wp_die for logged-in users.
    // The attacker page's own console reports the CORS block that step 15 asserts; it is not a panel page.
    page.on('console', (m) => { if (m.type() === 'error' && !page.url().startsWith(attacker.origin) && !/favicon/.test(m.location()?.url || '') && !/status of 40[0134]|status of 429|status of 503/.test(m.text())) problems.push(`console: ${m.text()}`); });
    return page;
  };

  await step('TLS proxy: self-signed loopback certificate; WordPress sees HTTPS only through it', async () => {
    const plain = await req.get(`http://localhost:${HTTP_PORT}/?stealthaudit=panel`, { maxRedirects: 0 });
    check(plain.status() === 403 && (await plain.text()).includes('HTTPS obrigatório') && plain.headers()['cache-control'].includes('no-store'), `plain HTTP gave ${plain.status()}`);
    const secure = await req.get(panelUrl(), { maxRedirects: 0 });
    check(secure.status() === 302, `HTTPS anonymous gave ${secure.status()}`);
    return { http: 403, https: 302, fingerprint: proxy.fingerprint.replace(/^.*=/, '').slice(0, 23) + '…' };
  });

  await step('Anonymous: page redirects to wp-login with a local redirect_to and reauth=1; external redirect_to dropped', async () => {
    const r = await req.get(panelUrl('view=errors&severity=warning'), { maxRedirects: 0 });
    const location = new URL(r.headers().location);
    const back = new URL(location.searchParams.get('redirect_to'));
    check(location.pathname === '/wp-login.php' && location.searchParams.get('reauth') === '1' && back.origin === BASE && back.searchParams.get('severity') === 'warning', `bad login redirect ${location}`);
    const evil = await req.get(panelUrl('view=errors&redirect_to=https%3A%2F%2Fevil.example%2F'), { maxRedirects: 0 });
    const evilBack = new URL(new URL(evil.headers().location).searchParams.get('redirect_to'));
    check(evilBack.origin === BASE && !evil.headers().location.includes('evil'), `external redirect kept: ${evil.headers().location}`);
    return { preserved: back.search, external: evilBack.search };
  });

  await step('Anonymous REST and assets: 401/404 without data, all no-store', async () => {
    const out = {};
    for (const route of ['events', 'errors', 'status']) {
      const r = await req.get(`${BASE}/?rest_route=/stealthaudit/v1/${route}`);
      const json = await r.json();
      check(r.status() === 401 && !('items' in json) && json.code === 'stealthaudit_unauthenticated' && r.headers()['cache-control'].includes('no-store'), `${route} anonymous gave ${r.status()}`);
      out[route] = r.status();
    }
    const post = await req.post(`${BASE}/?rest_route=/stealthaudit/v1/reconfirm`, { data: { password: 'x' } });
    out.reconfirm = post.status();
    check(post.status() === 401, `anonymous POST gave ${post.status()}`);
    const asset = await req.get(`${BASE}/?stealthaudit=asset&file=panel.js`);
    check(asset.status() === 404 && (await asset.body()).length === 0, 'anonymous asset served');
    out.asset = 404;
    const jsonp = await req.get(`${BASE}/?rest_route=/stealthaudit/v1/events&_jsonp=x`);
    out._jsonp = jsonp.status();
    check(!(await jsonp.text()).includes('items'), 'jsonp leaked');
    return out;
  });

  // ---------- operator: login → reconfirm → QR → TOTP → codes ----------
  const opCtx = await newContext();
  const op = watch(await opCtx.newPage());
  await step('Operator logs in through the real wp-login form and lands on the password screen', async () => {
    await login(op, operator);
    check(op.url().startsWith(panelUrl()), `landed on ${op.url()}`);
    check((await screen(op)) === 'password', `screen ${await screen(op)}`);
    return op.url().replace(BASE, '');
  });
  await capture(op, 'password');
  const geometry = {};
  geometry.password = await overflow(op);

  await step('No wp-admin menu or admin-bar entry for StealthAudit (only the Must-Use list)', async () => {
    const admin = await opCtx.newPage();
    await admin.goto(`${BASE}/wp-admin/`);
    const found = await admin.evaluate(() => {
      // The site title node is WordPress's own (a fresh wp-env site is titled after the directory, "StealthAudit").
      const hits = [...document.querySelectorAll('#adminmenu a, #wpadminbar a')].filter((a) => !a.closest('#wp-admin-bar-site-name') && /stealth/i.test(a.href + a.textContent));
      return { menu: hits.length, links: document.querySelectorAll('#adminmenu a').length, hits: hits.map((a) => `${a.closest('[id]')?.id}: ${a.textContent.trim().slice(0, 40)} ${a.getAttribute('href')}`) };
    });
    check(found.links > 10 && found.menu === 0, `menu entries ${JSON.stringify(found)}`);
    await admin.goto(`${BASE}/wp-admin/plugins.php?plugin_status=mustuse`);
    const mu = await admin.locator('#the-list').textContent();
    await admin.close();
    return { adminMenuLinks: found.links, stealthauditEntries: 0, mustUseListed: /stealthaudit/i.test(mu) };
  });

  await step('REST without the second factor: password_required, 401 proof_required, no items', async () => {
    const r = await api(op, 'GET', 'events');
    check(r.status === 401 && r.json.code === 'stealthaudit_proof_required' && r.json.data.panel === 'password_required' && !('items' in r.json), JSON.stringify(r));
    return { status: r.status, panel: r.json.data.panel };
  });

  let secret;
  let codes;
  await step('Reconfirm password, generate the QR, decode it independently and confirm with an independent TOTP', async () => {
    await reloads(op, () => submit(op, 'reconfirm', { 'input[name="password"]': operator.password }));
    check((await screen(op)) === 'enroll', `after reconfirm: ${await screen(op)}`);
    await capture(op, 'enroll');
    const begin = op.waitForResponse((r) => decodeURIComponent(r.url()).includes('/enrollment/begin'));
    await submit(op, 'enrollment/begin');
    const beginResponse = await begin;
    check(beginResponse.headers()['cache-control'].includes('no-store'), 'QR response cacheable');
    const qr = await readQr(op);
    secret = qr.otp.secret;
    check(qr.otp.algorithm === 'SHA1' && qr.otp.digits === 6 && qr.otp.period === 30 && qr.otp.issuer === 'StealthAudit' && base32(secret).length >= 20, JSON.stringify(qr.otp.params));
    check(qr.quietZoneModules >= 3.9 && qr.contrast >= 15, `QR quiet zone ${qr.quietZoneModules} contrast ${qr.contrast}`);
    geometry.setup = await overflow(op);
    await capture(op, 'setup');
    const confirm = op.waitForResponse((r) => decodeURIComponent(r.url()).includes('/enrollment/confirm'));
    await submit(op, 'enrollment/confirm', { '#sa-setup-code': (await freshCode(secret)).replace(/(\d{3})/, '$1 ') });
    check((await confirm).headers()['cache-control'].includes('no-store'), 'codes response cacheable');
    await op.locator('[data-sa-screen="codes"]').waitFor();
    codes = await op.locator('[data-sa-codes] code').allTextContents();
    check(codes.length === 10 && codes.every((c) => /^[0-9a-f]{32}$/.test(c.replace(/[\s-]/g, ''))), `codes ${codes.length}`);
    geometry.codes = await overflow(op);
    await capture(op, 'codes');
    return { version: qr.version, modules: qr.dimension, modulePx: Math.round(qr.modulePx * 10) / 10, quietZone: Math.round(qr.quietZoneModules * 10) / 10, contrast: Math.round(qr.contrast * 10) / 10, label: qr.otp.label.replace(/sa-e2e-\w+-[0-9a-f]+/, 'sa-e2e-…'), params: qr.otp.params, recoveryCodes: codes.length, pastedWithSpace: true };
  });

  await step('Recovery-code acknowledgement is required, then the authorized audit view loads', async () => {
    await submit(op, 'codes-ack');
    check((await op.locator('#sa-codes-ack-help').textContent()).includes('Confirme'), 'ack not enforced');
    await op.check('#sa-codes-ack');
    const list = listing(op, 'events');
    await reloads(op, () => op.locator('form[data-sa-action="codes-ack"] button').click());
    const json = await (await list).json();
    check((await records(op)) === 'full' && json.items.length === 50 && json.limit === 50 && json.next_cursor && json.timezone === 'UTC', `items ${json.items.length}`);
    return { rows: json.items.length, next_cursor: Boolean(json.next_cursor), capture: json.capture.events.state };
  });
  await capture(op, 'audit-full');
  geometry['audit-full'] = await overflow(op);
  const focusAudit = await keyboard(op);
  const contrastRatios = await contrast(op);
  const mfaEvents = JSON.parse(fixture('inspect')).mfa_actions;

  // ---------- filters, pagination and errors ----------
  await step('Pagination: 25 rows, stable keyset cursor, disjoint pages, "Primeira página"', async () => {
    let list = listing(op, 'events');
    await op.selectOption('#sa-limit', '25');
    await op.locator('form[data-sa-filters] button[type="submit"]').click();
    const one = await (await list).json();
    await records(op);
    check(op.url().includes('limit=25') && one.items.length === 25, `page 1 ${one.items.length}`);
    list = listing(op, 'events');
    await op.locator('[data-sa-next]').click();
    const two = await (await list).json();
    await records(op);
    const ids = new Set(one.items.map((i) => i.id));
    const key = (i) => `${i.time}|${String(i.id).padStart(10, '0')}`;
    check(two.items.length === 25 && two.items.every((i) => !ids.has(i.id)) && key(two.items[0]) < key(one.items.at(-1)), 'pages overlap or are out of order');
    check(await op.locator('[data-sa-first]').isVisible(), 'no first-page link');
    const cursor = new URL(op.url()).searchParams.get('cursor');
    return { page1: `${one.items[0].id}..${one.items.at(-1).id}`, page2: `${two.items[0].id}..${two.items.at(-1).id}`, cursor };
  });

  await step('Filters: user, action, result, category, IP and UTC period applied by the server', async () => {
    const out = {};
    const run = async (query, predicate, label) => {
      const list = listing(op, 'events');
      await goto(op, panelUrl(`view=audit&${query}`));
      const json = await (await list).json();
      await records(op);
      check(json.items.length > 0 && json.items.every(predicate), `${label}: ${json.items.length} rows`);
      out[label] = json.items.length;
      return json;
    };
    await run(`user=${operator.id}`, (i) => i.actor_id === operator.id, 'user');
    const enrolled = await run('action=mfa_enrolled&result=success&category=access', (i) => i.action === 'mfa_enrolled' && i.result === 'success' && i.category === 'access', 'action+result+category');
    const ip = enrolled.items[0].ip;
    await run(`ip=${encodeURIComponent(ip)}&user=${operator.id}`, (i) => i.ip === ip, 'ip');
    const to = new Date(Date.now() + 60_000).toISOString().replace(/\.\d+Z$/, 'Z');
    const from = new Date(Date.now() - 3_600_000).toISOString().replace(/\.\d+Z$/, 'Z');
    await run(`from=${from}&to=${to}`, (i) => i.time >= from && i.time <= to, 'period');
    check(await op.inputValue('#sa-from') === from, 'period not kept in the form');
    const list = listing(op, 'events');
    await goto(op, panelUrl('view=audit&from=2026-01-01T00:00:00Z&to=2026-01-02T00:00:00Z'));
    const empty = await (await list).json();
    check((await records(op)) === 'empty' && empty.items.length === 0 && (await op.locator('[data-sa-message-title]').textContent()).includes('Nenhum registro'), 'empty state');
    await capture(op, 'audit-empty');
    out.empty = 0;
    out.ip = `${out.ip} (${ip})`;
    return out;
  });

  await step('Malformed filters: page ignores them with an alert and never echoes; REST answers 400', async () => {
    await goto(op, panelUrl('view=audit&from=yesterday%3Cb%3E&ip=999.1.1.1'));
    const page = await op.content();
    check(await op.locator('.notice[role="alert"]').isVisible() && !page.includes('yesterday') && !page.includes('999.1.1.1'), 'malformed filter echoed or no alert');
    await records(op);
    await capture(op, 'audit-invalid');
    const out = {};
    for (const [label, params] of Object.entries({ date: { from: 'yesterday' }, limit: { limit: '1000' }, ip: { ip: '999.1.1.1' }, cursor: { cursor: 'abc' }, unknown: { foo: '1' }, user: { user: '-3' }, order: { from: '2026-11-02T00:00:00Z', to: '2026-11-01T00:00:00Z' } })) {
      const r = await api(op, 'GET', 'events', { params });
      check(r.status === 400 && !('items' in (r.json || {})), `${label} gave ${r.status}`);
      out[label] = r.status;
    }
    return out;
  });

  await step('XSS: HTML/script in a stored diagnostic and a tampered event row renders as inert text', async () => {
    fixture('inject');
    let list = listing(op, 'errors');
    await goto(op, panelUrl('view=errors&severity=warning'));
    const errors = await (await list).json();
    await records(op);
    const row = await op.evaluate(() => {
      const cells = [...document.querySelectorAll('[data-sa-rows] td')].filter((td) => td.textContent.includes('onerror'));
      return { text: cells[0]?.textContent || '', elements: document.querySelectorAll('[data-sa-rows] img, [data-sa-rows] script').length, title: document.title };
    });
    check(errors.items.some((i) => i.message.includes('<img src=x')) && row.text.includes('<img src=x') && row.elements === 0 && row.title !== 'xss', JSON.stringify(row));
    await capture(op, 'errors-full');
    geometry['errors-full'] = await overflow(op);
    const raw = await op.evaluate(async () => { const root = document.querySelector('[data-sa-root]'); const url = new URL(root.getAttribute('data-rest') + 'errors', location.href); url.searchParams.set('severity', 'warning'); const t = await (await fetch(url, { headers: { 'X-WP-Nonce': root.getAttribute('data-rest-nonce'), 'X-StealthAudit-Nonce': root.getAttribute('data-nonce') } })).text(); return t.includes(String.fromCharCode(92) + 'u003Cimg') && !t.includes('<img'); });
    check(raw, 'REST JSON not hex-escaped');
    list = listing(op, 'events');
    await goto(op, panelUrl('view=audit&action=login_failed&ip=192.0.2.1'));
    await list;
    await records(op);
    const ev = await op.evaluate(() => ({ text: document.querySelector('[data-sa-rows] td.wrap')?.textContent || '', elements: document.querySelectorAll('[data-sa-rows] img, [data-sa-rows] script').length, title: document.title }));
    check(ev.text.includes('<script>') && ev.elements === 0 && ev.title !== 'xss' && !problems.some((p) => p.startsWith('dialog')), JSON.stringify(ev));
    return { errorRow: row.text.slice(0, 40) + '…', eventSummary: ev.text.slice(0, 40) + '…', rawJsonHexEscaped: raw, injectedElements: 0 };
  });

  // ---------- GeoIP (read time, official MaxMind test database only) ----------
  await step('GeoIP: no location column without an MMDB; with the test City DB, "estimativa" for known IPs and "indisponível" otherwise', async () => {
    const geo = () => op.evaluate(() => {
      const heads = [...document.querySelectorAll('[data-sa-table] thead th')];
      const index = heads.findIndex((th) => th.hasAttribute('data-sa-geoip'));
      const cells = index < 0 ? [] : [...document.querySelectorAll('[data-sa-rows] tr')].map((tr) => ({ text: tr.children[index]?.textContent || '', ip: tr.children[index - 1]?.textContent || '', muted: tr.children[index]?.classList.contains('muted') }));
      const line = document.querySelector('[data-sa-geolocation]');
      return { header: index < 0 ? null : heads[index].textContent, cells, line: line ? { state: line.getAttribute('data-sa-geolocation'), text: line.textContent } : null };
    });
    let list = listing(op, 'events');
    await goto(op, panelUrl('view=audit'));
    const plain = await (await list).json();
    await records(op);
    const before = await geo();
    check(before.header === null && before.line === null && !('geolocation' in plain) && !plain.items.some((i) => 'location' in i), `location shown without an MMDB: ${JSON.stringify(before)}`);
    const { path } = JSON.parse(fixture('geoip', { state: 'on' }));
    fixture('config', { mmdb: path });
    const out = {};
    try {
      list = listing(op, 'events');
      await goto(op, panelUrl('view=audit&action=login_failed'));
      const json = await (await list).json();
      await records(op);
      const shown = await geo();
      const london = shown.cells.find((c) => c.ip === '81.2.69.160');
      const japan = shown.cells.find((c) => c.ip === '2001:218::');
      const other = shown.cells.find((c) => c.ip !== '81.2.69.160' && c.ip !== '2001:218::');
      check(json.geolocation?.state === 'available' && json.geolocation.basis === 'current_database' && json.items.every((i) => ['estimate', 'unavailable'].includes(i.location?.status)), `API ${JSON.stringify(json.geolocation)}`);
      check(shown.header === 'Local estimado (base atual)' && shown.line?.state === 'available' && shown.line.text.includes('não o local do IP no momento do evento') && /base compilada em \d{4}-\d\d-\d\dT/.test(shown.line.text), `header/line ${JSON.stringify(shown.line)}`);
      check(london?.text === 'estimativa: Londres, Inglaterra, Reino Unido (GB)' && japan?.text === 'estimativa: Japão (JP)' && other?.text === 'indisponível' && other.muted, `cells ${JSON.stringify(shown.cells.slice(0, 4))}`);
      await capture(op, 'audit-geoip');
      Object.assign(out, { header: shown.header, london: london.text, japan: japan.text, other: `${other.ip} → ${other.text}` });
    } finally {
      fixture('config', {});
      fixture('geoip', { state: 'off' });
    }
    list = listing(op, 'events');
    await goto(op, panelUrl('view=audit'));
    const after = await (await list).json();
    await records(op);
    check((await geo()).header === null && !('geolocation' in after), 'location column left after removing the MMDB');
    out.afterRemoval = 'no column';
    return out;
  });

  // ---------- CSRF and direct REST ----------
  await step('CSRF: missing/forged nonces, cross Origin and Sec-Fetch-Site are rejected; state unchanged', async () => {
    const out = {};
    out.valid = (await api(op, 'GET', 'events')).status;
    out.noStealthNonce = (await api(op, 'GET', 'events', { nonce: null })).status;
    out.forgedNonce = (await api(op, 'GET', 'events', { nonce: 'a1b2c3d4e5' })).status;
    out.noWpNonce = (await api(op, 'GET', 'events', { restNonce: null })).status;
    out.revokeNoNonce = (await api(op, 'POST', 'revoke', { nonce: null })).status;
    out.revokeForged = (await api(op, 'POST', 'revoke', { nonce: 'a1b2c3d4e5' })).status;
    const nonces = await op.evaluate(() => { const r = document.querySelector('[data-sa-root]'); return { sa: r.getAttribute('data-nonce'), wp: r.getAttribute('data-rest-nonce') }; });
    const headers = { 'X-StealthAudit-Nonce': nonces.sa, 'X-WP-Nonce': nonces.wp };
    out.crossOrigin = (await opCtx.request.get(`${BASE}/?rest_route=/stealthaudit/v1/events`, { headers: { ...headers, Origin: 'https://evil.example' } })).status();
    out.crossSite = (await opCtx.request.get(`${BASE}/?rest_route=/stealthaudit/v1/events`, { headers: { ...headers, 'Sec-Fetch-Site': 'cross-site' } })).status();
    out.revokeCrossOrigin = (await opCtx.request.post(`${BASE}/?rest_route=/stealthaudit/v1/revoke`, { headers: { ...headers, Origin: 'https://evil.example' }, data: {} })).status();
    out.textPlain = (await opCtx.request.post(`${BASE}/?rest_route=/stealthaudit/v1/reconfirm`, { headers: { ...headers, 'Content-Type': 'text/plain' }, data: '{"password":"x"}' })).status();
    const expected = { valid: 200, noStealthNonce: 403, forgedNonce: 403, noWpNonce: 401, revokeNoNonce: 403, revokeForged: 403, crossOrigin: 403, crossSite: 403, revokeCrossOrigin: 403, textPlain: 400 };
    check(JSON.stringify(out) === JSON.stringify(expected), JSON.stringify(out));
    const status = await api(op, 'GET', 'status');
    check(status.json.status === 'authorized', 'state changed');
    return out;
  });

  await step('Cross-site attacker page (http://127.0.0.1:8444) cannot read or revoke', async () => {
    const evil = watch(await opCtx.newPage());
    const before = proxy.log.length;
    await evil.goto(attacker.origin);
    await evil.waitForFunction(() => window.results && 'read' in window.results && 'post' in window.results);
    const seen = await evil.evaluate(() => window.results);
    await Promise.all([evil.waitForLoadState('load'), evil.evaluate(() => document.getElementById('f').submit())]);
    await evil.waitForURL(/rest_route/);
    await evil.close();
    const hits = proxy.log.slice(before).filter((e) => e.path.includes('stealthaudit'));
    check(hits.length >= 3 && hits.every((e) => [401, 403].includes(e.status)), JSON.stringify(hits));
    // Core's CORS grant is stripped for our namespace, so the credentialed fetch is unreadable.
    check(seen.read === 'blocked', `cross-site fetch could read the status: ${seen.read}`);
    const cors = {};
    for (const method of ['GET', 'OPTIONS']) {
      const r = await req.fetch(`${BASE}/?rest_route=/stealthaudit/v1/events`, { method, headers: { Origin: attacker.origin, 'Access-Control-Request-Method': 'GET', 'Access-Control-Request-Headers': 'x-wp-nonce' } });
      const h = r.headers();
      check(!('access-control-allow-origin' in h) && !('access-control-allow-credentials' in h), `${method} kept CORS: ${JSON.stringify(h)}`);
      cors[method] = r.status();
    }
    // Control: a core route keeps WordPress's CORS grant (OPTIONS, so the test cache never stores it).
    const core = await req.fetch(`${BASE}/?rest_route=/`, { method: 'OPTIONS', headers: { Origin: attacker.origin, 'Access-Control-Request-Method': 'GET' } });
    check(core.headers()['access-control-allow-origin'] === attacker.origin, 'control: core namespace index lost its CORS header');
    check((await api(op, 'GET', 'status')).json.status === 'authorized', 'attacker changed state');
    return { browserRead: seen.read, cors, requests: hits.map((e) => `${e.method} ${e.status}${e.cookie ? ' (cookie)' : ''}`) };
  });

  await step('WP-Cron maintenance: real "wp cron event run", status API and panel line show the last run', async () => {
    const before = JSON.parse(fixture('maintenance'));
    check(Number.isInteger(before.scheduled), `maintenance not scheduled: ${JSON.stringify(before)}`);
    wp('cron', 'event', 'run', 'stealthaudit_maintenance');
    const after = JSON.parse(fixture('maintenance'));
    check(after.state === 'current' && Number.isInteger(after.last_run), `maintenance report ${JSON.stringify(after)}`);
    const status = (await api(op, 'GET', 'status')).json;
    check(status.maintenance && status.maintenance.state === 'current' && /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/.test(status.maintenance.last_run), `status maintenance ${JSON.stringify(status.maintenance)}`);
    check(status.capture.events.state === 'available', `capture after maintenance ${JSON.stringify(status.capture)}`);
    await goto(op, panelUrl('view=audit'));
    await records(op);
    const line = await op.locator('[data-sa-maintenance]').textContent();
    check(line.includes('Manutenção:') && line.includes(status.maintenance.last_run) && line.includes('em dia'), `panel line: ${line}`);
    return { scheduled: before.scheduled, before: before.state, after: after.state, line };
  });

  // ---------- revoke → challenge → TOTP ----------
  await step('Revoke ("Sair do StealthAudit") → challenge → TOTP → authorized; WordPress session kept', async () => {
    await goto(op, panelUrl());
    await records(op);
    await reloads(op, () => op.locator('form[data-sa-action="revoke"] button').click());
    check((await screen(op)) === 'challenge', `after revoke ${await screen(op)}`);
    const denied = await api(op, 'GET', 'events');
    check(denied.status === 401 && denied.json.data.panel === 'challenge_required', 'revoked proof still valid');
    await capture(op, 'challenge');
    geometry.challenge = await overflow(op);
    const focusChallenge = await keyboard(op);
    const list = listing(op, 'events');
    await submit(op, 'authenticate', { '#sa-challenge-code': await freshCode(secret) });
    await list;
    check((await records(op)) === 'full', 'not authorized after TOTP');
    return { afterRevoke: denied.status, focusOrder: focusChallenge.length };
  });

  // ---------- expiration ----------
  await step('Idle expiration between render and query (proof aged past 1,800 s) → "A verificação expirou", rows cleared', async () => {
    let release;
    const held = new Promise((r) => { release = r; });
    await op.route((u) => decodeURIComponent(u.toString()).includes('/stealthaudit/v1/events'), async (route) => { await held; await route.continue(); });
    const navigation = op.goto(panelUrl('view=audit&result=success'));
    await op.waitForFunction(() => document.querySelector('[data-sa-records]')?.getAttribute('data-state') === 'loading').catch(() => {});
    await navigation;
    const aged = fixture('age-proof');
    release();
    await op.waitForFunction(() => !document.querySelector('[data-sa-screen="challenge"]')?.hidden);
    await op.unrouteAll();
    const view = await op.evaluate(() => ({ heading: document.querySelector('#sa-h-challenge').textContent, rows: document.querySelectorAll('[data-sa-rows] tr').length, workspace: document.querySelector('[data-sa-table]').hidden, chrome: [...document.querySelectorAll('[data-sa-chrome]')].every((n) => n.hidden), focus: document.activeElement?.id }));
    check(view.heading === 'A verificação expirou' && view.rows === 0 && view.workspace && view.chrome && view.focus === 'sa-h-challenge', JSON.stringify(view));
    await capture(op, 'challenge-expired');
    return { aged: aged.replace(/^Success: /, ''), ...view };
  });

  await step('Expired session on reload keeps filters in the URL and states they apply after verification', async () => {
    await goto(op, panelUrl('view=errors&severity=warning'));
    check((await screen(op)) === 'challenge' && (await op.locator('[data-sa-screen="challenge"] .lead-in').textContent()).includes('filtros'), 'filters notice missing');
    return 'view=errors&severity=warning';
  });

  // ---------- recovery code → new factor ----------
  let secret2;
  await step('Recovery code (with password) → new QR → new TOTP → new codes → filters applied', async () => {
    await op.locator('[data-sa-screen="challenge"] details > summary').click();
    await submit(op, 'recover', { '#sa-recover-password': operator.password, '#sa-recover-code': codes[0].toUpperCase().replace(/(.{8})/g, '$1-').replace(/-$/, '') });
    const qr = await readQr(op);
    secret2 = qr.otp.secret;
    check(secret2 !== secret, 'recovery kept the old secret');
    await submit(op, 'enrollment/confirm', { '#sa-setup-code': await freshCode(secret2) });
    await op.locator('[data-sa-screen="codes"]').waitFor();
    const fresh = await op.locator('[data-sa-codes] code').allTextContents();
    check(fresh.length === 10 && fresh.every((c) => !codes.includes(c)), 'recovery codes not replaced');
    codes = fresh;
    await op.check('#sa-codes-ack');
    const list = listing(op, 'errors');
    await reloads(op, () => op.locator('form[data-sa-action="codes-ack"] button').click());
    const json = await (await list).json();
    check((await op.inputValue('#sa-severity')) === 'warning' && json.items.every((i) => i.severity === 'warning'), 'filters lost after recovery');
    return { newSecret: true, codesReplaced: 10, view: 'errors', severity: 'warning' };
  });

  await step('Replace factor (password + current TOTP) → new QR → confirm → authorized', async () => {
    await op.locator('details.account > summary').click();
    await submit(op, 'replace-factor', { '#sa-replace-password': operator.password, '#sa-replace-code': await freshCode(secret2) });
    const qr = await readQr(op);
    check(qr.otp.secret !== secret2 && qr.otp.secret !== secret, 'replacement kept a previous secret');
    const secret3 = qr.otp.secret;
    await submit(op, 'enrollment/confirm', { '#sa-setup-code': await freshCode(secret3) });
    await op.locator('[data-sa-screen="codes"]').waitFor();
    await op.check('#sa-codes-ack');
    await reloads(op, () => op.locator('form[data-sa-action="codes-ack"] button').click());
    await records(op);
    secret = secret3;
    return { secrets: 3, distinct: true };
  });

  // ---------- second operator: redisplay and try-later ----------
  const twoCtx = await newContext();
  const two = watch(await twoCtx.newPage());
  await step('Second operator (subscriber role): wrong password, QR redisplay after reload keeps the same pending secret', async () => {
    await login(two, second);
    await submit(two, 'reconfirm', { 'input[name="password"]': 'wrong-password' });
    const wrong = await formStatus(two, 'reconfirm');
    check(wrong.includes('Não foi possível confirmar'), wrong);
    check((await two.getAttribute('#sa-password', 'aria-invalid')) === null && (await two.inputValue('#sa-password')) === '', 'secret kept in field');
    await reloads(two, () => submit(two, 'reconfirm', { 'input[name="password"]': second.password }));
    await submit(two, 'enrollment/begin');
    const first = await readQr(two);
    await goto(two, panelUrl());
    check((await screen(two)) === 'setup' && (await two.locator('[data-sa-qr]').isHidden()) && (await two.locator('[data-sa-redisplay]').isVisible()), 'redisplay form missing');
    await capture(two, 'setup-redisplay');
    await submit(two, 'redisplay', { '#sa-redisplay-password': second.password });
    const again = await readQr(two);
    check(again.otp.secret === first.otp.secret && (await two.locator('[data-sa-redisplay]').isHidden()), 'redisplay changed the pending secret');
    await submit(two, 'enrollment/confirm', { '#sa-setup-code': await freshCode(again.otp.secret) });
    await two.locator('[data-sa-screen="codes"]').waitFor();
    await two.check('#sa-codes-ack');
    await reloads(two, () => two.locator('form[data-sa-action="codes-ack"] button').click());
    check((await records(two)) !== 'error', 'second operator not authorized');
    return { wrongPassword: wrong.trim(), sameSecretAfterRedisplay: true };
  });

  await step('Attempt window: wrong TOTP codes are denied, then "try later" (429) without counters', async () => {
    await reloads(two, () => two.locator('form[data-sa-action="revoke"] button').click());
    const messages = [];
    for (let i = 0; i < 12; i++) {
      const response = two.waitForResponse((r) => decodeURIComponent(r.url()).includes('/authenticate'));
      await submit(two, 'authenticate', { '#sa-challenge-code': '000000' });
      const r = await response;
      messages.push(`${r.status()}`);
      await formStatus(two, 'authenticate');
      if (r.status() === 429) {
        const text = await formStatus(two, 'authenticate');
        check(text.includes('Muitas tentativas') && !/\d/.test(text), text);
        const body = await r.json();
        check(body.code === 'stealthaudit_try_later' && !r.headers()['retry-after'] && Object.keys(body.data).join() === 'status', JSON.stringify(body));
        break;
      }
    }
    check(messages.at(-1) === '429', `no 429: ${messages}`);
    await capture(two, 'challenge-try-later');
    return { statuses: messages.join(',') };
  });

  await step('Outsider (logged in, not allowlisted): page 403 and REST 403 without data', async () => {
    const ctx = await newContext();
    const page = watch(await ctx.newPage());
    await page.goto(panelUrl());
    await page.fill('#user_login', outsider.login);
    await page.fill('#user_pass', outsider.password);
    const [response] = await Promise.all([page.waitForResponse((r) => r.url().includes('stealthaudit=panel') && r.request().resourceType() === 'document'), page.click('#wp-submit')]);
    const text = await page.textContent('body');
    check(response.status() === 403 && text.includes('Acesso negado') && !(await page.locator('[data-sa-root]').count()), `outsider page ${response.status()}`);
    const anonymous = await ctx.request.get(`${BASE}/?rest_route=/stealthaudit/v1/events`);
    // WordPress's own endpoint hands a logged-in user a wp_rest nonce; with it the cookie identifies the outsider.
    const restNonce = (await (await ctx.request.get(`${BASE}/wp-admin/admin-ajax.php?action=rest-nonce`)).text()).trim();
    const rest = await ctx.request.get(`${BASE}/?rest_route=/stealthaudit/v1/events`, { headers: { 'X-WP-Nonce': restNonce } });
    const json = await rest.json();
    const asset = await ctx.request.get(`${BASE}/?stealthaudit=asset&file=panel.css`);
    check(anonymous.status() === 401 && rest.status() === 403 && json.code === 'stealthaudit_forbidden' && !('items' in json), `outsider REST ${anonymous.status()}/${rest.status()}`);
    check(asset.status() === 404, 'outsider got an asset');
    await ctx.close();
    return { page: 403, restWithoutWpNonce: 401, restWithWpNonce: 403, asset: 404 };
  });

  await step('Backend unavailable (key removed from config): page 503 styled for the operator, REST 503, no data', async () => {
    await goto(op, panelUrl());
    const nonces = await op.evaluate(() => { const r = document.querySelector('[data-sa-root]'); return { 'X-StealthAudit-Nonce': r.getAttribute('data-nonce'), 'X-WP-Nonce': r.getAttribute('data-rest-nonce') }; });
    fixture('config', { key: 'missing' });
    try {
      const page = await opCtx.newPage();
      const response = await page.goto(panelUrl());
      const body = await page.content();
      const out = { page: response.status() };
      for (const route of ['status', 'events']) {
        const r = await opCtx.request.get(`${BASE}/?rest_route=/stealthaudit/v1/${route}`, { headers: nonces });
        const json = await r.json();
        check(r.status() === 503 && json.code === 'stealthaudit_unavailable' && !('items' in json), `${route} ${r.status()}`);
        out[route] = r.status();
      }
      check(response.status() === 503 && body.includes('Painel indisponível') && body.includes('stealthaudit=asset') && !body.includes('data-sa-root') && response.headers()['cache-control'].includes('no-store'), `page ${response.status()}`);
      await capture(page, 'unavailable');
      await page.close();
      return out;
    } finally {
      fixture('config', {});
    }
  });

  await step('WordPress theme switch (default → twentytwentyfive → twentytwentythree): panel HTML identical', async () => {
    const normalize = (html) => html.replace(/data-(rest-)?nonce="[^"]+"/g, 'data-$1nonce=""');
    const pages = {};
    for (const slug of ['restore', 'twentytwentyfive', 'twentytwentythree']) {
      fixture('theme', { slug });
      const r = await opCtx.request.get(panelUrl());
      pages[slug] = normalize(await r.text());
      check(r.status() === 200 && !pages[slug].includes('/wp-content/themes/'), `${slug}: theme asset in panel`);
    }
    fixture('theme', { slug: 'restore' });
    check(pages.restore === pages.twentytwentyfive && pages.restore === pages.twentytwentythree, 'panel HTML changed with the theme');
    return { compared: 3, bytes: pages.restore.length };
  });

  await step('Pretty permalinks served by Apache: /stealthaudit/ beats a page with the same slug; REST under /wp-json/', async () => {
    wp('post', 'create', '--post_type=page', '--post_status=publish', '--post_title=E2E slug collision', '--post_name=stealthaudit');
    fixture('permalinks', { structure: '/%postname%/' });
    const page = await opCtx.newPage();
    const list = listing(page, 'events');
    const response = await page.goto(`${BASE}/stealthaudit/`);
    await ready(page);
    const json = await (await list).json();
    const links = await page.evaluate(() => [...document.querySelectorAll('.tabs a')].map((a) => a.getAttribute('href')));
    const rest = await page.getAttribute('[data-sa-root]', 'data-rest');
    check(response.status() === 200 && Array.isArray(json.items) && links.every((l) => l.startsWith(`${BASE}/stealthaudit/`)), `pretty ${response.status()} ${links}`);
    check(rest === `${BASE}/wp-json/stealthaudit/v1/`, rest);
    const inspect = JSON.parse(fixture('inspect'));
    await page.close();
    return { status: 200, tabs: links.map((l) => l.replace(BASE, '')), rest: rest.replace(BASE, ''), rules: inspect.rules };
  });

  await step('Endpoint changed in config (/painel-ops): leased one-time flush, new links, old path no longer the panel', async () => {
    fixture('config', { endpoint: '/painel-ops' });
    try {
      const page = await opCtx.newPage();
      const response = await page.goto(`${BASE}/painel-ops/`);
      await ready(page);
      const html = await page.content();
      const paths = [...html.matchAll(/(?:href|action|data-rest|data-panel)="([^"]+)"/g)].map((m) => m[1]);
      check(response.status() === 200 && html.includes('data-sa-root') && !paths.some((p) => /\/stealthaudit\/(?!v1)/.test(p)), `new endpoint ${response.status()}`);
      const old = await opCtx.request.get(`${BASE}/stealthaudit/`);
      check(!(await old.text()).includes('data-sa-root'), 'old path still serves the panel');
      const inspect = JSON.parse(fixture('inspect'));
      check(inspect.rules.some((r) => r.split(String.fromCharCode(92)).join('').includes('painel-ops')) && inspect.new_state.includes('lease'), JSON.stringify(inspect));
      await page.close();
      return { status: 200, oldPath: old.status(), rules: inspect.rules, lease: inspect.new_state.filter((k) => k === 'lease').length };
    } finally {
      fixture('config', {});
      fixture('permalinks', { structure: '' });
    }
  });

  await step('Plain permalinks: /?stealthaudit=panel serves the panel, /stealthaudit/ does not', async () => {
    const plain = await opCtx.request.get(panelUrl());
    const pretty = await opCtx.request.get(`${BASE}/stealthaudit/`, { maxRedirects: 0 });
    check(plain.status() === 200 && !(await pretty.text()).includes('data-sa-root'), 'plain fallback');
    return { fallback: 200, friendly: pretty.status() };
  });

  await step('WordPress in a subdirectory (/blog, served by Apache): login, panel, assets and REST under /blog', async () => {
    fixture('subdir', { state: 'on' });
    fixture('config', { home: `${BASE}/blog` });
    const ctx = await newContext();
    try {
      const page = watch(await ctx.newPage());
      await login(page, operator, `${BASE}/blog/?stealthaudit=panel`);
      check(page.url().startsWith(`${BASE}/blog/?stealthaudit=panel`) && (await screen(page)) === 'challenge', `subdir ${page.url()} ${await screen(page)}`);
      const styled = await page.evaluate(() => getComputedStyle(document.body).fontFamily.includes('monospace'));
      const status = await api(page, 'GET', 'status');
      const urls = await page.evaluate(() => [...document.querySelectorAll('link[rel=stylesheet], script[src], a[href], [data-rest]')].map((n) => n.href || n.src || n.getAttribute('data-rest')).filter((u) => u.startsWith('http')));
      check(styled && status.status === 200 && urls.every((u) => u.startsWith(`${BASE}/blog/`)), JSON.stringify(urls));
      fixture('permalinks', { structure: '/%postname%/' });
      const pretty = await page.goto(`${BASE}/blog/stealthaudit/`);
      await ready(page);
      const prettyStatus = await api(page, 'GET', 'status');
      check(pretty.status() === 200 && (await screen(page)) === 'challenge' && prettyStatus.status === 200, 'subdir pretty');
      return { plain: `/blog/?stealthaudit=panel → ${status.json.status}`, pretty: `/blog/stealthaudit/ → ${prettyStatus.json.status}`, rest: (await page.getAttribute('[data-sa-root]', 'data-rest')).replace(BASE, ''), assets: urls.filter((u) => u.includes('asset')).length };
    } finally {
      await ctx.close();
      fixture('permalinks', { structure: '' });
      fixture('config', {});
      fixture('subdir', { state: 'off' });
    }
  });

  await step('WordPress session revoked while the panel is open → "Sessão do WordPress encerrada"', async () => {
    let release;
    const held = new Promise((r) => { release = r; });
    await op.route((u) => decodeURIComponent(u.toString()).includes('/stealthaudit/v1/events'), async (route) => { await held; await route.continue(); });
    const navigation = op.goto(panelUrl());
    await navigation;
    wp('user', 'session', 'destroy', String(operator.id), '--all');
    release();
    await op.waitForFunction(() => !document.querySelector('[data-sa-screen="session"]')?.hidden);
    await op.unrouteAll();
    const login = await op.getAttribute('[data-sa-screen="session"] a', 'href');
    check(new URL(login).pathname === '/wp-login.php' && (await op.locator('[data-sa-rows] tr').count()) === 0, login);
    await capture(op, 'session');
    return { login: login.replace(BASE, '').slice(0, 60) + '…' };
  });

  await step('Shared cache in front: no panel, asset, QR, codes or REST response stored; control file cached', async () => {
    await req.get(`${BASE}/readme.html`);
    await req.get(`${BASE}/readme.html`);
    const ours = proxy.log.filter((e) => /stealthaudit|rest_route|wp-json/.test(e.path));
    const control = proxy.log.filter((e) => e.path === '/readme.html');
    check(ours.length > 50 && ours.every((e) => !e.storable && e.cache === 'MISS'), `stored: ${JSON.stringify(ours.filter((e) => e.storable).slice(0, 3))}`);
    check(control.some((e) => e.cache === 'HIT'), 'control not cached');
    const statuses = {};
    for (const e of ours) statuses[e.status] = (statuses[e.status] || 0) + 1;
    return { privateResponses: ours.length, stored: 0, byStatus: statuses, control: control.map((e) => e.cache).join('/') };
  });

  await step('Reduced motion: transitions collapse to 0.01 ms under prefers-reduced-motion', () => reducedMotion(browser));

  await step('No CSP violation, script error or dialog in any panel page', () => {
    check(!problems.length, problems.join(' | '));
    return { problems: 0 };
  });

  results.push({ id: 'evidence', geometry, focusAudit, contrastRatios, mfaEvents, proxyRequests: proxy.log.length });
  console.log(`\nfocus order (audit): ${focusAudit.join(' → ')}`);
  console.log(`contrast: ${JSON.stringify(contrastRatios)}`);
  console.log(`mfa events written: ${mfaEvents.join(', ')}`);
} catch {
  process.exitCode = 1;
} finally {
  await browser?.close().catch(() => {});
  await proxy?.close().catch(() => {});
  await attacker?.close().catch(() => {});
  // Setup may fail before writing the snapshot; then there is nothing to inspect or restore.
  if (setUp && existsSync(join(OUT, 'snapshot.json'))) {
    try {
      const inspect = JSON.parse(fixture('inspect'));
      results.push({ id: 'teardown-inspect', secretInEvents: inspect.secret_in_event, newEvents: inspect.new_events, newErrors: inspect.new_errors });
      console.log(fixture('teardown'));
      console.log(fixture('restore'));
    } catch (failure) {
      console.log(`TEARDOWN FAILED: ${failure.message}. Run: ${RECOVER}`);
      process.exitCode = 1;
    }
  }
  writeFileSync(join(OUT, 'results.json'), JSON.stringify(results, null, 2));
  const failed = results.filter((r) => r.ok === false).length;
  console.log(`\n${results.filter((r) => r.ok).length} passed, ${failed} failed`);
  if (failed) process.exitCode = 1;
}
