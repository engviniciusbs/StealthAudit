// SPDX-License-Identifier: GPL-3.0-or-later
// Development only: authenticate one synthetic operator in the benchmark site through the real
// flow (wp-login form, password reconfirmation, QR decoded independently, RFC 6238 TOTP, codes
// acknowledged) and time warm panel loads. Reuses the e2e helpers; secrets stay in memory.
import { setTimeout as sleep } from 'node:timers/promises';
import { chromium } from 'playwright-core';
import { base32, decodeQr, parseOtpauth, totp } from '../../tests/e2e/otp.mjs';
import { startProxy } from '../../tests/e2e/proxy.mjs';

const BASE = 'https://localhost:8443';
const panelUrl = (query = '') => `${BASE}/?stealthaudit=panel${query ? `&${query}` : ''}`;

export async function openPanel({ httpPort, user }) {
  const proxy = await startProxy({ upstream: { host: '127.0.0.1', port: httpPort } });
  const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : { channel: 'chrome' }) });
  const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 900 }, locale: 'pt-BR' });
  const page = await context.newPage();
  let secret = null;
  let usedStep = 0;

  const screen = () => page.evaluate(() => [...document.querySelectorAll('[data-sa-screen]')].filter((s) => !s.hidden).map((s) => s.getAttribute('data-sa-screen')).join(','));
  const ready = async () => {
    await page.waitForLoadState('load');
    await page.waitForFunction(() => !document.querySelector('fieldset[data-sa-js][disabled]') || !document.querySelector('[data-sa-root]'));
  };
  const reloads = async (action) => {
    const loaded = page.waitForEvent('load');
    await action();
    await loaded;
    await ready();
  };
  const submit = async (action, fields = {}) => {
    const form = page.locator(`form[data-sa-action="${action}"]`).first();
    for (const [selector, value] of Object.entries(fields)) await form.locator(selector).fill(value);
    await form.locator('button[type="submit"]').click();
  };
  const records = async () => {
    await page.waitForFunction(() => ['full', 'empty', 'error'].includes(document.querySelector('[data-sa-records]')?.getAttribute('data-state')), null, { timeout: 120_000 });
    return page.evaluate(() => document.querySelector('[data-sa-records]').getAttribute('data-state'));
  };
  const freshCode = async () => {
    let now = Date.now() / 1000;
    if (Math.floor(now / 30) <= usedStep) {
      await sleep(((usedStep + 1) * 30 - now + 1) * 1000);
      now = Date.now() / 1000;
    }
    usedStep = Math.floor(now / 30);
    return totp(base32(secret), now);
  };

  async function enroll() {
    await page.goto(panelUrl());
    await page.fill('#user_login', user.login);
    await page.fill('#user_pass', user.password);
    await Promise.all([page.waitForURL((u) => !u.pathname.endsWith('wp-login.php')), page.click('#wp-submit')]);
    await ready();
    if ((await screen()) !== 'password') throw new Error(`panel: expected the password screen, got ${await screen()}`);
    await reloads(() => submit('reconfirm', { 'input[name="password"]': user.password }));
    await submit('enrollment/begin');
    const box = page.locator('[data-sa-qr]');
    await box.locator('img').waitFor();
    await page.waitForFunction(() => document.querySelector('[data-sa-qr] img')?.complete);
    secret = parseOtpauth(decodeQr(await box.screenshot()).text).secret;
    await submit('enrollment/confirm', { '#sa-setup-code': await freshCode() });
    await page.locator('[data-sa-screen="codes"]').waitFor();
    await page.check('#sa-codes-ack');
    await reloads(() => page.locator('form[data-sa-action="codes-ack"] button').click());
    if ((await records()) !== 'full') throw new Error('panel: records not shown after enrollment');
  }

  // A proof can expire (30 min idle) while a large size is being seeded: answer the challenge.
  async function authorized() {
    await page.goto(panelUrl('view=audit'));
    await ready();
    const current = await screen();
    if (current === 'challenge') {
      await submit('authenticate', { '#sa-challenge-code': await freshCode() });
      await records();
    } else if (current !== 'workspace') {
      throw new Error(`panel: unexpected screen ${current}`);
    }
  }

  // Warm page loads: navigation start to rows rendered, plus the REST call measured by the browser.
  async function measure(query, { warmup = 3, reps = 15, expect = 'full' } = {}) {
    await authorized();
    const total = [];
    const rest = [];
    let state = null;
    let rows = 0;
    for (let i = 0; i < warmup + reps; i++) {
      const started = performance.now();
      await page.goto(panelUrl(query));
      state = await records();
      const elapsed = performance.now() - started;
      const timing = await page.evaluate(() => {
        const entry = performance.getEntriesByType('resource').filter((e) => e.name.includes('stealthaudit%2Fv1%2F') || e.name.includes('stealthaudit/v1/')).at(-1);
        return { rest: entry ? entry.responseEnd - entry.requestStart : null, rows: document.querySelectorAll('[data-sa-rows] tr').length };
      });
      rows = timing.rows;
      if (state !== expect) throw new Error(`panel: ${query} rendered "${state}"`);
      if (i >= warmup) {
        total.push(elapsed);
        if (timing.rest !== null) rest.push(timing.rest);
      }
    }
    return { query, rows, total_ms: stats(total), rest_ms: stats(rest) };
  }

  return {
    enroll,
    measure,
    close: async () => {
      await browser.close();
      await proxy.close();
    },
  };
}

export function stats(samples) {
  const sorted = [...samples].sort((a, b) => a - b);
  const at = (q) => Math.round(sorted[Math.min(sorted.length - 1, Math.max(0, Math.ceil(q * sorted.length) - 1))] * 1000) / 1000;
  return { n: sorted.length, mean: Math.round((sorted.reduce((a, b) => a + b, 0) / Math.max(1, sorted.length)) * 1000) / 1000, p50: at(0.5), p95: at(0.95), p99: at(0.99), max: at(1) };
}
