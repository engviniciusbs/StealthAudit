// SPDX-License-Identifier: GPL-3.0-or-later
// Development only: reproducible StealthAudit benchmark (phase 8b) in an ADDITIONAL disposable
// wp-env (tools/wp-env.cjs --matrix=8.3,mariadb:10.11.19,port=8882,tag=bench). It never touches
// the development (8870) or test (8880) sites and destroys its environment at the end.
//
//   npm run benchmark [-- --sizes 10000,100000,1000000] [--http 200] [--iterations 300]
//                      [--no-panel] [--keep] [--reuse] [--port 8882]
//
// For each size it seeds that many synthetic audit rows AND diagnostic rows, then measures:
//   collect   one WordPress action that records one event (failed wp_signon), loader off vs on,
//             in one warm WP-CLI process (per action) and over HTTP (per request, wp-login.php);
//   memory    plugin footprint of a booted request and growth over the collection loop;
//   query     100-row storage windows (filters, period, deep cursor, no-match worst cases) and
//             the REST handler with and without the MaxMind City *test* MMDB;
//   cleanup   one 500-row maintenance batch and full maintenance runs over expired rows;
//   panel     warm panel loads in Chrome through the TLS proxy, with and without GeoIP;
// and, once, concurrent HTTP bursts with the default per-second budget.
// Raised capacity caps are set explicitly in the benchmark config below, only in this env.
// Results: artifacts/benchmark/results.json and summary.md (gitignored).
import { execFileSync, spawnSync } from 'node:child_process';
import { mkdirSync, readFileSync, rmSync, statfsSync, writeFileSync } from 'node:fs';
import http from 'node:http';
import { cpus, totalmem, release, type } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { randomBytes } from 'node:crypto';
import { openPanel, stats } from './lib/panel.mjs';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = join(ROOT, 'artifacts/benchmark');
const argv = process.argv.slice(2);
const flag = (name) => argv.includes(`--${name}`);
const value = (name, fallback) => {
  const i = argv.indexOf(`--${name}`);
  return i >= 0 ? argv[i + 1] : fallback;
};
const SIZES = value('sizes', '10000,100000,1000000').split(',').map(Number);
const HTTP_REQUESTS = Number(value('http', 200));
const ITERATIONS = Number(value('iterations', 300));
const PORT = Number(value('port', 8882));
if (SIZES.some((n) => !Number.isInteger(n) || n < 1000 || n > 2_000_000) || [8870, 8880, 8881].includes(PORT)) {
  console.error('usage: npm run benchmark -- [--sizes 10000,100000,1000000] [--http 200] [--iterations 300] [--no-panel] [--keep] [--reuse] [--port 8882]');
  process.exit(2);
}
const SPEC = `8.3,mariadb:10.11.19,port=${PORT},tag=bench`;
const SLUG = 'php83-mariadb101119-bench';
const MIN_FREE_GIB = 8; // below this the run stops instead of filling the Docker host disk
const env = { ...process.env, MSYS_NO_PATHCONV: '1' };
// wp-env serves /var/www/html (WordPress core included) from a 9p bind mount of the Windows host;
// the plugin copy and its config live on each container's own disk, outside the webroot.
const BENCH_DIR = '/opt/stealthaudit-bench';
const LOCAL_WP = '/opt/wp-local';
const PLUGIN = '/var/www/html/wp-content/mu-plugins/stealthaudit';
const MMDB = '/tmp/stealthaudit-bench-geoip/GeoIP2-City-Test.mmdb'; // outside the webroot, as required

