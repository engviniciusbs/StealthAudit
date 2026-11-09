// SPDX-License-Identifier: GPL-3.0-or-later
// Development only: build, verify and INSTALL the distribution ZIP into a clean, isolated
// WordPress, then run the operator flow on the artifact (phase 8c).
//
//   npm run test:package [-- --allow-dirty] [--keep] [--skip-e2e]
//   (development iteration only: --fast skips the rebuild and tamper checks; --reuse-env wipes
//   the StealthAudit install, tables and test users of a site kept with --keep instead of a new one)
//
// Needs Docker, the wp-env test environment (8880: its CLI container is the PHP 8.3 + Composer
// build host for `npm run package`), OpenSSL, local Chrome and network on the first run. Creates
// ONE extra environment (tools/wp-env.cjs --matrix=8.3,mariadb:10.11.19,port=8883,tag=artifact,
// source=none): WordPress 7.1.4 without the repository mapped and without Composer or Node,
// destroyed at the end (unless --keep). Never touches the 8870/8880 sites' data.
//
//  1 build A (dist/) and B (test-only version marker), rebuild A to prove determinism; verify
//    both; tampered copies of A must be rejected; build the coexistence fixture
//  2 start the clean site; no Composer/Node on it; install A (core first, loader last) and prove
//    the installed files are byte-identical to the ZIP content list
//  3 package smoke (QR/TOTP/cipher/GeoIP from the scoped vendor) and the full browser E2E
//    (tests/e2e/run.mjs: HTTPS proxy, pretty permalinks via Apache, TOTP enrollment, filters,
//    expiration/revocation, recovery, maintenance...) against the ARTIFACT
//  4 another plugin with different versions of the same libraries, loaded before and after:
//    smoke both orders, then the full E2E again with it loaded first
//  5 operator flow with the preferred external config file and pretty permalinks: enroll, actions
//    and errors, filtered queries, expiration and revocation, retention, update A -> B by directory
//    swap (data, proofs and config preserved), the packaged operator CLI on the Composer-free
//    web container (refusal, key rotation, validation, reset) and the CLI unreachable over HTTP
//  6 Plugin Check (support only) on a copy of the package; Apache log scan; destroy
import { execFileSync, spawnSync } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync, readFileSync, rmSync, statfsSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { randomBytes } from 'node:crypto';
import http from 'node:http';
import { setTimeout as sleep } from 'node:timers/promises';
import { chromium } from 'playwright-core';
import { base32, decodeQr, parseOtpauth, totp } from '../e2e/otp.mjs';
import { startProxy } from '../e2e/proxy.mjs';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = join(ROOT, 'artifacts/package-e2e');
const argv = process.argv.slice(2);
const flag = (name) => argv.includes(`--${name}`);
const PORT = 8883;
const SPEC = `8.3,mariadb:10.11.19,port=${PORT},tag=artifact,source=none`;
const SLUG = 'php83-mariadb101119-artifact';
const ENV_PREFIX = `wp-env-stealthaudit-matrix-${SLUG}`;
const HARNESS = '/opt/stealthaudit-dev';
const WP = '/var/www/html';
const MU = `${WP}/wp-content/mu-plugins`;
const BASE = 'https://localhost:8443';
const PLUGIN_CHECK = '2.1.0';
const env = { ...process.env, MSYS_NO_PATHCONV: '1' };
const results = { started: new Date().toISOString(), steps: [] };
let last = 0;