// ---------- infrastructure ----------
const freeGiB = () => {
  const s = statfsSync(ROOT);
  return Math.round(((s.bavail * s.bsize) / 2 ** 30) * 10) / 10;
};
function guardDisk(stage) {
  const free = freeGiB();
  console.log(`[bench] ${stage}: ${free} GiB free on the host disk`);
  if (free < MIN_FREE_GIB) throw new Error(`only ${free} GiB free (< ${MIN_FREE_GIB} GiB) before ${stage}; stopping instead of filling the disk`);
  return free;
}
function wpEnv(args, log) {
  const run = spawnSync(process.execPath, ['tools/wp-env.cjs', `--matrix=${SPEC}`, ...args], { cwd: ROOT, env, encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 });
  if (log) writeFileSync(join(OUT, log), `${run.stdout}${run.stderr}`);
  return run.status === 0;
}
function container(service) {
  const exact = new RegExp(`^wp-env-stealthaudit-matrix-${SLUG}-[0-9a-f]{8}-${service}-1$`);
  const name = execFileSync('docker', ['ps', '--filter', `name=wp-env-stealthaudit-matrix-${SLUG}-`, '--format', '{{.Names}}']).toString().split(/\s+/).find((n) => exact.test(n));
  if (!name) throw new Error(`benchmark ${service} container not running`);
  return name;
}
const exec = (name, args, { input, profile, user } = {}) => execFileSync('docker', ['exec', ...(input !== undefined ? ['-i'] : []), ...(user ? ['-u', user] : []), ...(profile ? ['-e', `STEALTHAUDIT_BENCH_PROFILE=${profile}`] : []), name, ...args], { env, input, maxBuffer: 64 * 1024 * 1024 }).toString();
const sh = (name, script, options) => exec(name, ['sh', '-c', script], options).trim();
let CLI, WEB, DB;
function bench(action, input, profile) {
  const out = exec(CLI, ['wp', `--path=/var/www/html`, 'eval-file', `${PLUGIN}/benchmarks/php/bench.php`, action, ...(input ? [JSON.stringify(input)] : [])], { profile }).trim().split('\n');
  return JSON.parse(out.at(-1));
}

// Capacity caps raised ONLY here, explicitly: the defaults (100,000 audit rows, 128 MiB; 20,000
// diagnostics, 32 MiB) would suspend collection on a 1,000,000-row table by design. Per-request,
// per-second and work budgets stay at their defaults for HTTP; "loop" lifts them for the single
// WP-CLI process that repeats one action hundreds of times.
const CAPS = { event_rows: 3_000_000, event_bytes: 2_000_000_000, error_rows: 3_000_000, error_bytes: 2_000_000_000 };
const LOOP = { events_per_request: 1_000_000, events_per_second: 1_000_000, storage_work_ms: 10_000_000 };
const panel = { authorized_user_ids: [], key_base64: randomBytes(32).toString('base64'), key_id: 'bench', installation_id: `bench-${randomBytes(6).toString('hex')}` };
let mmdbForPanel = null;
function mode(state) {
  const config = { endpoint: '/stealthaudit', trusted_proxies: [], limits: CAPS, mmdb_max_age_days: 3650, ...panel, ...(mmdbForPanel ? { mmdb_path: mmdbForPanel } : {}) };
  for (const name of [CLI, WEB]) sh(name, `cat > ${BENCH_DIR}/config.json`, { input: JSON.stringify({ mode: state, config, loop: LOOP }), user: 'root' });
}

// ---------- HTTP ----------
// One TCP connection per request: Apache's MaxKeepAliveRequests/KeepAliveTimeout close reused
// sockets mid-burst, and both modes pay the same loopback connect.
const agent = new http.Agent({ keepAlive: false, maxSockets: 64 });
// A connection reset before any response is sent once more (at most twice); every resend is counted in results.http_resends; a non-network failure is never retried.
let RESENDS = 0;
async function login(i) {
  for (let tries = 1; ; tries++) {
    try {
      return await attempt(i);
    } catch (error) {
      if (tries >= 3 || !/socket hang up|ECONNRESET/.test(String(error.message))) throw error;
      RESENDS++;
    }
  }
}
function attempt(i) {
  return new Promise((resolveRequest, reject) => {
    const body = `log=bench-no-such-user-${i % 7}&pwd=wrong-password&wp-submit=Log+In`;
    const started = performance.now();
    const req = http.request({ host: '127.0.0.1', port: PORT, path: '/wp-login.php', method: 'POST', agent, headers: { Host: `localhost:${PORT}`, 'X-Forwarded-Proto': 'https', 'Content-Type': 'application/x-www-form-urlencoded', 'Content-Length': Buffer.byteLength(body) } }, (res) => {
      res.resume();
      res.on('end', () => (res.statusCode === 200 ? resolveRequest(performance.now() - started) : reject(new Error(`wp-login answered ${res.statusCode}`))));
    });
    req.on('error', reject);
    req.end(body);
  });
}
async function sequential(count) {
  const samples = [];
  for (let i = 0; i < count; i++) samples.push(await login(i));
  return samples;
}
// The site URL is https://localhost:8443 (panel), so wp-login.php would redirect plain HTTP;
// the wp-env wp-config turns X-Forwarded-Proto: https into HTTPS=on, as the e2e TLS proxy does.
// Interleaved off/on blocks against drift; both modes see the same warm Apache and opcache.
async function httpCollect() {
  const samples = { off: [], on: [] };
  for (const state of ['off', 'on']) {
    mode(state);
    await sequential(20);
  }
  const block = 25;
  const before = bench('count').events.rows;
  let onRequests = 0;
  for (let b = 0; b < (2 * HTTP_REQUESTS) / block; b++) {
    const state = b % 2 === 0 ? 'off' : 'on';
    mode(state);
    samples[state].push(...(await sequential(block)));
    if (state === 'on') onRequests += block;
  }
  mode('on');
  const written = bench('count').events.rows - before;
  return { requests_per_mode: HTTP_REQUESTS, events_written_on: written, on_requests: onRequests, off: stats(samples.off), on: stats(samples.on), p50_delta_ms: round(stats(samples.on).p50 - stats(samples.off).p50), p95_delta_ms: round(stats(samples.on).p95 - stats(samples.off).p95) };
}
async function burst(state, clients, each) {
  mode(state);
  await sequential(10);
  const before = bench('count');
  const started = performance.now();
  const lists = await Promise.all(Array.from({ length: clients }, (_, c) => (async () => { const s = []; for (let i = 0; i < each; i++) s.push(await login(c * 1000 + i)); return s; })()));
  const seconds = (performance.now() - started) / 1000;
  const after = bench('count');
  mode('on');
  return { mode: state, clients, requests: clients * each, seconds: round(seconds), requests_per_second: round((clients * each) / seconds), latency_ms: stats(lists.flat()), events_written: after.events.rows - before.events.rows, dropped_counted: (after.events.budget.dropped ?? 0) - (before.events.budget.dropped ?? 0) };
}
const round = (n) => Math.round(n * 1000) / 1000;

// ---------- the run ----------
mkdirSync(OUT, { recursive: true });
const results = { started: new Date().toISOString(), spec: SPEC, sizes: SIZES, http_requests_per_mode: HTTP_REQUESTS, cli_iterations: ITERATIONS, caps: CAPS, loop_limits: LOOP, host: {}, versions: {}, runs: {}, bursts: [] };
let failed = false;
let ui = null;
try {
  results.host = { os: `${type()} ${release()}`, cpu: cpus()[0]?.model, logical_cpus: cpus().length, memory_gib: Math.round(totalmem() / 2 ** 30), disk_free_gib_start: guardDisk('start') };
  const geo = spawnSync(process.execPath, ['tools/geoip-test-data.mjs'], { cwd: ROOT, env, stdio: 'inherit' });
  if (geo.status !== 0) throw new Error('GeoIP test data unavailable (network required on first run)');
  if (!flag('reuse')) {
    console.log(`[bench] starting the isolated benchmark environment on 127.0.0.1:${PORT} ...`);
    if (!wpEnv(['start'], 'start.log')) throw new Error('wp-env start failed; see artifacts/benchmark/start.log');
  }
  CLI = container('cli');
  WEB = container('wordpress');
  DB = container('mysql');
  const info = JSON.parse(execFileSync('docker', ['info', '--format', '{{json .}}']).toString());
  results.host.docker = { server: info.ServerVersion, os: info.OperatingSystem, kernel: info.KernelVersion, cpus: info.NCPU, memory_gib: Math.round(info.MemTotal / 2 ** 30) };
  results.versions = {
    web_php: sh(WEB, 'php -v | head -1'),
    cli_php: sh(CLI, 'php -v | head -1'),
    opcache_web: sh(WEB, "php -i | grep -E '^opcache.enable |^opcache.validate_timestamps|^opcache.revalidate_freq' | tr -s ' ' | tr '\\n' ';'"),
    wordpress: sh(CLI, 'wp --path=/var/www/html core version'),
    database: sh(CLI, `wp --path=/var/www/html eval 'global $wpdb; echo $wpdb->db_server_info();'`),
    innodb_buffer_pool_mib: sh(CLI, `wp --path=/var/www/html eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT @@innodb_buffer_pool_size") / 1048576;'`),
    images: Object.fromEntries([CLI, WEB, DB].map((name) => [name.replace(/^.*-(\w+)-1$/, '$1'), execFileSync('docker', ['inspect', '--format', '{{.Config.Image}}', name]).toString().trim()])),
  };
  console.log(JSON.stringify(results.versions, null, 2));

  // Production-like PHP settings in this site only; WP-Cron off so a spawned cron request does
  // not land inside a timed sample (maintenance is measured directly below).
  for (const [name, val] of [['WP_DEBUG', 'false'], ['DISABLE_WP_CRON', 'true']]) sh(CLI, `wp --path=/var/www/html config set ${name} ${val} --raw --type=constant`);
  for (const name of ['WP_HOME', 'WP_SITEURL']) sh(CLI, `wp --path=/var/www/html config set ${name} https://localhost:8443 --type=constant`);
  // Plugin copy on each container's own disk (not the Windows 9p share), outside the webroot.
  for (const name of [CLI, WEB]) sh(name, `rm -rf ${BENCH_DIR} && mkdir -p ${BENCH_DIR}/stealthaudit && cd ${PLUGIN} && cp -r bootstrap.php src vendor templates assets ${BENCH_DIR}/stealthaudit/ && cp loader/stealthaudit.php ${BENCH_DIR}/stealthaudit.php && chmod -R a+rX ${BENCH_DIR} && chmod a+w ${BENCH_DIR}`, { user: 'root' });
  // mu-plugins/ itself is created root-owned by the wp-env mapping.
  sh(CLI, `cp ${PLUGIN}/benchmarks/php/toggle.php /var/www/html/wp-content/mu-plugins/0-stealthaudit-bench.php && chmod 644 /var/www/html/wp-content/mu-plugins/0-stealthaudit-bench.php`, { user: 'root' });
  // Apache serves a copy of the site from the web container's own disk (/opt/wp-local): every
  // request through the 9p share cost ~0.4 s with the plugin off, which would bury the plugin's
  // cost. Same files, wp-config and database; the repository mapping is replaced by the runtime
  // files only. Default image opcache settings (revalidation every 2 s) are kept.
  sh(WEB, [
    `rm -f /usr/local/etc/php/conf.d/zz-stealthaudit-bench.ini && rm -rf ${LOCAL_WP} && mkdir -p ${LOCAL_WP}`,
    `cd /var/www/html && tar --exclude=./wp-content/mu-plugins/stealthaudit -cf - . | tar -xf - -C ${LOCAL_WP}`,
    `mkdir -p ${LOCAL_WP}/wp-content/mu-plugins/stealthaudit && cd ${PLUGIN} && cp -r bootstrap.php src vendor templates assets ${LOCAL_WP}/wp-content/mu-plugins/stealthaudit/ && chmod -R a+rX ${LOCAL_WP}`,
    `sed -i 's#DocumentRoot /var/www/html$#DocumentRoot ${LOCAL_WP}#' /etc/apache2/sites-enabled/000-default.conf`,
    `printf '<Directory ${LOCAL_WP}/>\\n Options FollowSymLinks\\n AllowOverride All\\n Require all granted\\n</Directory>\\n' > /etc/apache2/conf-enabled/zz-stealthaudit-bench.conf`,
    'apache2ctl -k graceful',
  ].join(' && '), { user: 'root' });
  await new Promise((done) => setTimeout(done, 3000));
  results.versions.document_root = sh(WEB, 'grep -h DocumentRoot /etc/apache2/sites-enabled/000-default.conf');
  sh(CLI, `mkdir -p ${dirname(MMDB)} && cp ${PLUGIN}/artifacts/geoip/GeoIP2-City-Test.mmdb ${MMDB}`);
  execFileSync('docker', ['exec', '-u', 'root', WEB, 'mkdir', '-p', dirname(MMDB)], { env });
  execFileSync('docker', ['cp', join(ROOT, 'artifacts/geoip/GeoIP2-City-Test.mmdb'), `${WEB}:${MMDB}`], { env });
  execFileSync('docker', ['exec', '-u', 'root', WEB, 'chmod', '-R', 'a+rX', dirname(MMDB)], { env });
  const operator = bench('user');
  panel.authorized_user_ids = [operator.id];
  mode('on');
  sh(CLI, 'wp --path=/var/www/html eval "echo STEALTHAUDIT_BOOT_STATUS;"');

  for (const size of SIZES) {
    const run = (results.runs[size] = {});
    guardDisk(`seeding ${size}`);
    console.log(`[bench] seeding ${size} audit rows and ${size} diagnostics ...`);
    run.seed = bench('seed', { events: size, errors: size });
    console.log(`[bench] ${size}: seeded in ${run.seed.seconds}s`);

    console.log(`[bench] ${size}: collection in one warm WP-CLI process (off/on, ${ITERATIONS} actions each, twice) ...`);
    run.cli = { off: [], on: [] };
    for (const state of ['off', 'on', 'off', 'on']) {
      mode(state);
      run.cli[state].push(bench('collect', { iterations: ITERATIONS, warmup: 30 }, 'loop'));
    }
    mode('on');
    const cli = (state, key) => Math.min(...run.cli[state].map((r) => r.ms[key]));
    run.cli.p50_delta_ms = round(cli('on', 'p50') - cli('off', 'p50'));
    run.cli.p95_delta_ms = round(cli('on', 'p95') - cli('off', 'p95'));
    run.cli.memory_footprint_delta_bytes = Math.min(...run.cli.on.map((r) => r.memory_at_start)) - Math.min(...run.cli.off.map((r) => r.memory_at_start));
    run.cli.memory_growth_on_bytes = Math.max(...run.cli.on.map((r) => r.memory_growth));
    run.cli.memory_growth_off_bytes = Math.max(...run.cli.off.map((r) => r.memory_growth));
    run.cli.memory_peak_delta_bytes = Math.max(...run.cli.on.map((r) => r.memory_peak)) - Math.max(...run.cli.off.map((r) => r.memory_peak));
    if (run.cli.on.some((r) => r.events_written !== r.expected) || run.cli.off.some((r) => r.events_written !== 0)) throw new Error(`collection did not record exactly one event per action: ${JSON.stringify(run.cli.on.map((r) => r.events_written))}`);

    console.log(`[bench] ${size}: collection over HTTP (wp-login.php, ${HTTP_REQUESTS} requests per mode) ...`);
    run.http = await httpCollect();
    console.log(`[bench] ${size}: queries and REST handler ...`);
    run.query = bench('query', { reps: 20, mmdb: MMDB });
    console.log(`[bench] ${size}: cleanup ...`);
    run.cleanup = bench('cleanup', { expired: 20000 });

    if (!flag('no-panel')) {
      console.log(`[bench] ${size}: warm panel in Chrome ...`);
      if (!ui) {
        ui = await openPanel({ httpPort: PORT, user: operator });
        await ui.enroll();
      }
      // Newest rows, diagnostics, and a filter no row matches (the worst case: a full newest-first
      // scan of the retention window, see the no_match_* storage queries).
      run.panel = { audit: await ui.measure('view=audit&limit=100'), errors: await ui.measure('view=errors&limit=100'), audit_no_match: await ui.measure('view=audit&limit=100&result=unknown', { expect: 'empty' }) };
      mmdbForPanel = MMDB;
      mode('on');
      // Seeded logout rows: mixed IPs, many known to the City test MMDB (the newest rows are the
      // benchmark's own failed logins from a single Docker gateway address).
      run.panel.audit_geoip = await ui.measure('view=audit&limit=100&action=logout');
      mmdbForPanel = null;
      mode('on');
      run.panel.audit_logout = await ui.measure('view=audit&limit=100&action=logout');
    }
    writeFileSync(join(OUT, 'results.json'), JSON.stringify(results, null, 2) + '\n');
  }

  console.log('[bench] concurrent bursts (default per-request/per-second budgets) ...');
  for (const state of ['off', 'on']) results.bursts.push(await burst(state, 16, 25));
  results.final_counters = bench('count');
} catch (error) {
  failed = true;
  results.error = String(error.stack || error.message || error);
  console.error(`[bench] ${results.error}`);
} finally {
  await ui?.close().catch(() => {});
  agent.destroy();
  try {
    const log = execFileSync('docker', ['logs', container('wordpress')], { stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 256 * 1024 * 1024 }).toString();
    writeFileSync(join(OUT, 'web.log'), log);
    const php = log.match(/^.*PHP (?:Deprecated|Warning|Notice|Fatal error|Parse error):.*$/gm) || [];
    results.web_log = { php_diagnostics: php.length, stealthaudit: php.filter((l) => /stealthaudit/.test(l)).slice(0, 20) };
    if (results.web_log.stealthaudit.length) failed = true;
  } catch (error) {
    results.web_log = { error: String(error.message).split('\n')[0] };
  }
  results.http_resends = RESENDS;
  results.host.disk_free_gib_end = freeGiB();
  if (!flag('keep')) {
    console.log('[bench] destroying the benchmark environment ...');
    results.destroyed = wpEnv(['destroy', '--force'], 'destroy.log');
    if (results.destroyed) rmSync(join(ROOT, `.wp-env.matrix-${SLUG}.json`), { force: true });
  }
  results.finished = new Date().toISOString();
  writeFileSync(join(OUT, 'results.json'), JSON.stringify(results, null, 2) + '\n');
  writeFileSync(join(OUT, 'summary.md'), summary(results));
  console.log(summary(results));
}
process.exit(failed ? 1 : 0);