// ---------- infrastructure ----------
function check(condition, message) {
  if (!condition) throw new Error(message);
}
// Written after every step, so an interrupted run (e.g. Docker going down) still leaves a record.
function saveResults() {
  writeFileSync(join(OUT, 'results.json'), JSON.stringify(results, null, 2) + '\n');
}
async function step(title, fn) {
  const started = Date.now();
  const id = ++last;
  try {
    const detail = await fn();
    results.steps.push({ id, title, ok: true, ms: Date.now() - started, detail: detail ?? null });
    saveResults();
    console.log(`ok ${id} - ${title}${detail ? ` (${typeof detail === 'string' ? detail : JSON.stringify(detail)})` : ''}`);
    return detail;
  } catch (failure) {
    results.steps.push({ id, title, ok: false, ms: Date.now() - started, detail: String(failure.message || failure) });
    saveResults();
    console.log(`not ok ${id} - ${title}: ${failure.message || failure}`);
    throw failure;
  }
}
function freeGiB() {
  const s = statfsSync(ROOT);
  return (s.bavail * s.bsize) / 2 ** 30;
}
function guardDisk(where) {
  const free = freeGiB();
  if (free < 8) throw new Error(`only ${free.toFixed(1)} GiB free on the workspace disk (${where}); stopping before Docker fills it`);
  return Math.round(free * 10) / 10;
}
function node(args, log) {
  const run = spawnSync(process.execPath, args, { cwd: ROOT, env, encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 });
  const output = `${run.stdout}${run.stderr}`;
  if (log) writeFileSync(join(OUT, log), output);
  return { ok: run.status === 0, output };
}
function names(filter) {
  return execFileSync('docker', ['ps', '--filter', `name=${filter}`, '--format', '{{.Names}}'], { env }).toString().split(/\s+/).filter(Boolean);
}
function container(prefix, service) {
  const exact = new RegExp(`^${prefix}-[0-9a-f]{8}-${service}-1$`);
  const name = names(prefix).find((n) => exact.test(n));
  if (!name) throw new Error(`${prefix}: ${service} container not running`);
  return name;
}
function dexec(name, command, { user, input, workdir, vars = {}, allowFail = false } = {}) {
  const args = ['exec', '-i'];
  if (user) args.push('-u', user);
  if (workdir) args.push('-w', workdir);
  for (const [k, v] of Object.entries(vars)) args.push('-e', `${k}=${v}`);
  const run = spawnSync('docker', [...args, name, ...command], { env, input: input ?? '', encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  if (!allowFail && run.status !== 0) throw new Error(`${command.join(' ')} failed (${run.status}): ${`${run.stdout}${run.stderr}`.trim().slice(0, 2000)}`);
  return { status: run.status, stdout: run.stdout.trim(), stderr: run.stderr.trim() };
}
const wpEnv = (args, log) => node(['tools/wp-env.cjs', `--matrix=${SPEC}`, ...args], log);
let CLI, WEB, USER, HOME;
const wp = (...args) => dexec(CLI, ['wp', `--path=${WP}`, ...args], { workdir: HARNESS }).stdout;
const flow = (action, input) => JSON.parse(wp('eval-file', `${HARNESS}/tests/package/flow.php`, action, ...(input ? [JSON.stringify(input)] : [])).split('\n').pop());
const web = (script, options = {}) => dexec(WEB, ['sh', '-c', script], { user: USER, ...options });
const toContainer = (hostPath, name, target) => execFileSync('docker', ['cp', hostPath, `${name}:${target}`], { env });

// Installation exactly as docs/operations/installation.md: unpack outside the webroot, copy the
// core directory first and the loader LAST by rename (an update swaps whole directories). The
// unpack keeps each entry's time like `unzip` and the copies use `cp -a` like `rsync -a`, so the
// installed files carry the ZIP's times (the commit time): the case where PHP's opcache can keep
// serving the previous version, which the update step checks.
function install(zip, { update = false } = {}) {
  const unpack = `/tmp/stealthaudit-unpack-${randomBytes(4).toString('hex')}`;
  toContainer(zip, WEB, '/tmp/stealthaudit.zip');
  dexec(WEB, ['chmod', '0644', '/tmp/stealthaudit.zip']);
  web(`php -r '$z = new ZipArchive(); if (true !== $z->open($argv[1]) || !$z->extractTo($argv[2])) { exit(1); } for ($i = 0; $i < $z->numFiles; $i++) { $s = $z->statIndex($i); $f = $argv[2] . "/" . $s["name"]; if (is_file($f) && !touch($f, $s["mtime"])) { exit(1); } }' /tmp/stealthaudit.zip ${unpack}`);
  if (update) {
    web(`set -e; cd ${MU}; rm -rf stealthaudit.new stealthaudit.old; cp -a ${unpack}/mu-plugins/stealthaudit stealthaudit.new; mv stealthaudit stealthaudit.old; mv stealthaudit.new stealthaudit; cp -a ${unpack}/mu-plugins/stealthaudit.php stealthaudit.php.new; mv -f stealthaudit.php.new stealthaudit.php`);
  } else {
    web(`set -e; mkdir -p ${MU}; cd ${MU}; test ! -e stealthaudit; cp -a ${unpack}/mu-plugins/stealthaudit stealthaudit; cp -a ${unpack}/mu-plugins/stealthaudit.php stealthaudit.php.new; mv -f stealthaudit.php.new stealthaudit.php`);
  }
  web(`rm -rf ${unpack}`);
  dexec(WEB, ['rm', '-f', '/tmp/stealthaudit.zip']); // docker cp wrote it as root
}
// Every installed file hashes as in the build's content list, and nothing else is installed.
function matchesContents(contents) {
  const listed = readFileSync(contents, 'utf8').trim().split('\n').map((line) => line.split(/\s+/));
  const installed = web(`cd ${WP}/wp-content && find mu-plugins/stealthaudit.php mu-plugins/stealthaudit -type f -exec sha256sum {} +`).stdout.split('\n').map((line) => line.trim().split(/\s+/));
  const want = new Map(listed.map(([sha, , path]) => [path, sha]));
  const have = new Map(installed.map(([sha, path]) => [path, sha]));
  const wrong = [...want].filter(([path, sha]) => have.get(path) !== sha).map(([path]) => path);
  const extra = [...have.keys()].filter((path) => !want.has(path));
  check(wrong.length === 0 && extra.length === 0, `installed tree differs from the ZIP: ${JSON.stringify({ wrong: wrong.slice(0, 5), extra: extra.slice(0, 5) })}`);
  return want.size;
}
function e2e(log) {
  const run = spawnSync(process.execPath, ['tests/e2e/run.mjs'], { cwd: ROOT, env: { ...env, STEALTHAUDIT_E2E_ENV: ENV_PREFIX, STEALTHAUDIT_E2E_HTTP_PORT: String(PORT), STEALTHAUDIT_E2E_WORKDIR: HARNESS }, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  const output = `${run.stdout}${run.stderr}`;
  writeFileSync(join(OUT, `${log}.log`), output);
  if (existsSync(join(ROOT, 'artifacts/e2e/results.json'))) copyFileSync(join(ROOT, 'artifacts/e2e/results.json'), join(OUT, `${log}-results.json`));
  const summary = (output.match(/^\d+ passed, \d+ failed.*$/m) || ['no summary'])[0];
  // Diagnostics the fixture/plugin printed through WP-CLI (display_errors=stderr) from the package.
  const own = (output.match(/^.*(?:Deprecated|Warning|Notice|Fatal error|Parse error):.*$/gm) || []).filter((line) => line.includes('/mu-plugins/stealthaudit'));
  check(run.status === 0 && own.length === 0, `${summary}${own.length ? `; ${own.length} StealthAudit PHP diagnostics` : ''}; see artifacts/package-e2e/${log}.log`);
  return summary;
}
const smoke = (conflict) => JSON.parse(wp('eval-file', `${HARNESS}/tests/package/smoke.php`, JSON.stringify({ mmdb: `${HARNESS}/artifacts/geoip/GeoIP2-City-Test.mmdb`, conflict })).split('\n').pop());
// Operator config file from the PACKAGED example, outside the webroot (user-home volume shared
// by the web and CLI containers), mode 0640.
function writeConfig(file, values) {
  let text = dexec(WEB, ['cat', `${MU}/stealthaudit/config/stealthaudit.example.php`]).stdout + '\n';
  for (const [key, value] of Object.entries(values)) {
    const re = new RegExp(`^(\\t'${key}'\\s*=> ).*?,( //.*)?$`, 'm');
    check(re.test(text), `example config has no ${key} line`);
    text = text.replace(re, `$1${value},$2`);
  }
  dexec(CLI, ['sh', '-c', `umask 027; mkdir -p "$(dirname '${file}')"; cat > '${file}.tmp' && mv -f '${file}.tmp' '${file}'`], { input: text });
}
const php = (value) => (Array.isArray(value) ? `array( ${value.join(', ')} )` : `'${value}'`);
function operatorCli(action, cursor, extra = {}) {
  // The web container: PHP of the WordPress host, no Composer, the package's own bin/.
  return dexec(WEB, ['php', `${MU}/stealthaudit/bin/security-operator.php`, action, String(cursor)], { user: USER, vars: { STEALTHAUDIT_WORDPRESS_ROOT: WP, ...extra }, allowFail: true });
}

// ---------- browser helpers (same contract as tests/e2e/run.mjs) ----------
let usedStep = 0;
async function freshCode(secret) {
  let now = Date.now() / 1000;
  if (Math.floor(now / 30) <= usedStep) {
    await sleep(((usedStep + 1) * 30 - now + 1) * 1000);
    now = Date.now() / 1000;
  }
  usedStep = Math.floor(now / 30);
  return totp(base32(secret), now);
}
const screen = (page) => page.evaluate(() => [...document.querySelectorAll('[data-sa-screen]')].filter((s) => !s.hidden).map((s) => s.getAttribute('data-sa-screen')).join(','));
async function ready(page) {
  await page.waitForLoadState('load');
  await page.waitForFunction(() => !document.querySelector('fieldset[data-sa-js][disabled]') || !document.querySelector('[data-sa-root]'));
}
async function records(page) {
  await page.waitForFunction(() => ['full', 'empty', 'error'].includes(document.querySelector('[data-sa-records]')?.getAttribute('data-state')));
  return page.evaluate(() => document.querySelector('[data-sa-records]').getAttribute('data-state'));
}
async function submit(page, action, fields = {}) {
  const form = page.locator(`form[data-sa-action="${action}"]`).first();
  for (const [selector, value] of Object.entries(fields)) await form.locator(selector).fill(value);
  await form.locator('button[type="submit"]').click();
}
async function reloads(page, action) {
  const loaded = page.waitForEvent('load');
  await action();
  await loaded;
  await ready(page);
}
async function login(page, user, start) {
  await page.goto(start);
  check(page.url().includes('/wp-login.php'), `expected wp-login redirect, got ${page.url()}`);
  await page.fill('#user_login', user.login);
  await page.fill('#user_pass', user.password);
  await Promise.all([page.waitForURL((u) => !u.pathname.endsWith('wp-login.php')), page.click('#wp-submit')]);
  await ready(page);
}
async function readQr(page) {
  const box = page.locator('[data-sa-qr]');
  await box.locator('img').waitFor();
  await page.waitForFunction(() => document.querySelector('[data-sa-qr] img')?.complete);
  return parseOtpauth(decodeQr(await box.screenshot()).text);
}
function api(page, route, params = {}) {
  return page.evaluate(async ({ route, params }) => {
    const root = document.querySelector('[data-sa-root]');
    const url = new URL(root.getAttribute('data-rest') + route, location.href);
    for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
    const r = await fetch(url, { headers: { Accept: 'application/json', 'X-StealthAudit-Nonce': root.getAttribute('data-nonce'), 'X-WP-Nonce': root.getAttribute('data-rest-nonce') }, credentials: 'same-origin', cache: 'no-store' });
    return { status: r.status, json: await r.json().catch(() => null) };
  }, { route, params });
}

// ---------- run ----------
rmSync(OUT, { recursive: true, force: true });
mkdirSync(OUT, { recursive: true });
const dirty = flag('allow-dirty') ? ['--allow-dirty'] : [];
const version = JSON.parse(readFileSync(join(ROOT, 'package.json'), 'utf8')).version;
const marker = `${version}+update`;
const A = { zip: join(ROOT, `dist/stealthaudit-${version}.zip`) };
const B = { zip: join(OUT, `update/stealthaudit-${marker}.zip`) };
let started = false;
let browser, proxy;
try {
  await step('Disk guard and build host (8880 CLI container: PHP 8.3 + Composer)', () => {
    const free = guardDisk('start');
    const host = container('wp-env-stealthaudit-tests', 'cli');
    results.buildHost = host;
    return { freeGiB: free, buildHost: host };
  });
  const HOST = results.buildHost;
  const onHost = (command) => dexec(HOST, command, { workdir: `${WP}/wp-content/mu-plugins/stealthaudit` });

  // ---------- 1. build and verify ----------
  await step('npm run package: package A (dist/) from the committed tree', () => {
    const run = node(['tools/package.mjs', ...dirty], 'package-a.log');
    check(run.ok, run.output.slice(-1500));
    A.sha = readFileSync(`${A.zip}.sha256`, 'utf8').split(/\s+/)[0];
    A.build = JSON.parse(readFileSync(A.zip.replace(/\.zip$/, '.build.json'), 'utf8'));
    return { zip: `dist/stealthaudit-${version}.zip`, sha256: A.sha, files: A.build.files, bytes: A.build.bytes, dirty: A.build.dirty };
  });
  if (!flag('fast')) await step('Same commit rebuilt: byte-identical ZIP (deterministic build)', () => {
    const run = node(['tools/package.mjs', ...dirty, '--out', 'artifacts/package-e2e/rebuild'], 'package-rebuild.log');
    check(run.ok, run.output.slice(-1500));
    const again = readFileSync(join(OUT, `rebuild/stealthaudit-${version}.zip.sha256`), 'utf8').split(/\s+/)[0];
    check(again === A.sha, `rebuild sha256 ${again} != ${A.sha}`);
    return again;
  });
  await step(`Package B with a test-only version marker (${marker})`, () => {
    const run = node(['tools/package.mjs', ...dirty, '--package-version', marker, '--out', 'artifacts/package-e2e/update'], 'package-b.log');
    check(run.ok, run.output.slice(-1500));
    B.sha = readFileSync(`${B.zip}.sha256`, 'utf8').split(/\s+/)[0];
    return { sha256: B.sha };
  });
  await step('npm run verify-package: A (also against the working tree) and B', () => {
    const a = onHost(['php', 'tools/verify-package.php', `dist/stealthaudit-${version}.zip`, '--against-source']);
    const b = onHost(['php', 'tools/verify-package.php', `artifacts/package-e2e/update/stealthaudit-${marker}.zip`]);
    writeFileSync(join(OUT, 'verify.log'), `${a.stdout}\n${b.stdout}\n`);
    check(a.stdout.endsWith('[verify] OK') && b.stdout.endsWith('[verify] OK'), 'verification failed');
    return a.stdout.split('\n').find((l) => l.startsWith('[verify] {'));
  });
  if (!flag('fast')) await step('Tampered copies of A are rejected by verify-package', () => {
    const run = onHost(['php', 'tests/package/verify-negative.php', `dist/stealthaudit-${version}.zip`]);
    writeFileSync(join(OUT, 'verify-negative.log'), run.stdout);
    return run.stdout.split('\n').pop();
  });
  await step('Coexistence fixture built from its lock (other versions of the same libraries)', () => {
    onHost(['sh', '-c', 'set -e; rm -rf /tmp/sa-conflict-build; mkdir -p /tmp/sa-conflict-build/sa-conflict; cp tests/package/conflict/composer.json tests/package/conflict/composer.lock /tmp/sa-conflict-build/sa-conflict/; cd /tmp/sa-conflict-build/sa-conflict; composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-plugins >/dev/null 2>&1; composer audit --locked >/dev/null; rm composer.json composer.lock; cd ..; tar -cf /var/www/html/wp-content/mu-plugins/stealthaudit/artifacts/package-e2e/conflict.tar sa-conflict; rm -rf /tmp/sa-conflict-build']);
    return 'artifacts/package-e2e/conflict.tar';
  });
  const geo = node(['tools/geoip-test-data.mjs']);
  check(geo.ok, 'GeoIP test data unavailable (network required on first run)');

  // ---------- 2. clean site, install A ----------
  await step(`Clean isolated WordPress on 127.0.0.1:${PORT} (repository not mapped, Composer removed)`, () => {
    guardDisk('before start');
    started = true;
    const start = wpEnv(['start'], 'start.log');
    check(start.ok, 'wp-env start failed; see artifacts/package-e2e/start.log');
    CLI = container(ENV_PREFIX, 'cli');
    WEB = container(ENV_PREFIX, 'wordpress');
    USER = execFileSync('docker', ['inspect', '--format', '{{.Config.User}}', CLI], { env }).toString().trim();
    HOME = dexec(CLI, ['sh', '-c', 'echo $HOME']).stdout;
    const tools = {};
    for (const [label, name] of [['web', WEB], ['cli', CLI]]) {
      tools[label] = dexec(name, ['sh', '-c', 'for t in composer node npm; do command -v $t || true; done; ls /home/*/.composer 2>/dev/null || true']).stdout;
      check(tools[label] === '', `${label} container still has ${tools[label]}`);
    }
    if (flag('reuse-env')) {
      results.reusedEnvironment = true;
      dexec(CLI, ['sh', '-c', `cd ${WP}; wp config delete STEALTHAUDIT_CONFIG_FILE >/dev/null 2>&1; rm -rf "$HOME/stealthaudit-config" wp-content/plugins/stealthaudit; for u in $(wp user list --field=user_login | grep '^sa-'); do wp user delete "$u" --yes >/dev/null; done; for t in $(wp db query "SHOW TABLES LIKE '%stealthaudit%'" --skip-column-names); do wp db query "DROP TABLE $t"; done; wp db query "DELETE FROM $(wp db prefix)options WHERE option_name LIKE 'stealthaudit%'"; wp cron event delete stealthaudit_maintenance >/dev/null 2>&1; wp rewrite structure '' >/dev/null; wp config set WP_HOME http://localhost:${PORT} --type=constant; wp config set WP_SITEURL http://localhost:${PORT} --type=constant; true`]);
      web(`rm -rf ${MU}/* ${MU}/.[!.]* 2>/dev/null; true`);
    }
    const mapped = dexec(WEB, ['sh', '-c', `ls -A ${MU} 2>/dev/null || true`]).stdout;
    check(mapped === '', `mu-plugins not empty before install: ${mapped}`);
    results.site = {
      wordpress: wp('core', 'version'),
      web_php: dexec(WEB, ['php', '-r', 'echo PHP_VERSION;']).stdout,
      web_extensions: dexec(WEB, ['php', '-r', 'echo implode(" ", array_intersect(get_loaded_extensions(), ["mysqli","json","sodium","iconv","xmlwriter","zip","curl","openssl","mbstring"]));']).stdout,
      database: wp('db', 'query', 'SELECT VERSION()', '--skip-column-names'),
      wp_cli: wp('--version'),
      free_gib: guardDisk('after start'),
    };
    return { ...results.site, composer: 'absent', node: 'absent' };
  });
  await step('Install package A as documented (core directory, then the loader by rename)', async () => {
    install(A.zip);
    const files = matchesContents(A.zip.replace(/\.zip$/, '.contents.txt'));
    const http = (await fetch(`http://127.0.0.1:${PORT}/`)).status;
    const snap = flow('snapshot');
    check(snap.boot === 'ready' && snap.version === version && snap.schema && snap.cron_next, JSON.stringify(snap));
    // `wp core install` (wp-env start) auto-detects mod_rewrite and may leave pretty permalinks on.
    results.initialPermalinks = wp('option', 'get', 'permalink_structure');
    return { installedFiles: files, identicalToZip: true, homepage: http, version: snap.version, schema: snap.schema, cronScheduled: true, initialPermalinks: results.initialPermalinks || 'plain' };
  });

  // ---------- 3. smoke + full E2E on the artifact ----------
  await step('Package smoke (no other plugin): classes, QR, TOTP + PSR clock, cipher, GeoIP from the scoped vendor', () => smoke('none'));
  if (!flag('skip-e2e')) {
    // tests/e2e/run.mjs starts from plain permalinks and switches to pretty ones (served by Apache)
    // and back itself; its restore returns the site to this state.
    wp('rewrite', 'structure', '');
    await step('Full browser E2E (tests/e2e/run.mjs) against the installed artifact', () => e2e('e2e-artifact'));
  }

  // ---------- 4. coexistence ----------
  await step('Another plugin with other versions of the same libraries, loaded BEFORE StealthAudit', () => {
    toContainer(join(OUT, 'conflict.tar'), WEB, '/tmp/sa-conflict.tar');
    dexec(WEB, ['chmod', '0644', '/tmp/sa-conflict.tar']);
    web(`set -e; cd ${MU}; tar -xf /tmp/sa-conflict.tar; cat > 0-sa-conflict.php`, { input: readFileSync(join(ROOT, 'tests/package/conflict/sa-conflict.php'), 'utf8') });
    return smoke('before');
  });
  await step('Same plugin loaded AFTER StealthAudit', () => {
    web(`cd ${MU} && mv 0-sa-conflict.php zz-sa-conflict.php`);
    const out = smoke('after');
    web(`cd ${MU} && mv zz-sa-conflict.php 0-sa-conflict.php`);
    return { fixture_classes: out.fixture_classes, foreign_clock: out.foreign_clock };
  });
  if (!flag('skip-e2e')) {
    await step('Full browser E2E again with the other plugin loaded first', () => e2e('e2e-coexistence'));
  }
  web(`cd ${MU} && rm -rf 0-sa-conflict.php zz-sa-conflict.php sa-conflict`);
  dexec(WEB, ['rm', '-f', '/tmp/sa-conflict.tar']);

  // ---------- 5. operator flow on the artifact ----------
  const active = `${HOME}/stealthaudit-config/stealthaudit.php`;
  const rotated = `${HOME}/stealthaudit-config/stealthaudit-rotated.php`;
  const keyA = randomBytes(32).toString('base64');
  const keyB = randomBytes(32).toString('base64');
  const installation = `pkg-${randomBytes(8).toString('hex')}`;
  const operator = { login: `sa-pkg-operator-${randomBytes(3).toString('hex')}`, password: `pkg-${randomBytes(12).toString('hex')}` };
  let page, secret;
  const panel = (query = '') => `${BASE}/stealthaudit/${query ? `?${query}` : ''}`;

  await step('Operator configuration: external file from the packaged example, STEALTHAUDIT_CONFIG_FILE, pretty permalinks', () => {
    operator.id = Number(wp('user', 'create', operator.login, `${operator.login}@example.test`, '--role=editor', `--user_pass=${operator.password}`, '--porcelain'));
    writeConfig(active, { authorized_user_ids: php([operator.id]), key_base64: php(keyA), key_id: php('pkg-a'), installation_id: php(installation) });
    wp('config', 'set', 'STEALTHAUDIT_CONFIG_FILE', active, '--type=constant');
    // The site's public address is the HTTPS one in front (the TLS proxy), as in production.
    for (const name of ['WP_HOME', 'WP_SITEURL']) wp('config', 'set', name, BASE, '--type=constant');
    wp('rewrite', 'structure', '/%postname%/');
    const mode = dexec(CLI, ['stat', '-c', '%a', active]).stdout;
    const htaccess = web(`test -f ${WP}/.htaccess && grep -c RewriteRule ${WP}/.htaccess || echo 0`).stdout;
    return { configFile: active.replace(HOME, '~'), mode, home: BASE, outsideWebroot: !active.startsWith(WP), permalinks: '/%postname%/', htaccessRewriteRules: Number(htaccess) };
  });

  proxy = await startProxy({ upstream: { host: '127.0.0.1', port: PORT } });
  browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : { channel: 'chrome' }) });
  const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 900 }, locale: 'pt-BR' });
  page = await context.newPage();

  await step('Pretty URL over HTTPS: login → password → QR (decoded independently) → TOTP → codes → audit view', async () => {
    const plain = (await fetch(`http://127.0.0.1:${PORT}/stealthaudit/`, { redirect: 'manual' })).status;
    check(plain === 403, `plain HTTP pretty URL gave ${plain}`);
    await login(page, operator, panel());
    check(new URL(page.url()).pathname === '/stealthaudit/' && (await screen(page)) === 'password', `landed on ${page.url()} / ${await screen(page)}`);
    await reloads(page, () => submit(page, 'reconfirm', { 'input[name="password"]': operator.password }));
    await submit(page, 'enrollment/begin');
    const otp = await readQr(page);
    secret = otp.secret;
    check(otp.issuer === 'StealthAudit' && otp.algorithm === 'SHA1' && otp.digits === 6 && otp.period === 30, JSON.stringify(otp.params));
    await submit(page, 'enrollment/confirm', { '#sa-setup-code': await freshCode(secret) });
    await page.locator('[data-sa-screen="codes"]').waitFor();
    const codes = await page.locator('[data-sa-codes] code').allTextContents();
    await page.check('#sa-codes-ack');
    await reloads(page, () => page.locator('form[data-sa-action="codes-ack"] button').click());
    check((await records(page)) !== 'error', 'audit view failed');
    return { url: '/stealthaudit/', http: plain, recoveryCodes: codes.length, rest: await page.evaluate(() => document.querySelector('[data-sa-root]').getAttribute('data-rest').replace(location.origin, '')) };
  });

  await step('Actions and errors: failed login, content change, a PHP warning', async () => {
    const anon = await browser.newContext({ ignoreHTTPSErrors: true });
    const bad = await anon.newPage();
    await bad.goto(`${BASE}/wp-login.php`);
    await bad.fill('#user_login', operator.login);
    await bad.fill('#user_pass', 'wrong-password');
    await Promise.all([bad.waitForLoadState('load'), bad.click('#wp-submit')]);
    await anon.close();
    wp('post', 'create', '--post_title=Package flow post', '--post_status=publish');
    flow('warning');
    return flow('snapshot');
  });

  await step('Filtered queries: login_failed/failure, content, warning diagnostics', async () => {
    const failed = await api(page, 'events', { action: 'login_failed', result: 'failure', category: 'access' });
    check(failed.status === 200 && failed.json.items.length > 0 && failed.json.items.every((i) => i.action === 'login_failed' && i.result === 'failure'), JSON.stringify(failed).slice(0, 300));
    const content = await api(page, 'events', { category: 'content' });
    check(content.status === 200 && content.json.items.length > 0 && content.json.items.every((i) => i.category === 'content'), 'content filter');
    const errors = await api(page, 'errors', { severity: 'warning' });
    check(errors.status === 200 && errors.json.items.some((i) => String(i.message).includes('stealthaudit package flow warning')) && errors.json.items.every((i) => i.severity === 'warning'), JSON.stringify(errors).slice(0, 300));
    return { login_failed: failed.json.items.length, content: content.json.items.length, warnings: errors.json.items.length };
  });

  await step('Idle expiration (proof aged past 1,800 s) and revocation, then TOTP again', async () => {
    const aged = flow('age-proofs');
    const expired = await api(page, 'events');
    check(expired.status === 401 && expired.json?.data?.panel === 'challenge_required', `expired proof still valid: ${expired.status}`);
    await page.goto(panel());
    await ready(page);
    await submit(page, 'authenticate', { '#sa-challenge-code': await freshCode(secret) });
    check((await records(page)) !== 'error', 'not authorized after TOTP');
    await reloads(page, () => page.locator('form[data-sa-action="revoke"] button').click());
    const revoked = await api(page, 'events');
    check(revoked.status === 401 && (await screen(page)) === 'challenge', 'revoked proof still valid');
    await submit(page, 'authenticate', { '#sa-challenge-code': await freshCode(secret) });
    check((await records(page)) !== 'error', 'not authorized after TOTP');
    return { aged: aged.aged, expired: expired.status, revoked: revoked.status, reauthorized: true };
  });

  let before;
  await step('Retention: real WP-Cron maintenance run on package A', () => {
    const was = flow('snapshot');
    wp('cron', 'event', 'run', 'stealthaudit_maintenance');
    before = flow('snapshot', { user: operator.id });
    check(before.maintenance.last_run && before.maintenance.last_run !== was.maintenance.last_run && before.factor, JSON.stringify(before.maintenance));
    return before.maintenance;
  });

  await step(`Update A → B (${marker}) by directory swap: data, factor, proofs and config preserved`, async () => {
    const config = dexec(CLI, ['sha256sum', active]).stdout.split(/\s+/)[0];
    // Which code does Apache EXECUTE? A test-only probe reads Runtime::VERSION through opcache.
    const token = randomBytes(16).toString('hex');
    web(`cat > ${MU}/zz-sa-probe.php`, { input: readFileSync(join(ROOT, 'tests/package/probe.php'), 'utf8').replace('__TOKEN__', token) });
    // A new connection per request: Apache's KeepAliveTimeout closes an idle pooled socket during
    // the install, and a reused dead socket would look like a server failure.
    const probe = () => new Promise((done, fail) => {
      http.get({ host: '127.0.0.1', port: PORT, path: `/?sa_package_probe=${token}`, agent: false, headers: { Connection: 'close' } }, (res) => {
        let body = '';
        res.setEncoding('utf8');
        res.on('data', (c) => { body += c; });
        res.on('end', () => { try { done(JSON.parse(body)); } catch { fail(new Error(`probe answered ${res.statusCode}: ${body.slice(0, 120)}`)); } });
      }).on('error', fail);
    });
    const runtimeFile = `${MU}/stealthaudit/src/Bootstrap/Runtime.php`;
    const mtimeA = web(`stat -c %Y ${runtimeFile}`).stdout;
    let served = await probe();
    for (let i = 0; i < 5; i++) served = await probe(); // compile and cache A in the Apache workers reached
    check(served.runtime_version === version && served.boot === 'ready', `before update the site runs ${JSON.stringify(served)}`);
    install(B.zip, { update: true });
    const files = matchesContents(B.zip.replace(/\.zip$/, '.contents.txt'));
    const mtimeB = web(`stat -c %Y ${runtimeFile}`).stdout;
    const beforeReload = [];
    for (let i = 0; i < 4; i++) beforeReload.push((await probe()).runtime_version);
    // docs/operations/installation.md: reload PHP (here Apache + mod_php) after the swap.
    dexec(WEB, ['apache2ctl', '-k', 'graceful']);
    let afterReload = null;
    for (let i = 0; i < 40 && afterReload?.runtime_version !== marker; i++) {
      await sleep(500);
      afterReload = await probe().catch(() => null);
    }
    check(afterReload?.runtime_version === marker, `after the reload Apache still runs ${JSON.stringify(afterReload)}`);
    const workers = [];
    for (let i = 0; i < 6; i++) workers.push((await probe()).runtime_version);
    check(workers.every((v) => v === marker), `some worker still runs A: ${workers.join(',')}`);
    web(`rm -f ${MU}/zz-sa-probe.php; rm -rf ${MU}/stealthaudit.old`);
    results.opcache = { served_before: served, mtime_a: Number(mtimeA), mtime_b: Number(mtimeB), before_reload: beforeReload, after_reload: afterReload.runtime_version };
    const after = flow('snapshot', { user: operator.id, upto_event: before.events_max });
    check(after.version === marker && after.boot === 'ready', `after update ${after.version}/${after.boot}`);
    check(after.schema === before.schema && after.old_events === before.events && after.errors >= before.errors && after.errors_max >= before.errors_max, `rows lost: ${JSON.stringify({ before, after })}`);
    check(JSON.stringify(after.factor) === JSON.stringify(before.factor) && after.proofs >= 1, 'factor row changed or proofs lost');
    check(dexec(CLI, ['sha256sum', active]).stdout.split(/\s+/)[0] === config, 'config file changed');
    // The open browser session keeps its proof: no re-verification after the swap.
    const still = await api(page, 'events');
    check(still.status === 200 && still.json.items.length > 0, `proof not preserved: ${still.status}`);
    // Collection runs on B: a new failed login is recorded.
    const anon = await browser.newContext({ ignoreHTTPSErrors: true });
    const bad = await anon.newPage();
    await bad.goto(`${BASE}/wp-login.php`);
    await bad.fill('#user_login', 'nobody-after-update');
    await bad.fill('#user_pass', 'wrong');
    await Promise.all([bad.waitForLoadState('load'), bad.click('#wp-submit')]);
    await anon.close();
    const newer = flow('snapshot');
    check(newer.events_max > after.events_max, 'no event recorded after the update');
    wp('cron', 'event', 'run', 'stealthaudit_maintenance');
    const maint = flow('snapshot').maintenance;
    check(maint.last_run !== before.maintenance.last_run, 'maintenance did not run on B');
    return { installedFiles: files, version: after.version, executed: { mtimeAEqualsB: mtimeA === mtimeB, opcache: served.opcache_enabled, validateTimestamps: served.validate_timestamps, revalidateFreq: served.revalidate_freq, beforeReload: [...new Set(beforeReload)], afterGracefulReload: afterReload.runtime_version }, eventsKept: after.old_events, factorUnchanged: true, proofStillValid: still.status, newEvent: true, maintenanceOnB: maint.state };
  });

  await step('Packaged operator CLI (web container: PHP only, no Composer) refuses while the allowlist is active', async () => {
    // A valid rotation target exists, so the active allowlist is the only reason to refuse.
    writeConfig(rotated, { authorized_user_ids: php([]), key_base64: php(keyB), key_id: php('pkg-b'), installation_id: php(installation) });
    const run = operatorCli('validate', 0, { STEALTHAUDIT_NEW_CONFIG_FILE: rotated });
    check(run.status === 1 && run.stderr.includes('panel must remain paused') && run.stdout === '', JSON.stringify(run));
    const over = await page.context().request.get(`${BASE}/wp-content/mu-plugins/stealthaudit/bin/security-operator.php`);
    const body = await over.text();
    const code = over.status();
    check(body === '', `CLI produced output over HTTP: ${body.slice(0, 80)}`);
    return { exit: run.status, message: run.stderr, http: { status: code, bodyBytes: body.length } };
  });

  await step('Offline key rotation with the packaged CLI: pause, rotate, validate, switch key, same TOTP works', async () => {
    writeConfig(active, { authorized_user_ids: php([]), key_base64: php(keyA), key_id: php('pkg-a'), installation_id: php(installation) });
    const rotate = operatorCli('rotate', 0, { STEALTHAUDIT_NEW_CONFIG_FILE: rotated });
    check(rotate.status === 0 && /Processed 1; migrated=1; last_user=\d+; complete=yes\./.test(rotate.stdout), JSON.stringify(rotate));
    const validate = operatorCli('validate', 0, { STEALTHAUDIT_NEW_CONFIG_FILE: rotated });
    check(validate.status === 0 && /Processed 1; last_user=\d+; complete=yes\./.test(validate.stdout), JSON.stringify(validate));
    check(!`${rotate.stdout}${validate.stdout}`.includes(keyB), 'key printed');
    writeConfig(active, { authorized_user_ids: php([operator.id]), key_base64: php(keyB), key_id: php('pkg-b'), installation_id: php(installation) });
    const old = await api(page, 'events');
    check(old.status === 401, `proof survived the rotation: ${old.status}`);
    await page.goto(panel());
    await ready(page);
    if ((await screen(page)) === 'password') await reloads(page, () => submit(page, 'reconfirm', { 'input[name="password"]': operator.password }));
    check((await screen(page)) === 'challenge', `after rotation: ${await screen(page)}`);
    await submit(page, 'authenticate', { '#sa-challenge-code': await freshCode(secret) });
    check((await records(page)) !== 'error', 'TOTP rejected after rotation');
    return { rotate: rotate.stdout, validate: validate.stdout, oldProof: old.status, sameSecretAccepted: true };
  });

  await step('Factor reset with the packaged CLI: sessions revoked, new enrollment required', async () => {
    writeConfig(active, { authorized_user_ids: php([]), key_base64: php(keyB), key_id: php('pkg-b'), installation_id: php(installation) });
    const reset = operatorCli('reset', operator.id);
    check(reset.status === 0 && reset.stdout === 'Factor reset; WordPress sessions revoked.', JSON.stringify(reset));
    writeConfig(active, { authorized_user_ids: php([operator.id]), key_base64: php(keyB), key_id: php('pkg-b'), installation_id: php(installation) });
    await page.goto(panel());
    check(page.url().includes('/wp-login.php'), `WordPress session survived the reset: ${page.url()}`);
    await login(page, operator, panel());
    await reloads(page, () => submit(page, 'reconfirm', { 'input[name="password"]': operator.password }));
    check((await screen(page)) === 'enroll', `after reset: ${await screen(page)}`);
    return { reset: reset.stdout, afterLogin: 'enroll' };
  });

  await browser.close();
  browser = null;
  await proxy.close();
  proxy = null;

  // ---------- 6. Plugin Check (support only) ----------
  await step(`Plugin Check ${PLUGIN_CHECK} on package A as an inactive conventional plugin (support only; MU differences justified in docs)`, () => {
    wp('plugin', 'install', 'plugin-check', `--version=${PLUGIN_CHECK}`, '--activate');
    // Package A as a (never activated) conventional plugin: plugins/stealthaudit/{stealthaudit.php,stealthaudit/}.
    toContainer(A.zip, WEB, '/tmp/stealthaudit-check.zip');
    dexec(WEB, ['chmod', '0644', '/tmp/stealthaudit-check.zip']);
    web(`set -e; rm -rf /tmp/sa-check ${WP}/wp-content/plugins/stealthaudit; php -r '$z = new ZipArchive(); if (true !== $z->open($argv[1]) || !$z->extractTo($argv[2])) { exit(1); }' /tmp/stealthaudit-check.zip /tmp/sa-check; mv /tmp/sa-check/mu-plugins ${WP}/wp-content/plugins/stealthaudit; rm -rf /tmp/sa-check`);
    dexec(WEB, ['rm', '-f', '/tmp/stealthaudit-check.zip']);
    const run = dexec(CLI, ['wp', `--path=${WP}`, 'plugin', 'check', 'stealthaudit', '--format=json'], { workdir: HARNESS, allowFail: true });
    writeFileSync(join(OUT, 'plugin-check.txt'), `${run.stdout}\n${run.stderr}\n`);
    const counts = {};
    for (const line of run.stdout.split('\n')) {
      if (!line.trim().startsWith('[')) continue;
      for (const item of JSON.parse(line)) {
        const key = `${item.type} ${item.code}`;
        counts[key] = (counts[key] || 0) + 1;
      }
    }
    web(`rm -rf ${WP}/wp-content/plugins/stealthaudit`);
    wp('plugin', 'deactivate', 'plugin-check');
    wp('plugin', 'delete', 'plugin-check');
    results.pluginCheck = counts;
    return { exit: run.status, findings: Object.values(counts).reduce((a, b) => a + b, 0), byCode: counts };
  });
} catch (failure) {
  process.exitCode = 1;
  if (!results.steps.some((s) => s.ok === false)) results.error = String(failure.message || failure);
  console.error(failure.message || failure);
} finally {
  await browser?.close().catch(() => {});
  await proxy?.close().catch(() => {});
  if (started) {
    try {
      const webName = container(ENV_PREFIX, 'wordpress');
      const log = execFileSync('docker', ['logs', webName], { env, stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 256 * 1024 * 1024 });
      writeFileSync(join(OUT, 'web.log'), log);
      const lines = log.toString().match(/^.*PHP (?:Deprecated|Warning|Notice|Fatal error|Parse error):.*$/gm) || [];
      const own = lines.filter((line) => line.includes('/mu-plugins/stealthaudit'));
      results.web = { php_diagnostics: lines.length, stealthaudit: own.slice(0, 20), other: [...new Set(lines.filter((l) => !own.includes(l)).map((l) => l.replace(/^\[[^\]]*\] /, '')))].slice(0, 20) };
      if (own.length) {
        process.exitCode = 1;
        console.error(`web log: ${own.length} StealthAudit PHP diagnostics (artifacts/package-e2e/web.log)`);
      }
    } catch (error) {
      results.web = { error: String(error.message || error).split('\n')[0] };
    }
    if (!flag('keep')) {
      const destroy = wpEnv(['destroy', '--force'], 'destroy.log');
      results.destroyed = destroy.ok;
      if (destroy.ok) rmSync(join(ROOT, `.wp-env.matrix-${SLUG}.json`), { force: true });
      else console.error('destroy failed; see artifacts/package-e2e/destroy.log');
    }
  }
  results.freeGiB = Math.round(freeGiB() * 10) / 10;
  results.finished = new Date().toISOString();
  writeFileSync(join(OUT, 'results.json'), JSON.stringify(results, null, 2) + '\n');
  const failed = results.steps.filter((s) => !s.ok).length;
  console.log(`\n${results.steps.length - failed} passed, ${failed} failed (artifacts/package-e2e/results.json)`);
}