// ---------- summary against the reference targets (local benchmark criteria, not an SLA) ----------
function summary(r) {
  const lines = ['# StealthAudit benchmark', '', `${r.started} → ${r.finished ?? '…'} · ${r.host.cpu} (${r.host.logical_cpus} threads) · ${r.versions.web_php ?? ''} · ${r.versions.database ?? ''}`, ''];
  const verdict = (ok) => (ok === null ? 'n/d' : ok ? 'atende' : 'NÃO atende');
  lines.push('| Medida | Meta | ' + Object.keys(r.runs).join(' | ') + ' |', '| --- | --- |' + Object.keys(r.runs).map(() => ' --- |').join(''));
  const row = (label, target, pick, limit) => {
    const cells = Object.values(r.runs).map((run) => {
      const v = pick(run);
      return v === undefined || v === null || Number.isNaN(v) ? 'n/d' : `${v} (${verdict(limit === undefined ? null : v <= limit)})`;
    });
    lines.push(`| ${label} | ${target} | ${cells.join(' | ')} |`);
  };
  row('Acréscimo p95 por ação, WP-CLI (ms)', '≤ 20', (x) => x.cli?.p95_delta_ms, 20);
  row('Acréscimo p95 por requisição HTTP (ms)', '≤ 20', (x) => x.http?.p95_delta_ms, 20);
  row('Memória adicional no boot (MiB)', '≤ 5', (x) => x.cli && round(x.cli.memory_footprint_delta_bytes / 2 ** 20), 5);
  row(`Crescimento de memória em ${r.cli_iterations + 30} coletas (MiB)`, '≤ 5', (x) => x.cli && round(x.cli.memory_growth_on_bytes / 2 ** 20), 5);
  row('Pior p95 de janela de 100 eventos (ms)', '≤ 500', (x) => x.query && Math.max(...Object.values(x.query.events).map((q) => q.p95)), 500);
  row('p95 janela 100 eventos sem filtro (ms)', '≤ 500', (x) => x.query?.events.newest.p95, 500);
  row('Pior p95 de janela de 100 erros (ms)', '≤ 500', (x) => x.query && Math.max(...Object.values(x.query.errors).map((q) => q.p95)), 500);
  row('p95 handler REST sem GeoIP (ms)', '—', (x) => x.query?.api?.api_events_no_geoip?.p95);
  row('p95 handler REST com GeoIP (ms)', '—', (x) => x.query?.api?.api_events_geoip?.p95);
  row('p95 painel aquecido, auditoria (ms)', '≤ 2000', (x) => x.panel?.audit.total_ms.p95, 2000);
  row('p95 painel aquecido, erros (ms)', '≤ 2000', (x) => x.panel?.errors.total_ms.p95, 2000);
  row('p95 painel aquecido, filtro logout sem GeoIP (ms)', '≤ 2000', (x) => x.panel?.audit_logout?.total_ms.p95, 2000);
  row('p95 painel aquecido, filtro logout com GeoIP (ms)', '≤ 2000', (x) => x.panel?.audit_geoip.total_ms.p95, 2000);
  row('p95 painel aquecido, filtro sem resultado (ms)', '≤ 2000', (x) => x.panel?.audit_no_match?.total_ms.p95, 2000);
  row('p95 DELETE de 500 linhas (ms)', '≤ 250', (x) => x.cleanup?.delete_500_ms.p95, 250);
  row('p95 manutenção com 1 lote de 500 (ms)', '≤ 250', (x) => x.cleanup?.run_one_batch_ms.p95, 250);
  lines.push('', 'Rajadas: ' + r.bursts.map((b) => `${b.mode}: ${b.requests} req, ${b.requests_per_second} req/s, p95 ${b.latency_ms.p95} ms, eventos ${b.events_written}, perdas contadas ${b.dropped_counted}`).join(' · '));
  if (r.error) lines.push('', `Erro: ${r.error.split('\n')[0]}`);
  return lines.join('\n') + '\n';
}
