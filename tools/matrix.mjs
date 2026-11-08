// SPDX-License-Identifier: GPL-3.0-or-later
// Development only: run the suites on one PHP x database combination in an ADDITIONAL isolated
// wp-env (tools/wp-env.cjs --matrix). Never touches the development (8870) or test (8880) sites.
//
//   npm run matrix -- --php 8.4 --db mysql:8.4.13 [--e2e] [--keep] [--prefix wpx_] [--collate utf8mb4_0900_ai_ci] [--port 8881]
//
// Steps: start -> record versions -> unit -> integration (incl. two-process tests) -> optional
// e2e -> destroy (unless --keep). Logs and versions land in artifacts/matrix/<slug>/ (gitignored).
// Exit code is non-zero when any suite fails; the environment is still destroyed.
import { spawnSync, execFileSync } from 'node:child_process';
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const argv = process.argv.slice(2);
const flag = (name) => argv.includes(`--${name}`);
const value = (name) => {
  const i = argv.indexOf(`--${name}`);
  return i >= 0 ? argv[i + 1] : undefined;
};
const php = value('php');
const db = value('db');
if (!php || !db) {
  console.error('usage: npm run matrix -- --php 8.4 --db mysql:8.4.13 [--e2e] [--keep] [--prefix p_] [--collate c] [--port 8881]');
  process.exit(2);
}
const port = value('port') || '8881';
const spec = [php, db, value('prefix') && `prefix=${value('prefix')}`, value('collate') && `collate=${value('collate')}`, `port=${port}`].filter(Boolean).join(',');
const slug = [`php${php.replace('.', '')}`, db.replace(/[:.]/g, ''), value('prefix') && `p${value('prefix').replace(/_/g, '')}`, value('collate') && `c${value('collate').replace(/^utf8mb4_/, '').replace(/_/g, '')}`].filter(Boolean).join('-');
const OUT = join(ROOT, 'artifacts/matrix', slug);
mkdirSync(OUT, { recursive: true });

const env = { ...process.env, MSYS_NO_PATHCONV: '1' };
function wpEnv(args, log) {
  const run = spawnSync(process.execPath, ['tools/wp-env.cjs', `--matrix=${spec}`, ...args], { cwd: ROOT, env, encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 });
  const output = `${run.stdout}${run.stderr}`;
  if (log) writeFileSync(join(OUT, log), output);
  return { ok: run.status === 0, output };
}
function container(service) {
  const names = execFileSync('docker', ['ps', '--filter', `name=wp-env-stealthaudit-matrix-${slug}-`, '--format', '{{.Names}}']).toString().split(/\s+/);
  const name = names.find((n) => n.endsWith(`-${service}-1`));
  if (!name) throw new Error(`matrix ${slug}: ${service} container not running`);
  return name;
}
const sh = (name, script) => {
  try {
    return execFileSync('docker', ['exec', name, 'sh', '-c', script], { env, stdio: ['ignore', 'pipe', 'pipe'] }).toString().trim();
  } catch (error) {
    return `unavailable: ${String(error.stderr || error.message).trim().split('\n')[0]}`;
  }
};
const summary = (output) => (output.match(/^(OK \(.*\)|Tests: .*|FAILURES!|ERRORS!|\d+ passed, \d+ failed.*)$/gm) || []).join(' ');

const results = { slug, php, db, prefix: value('prefix') || null, collate: value('collate') || null, started: new Date().toISOString(), suites: {} };
let failed = false;
try {
  const geo = spawnSync(process.execPath, ['tools/geoip-test-data.mjs'], { cwd: ROOT, env, stdio: 'inherit' });
  if (geo.status !== 0) throw new Error('GeoIP test data unavailable (network required on first run)');
  console.log(`[${slug}] starting isolated environment on 127.0.0.1:${port} ...`);
  const start = wpEnv(['start'], 'start.log');
  if (!start.ok) throw new Error(`wp-env start failed; see artifacts/matrix/${slug}/start.log`);

  const cli = container('cli');
  const web = container('wordpress');
  const database = container('mysql');
  const daemon = db.startsWith('mariadb:') ? 'mariadbd --version' : 'mysqld --version';
  const sql = 'SELECT VERSION(), @@version_comment, @@default_storage_engine, @@character_set_database, @@collation_database';
  results.versions = {
    cli_php: sh(cli, 'php -v | head -1'),
    web_php: sh(web, 'php -v | head -1'),
    extensions: sh(cli, 'php -m | tr "\\n" " "'),
    wordpress: sh(cli, 'cd /var/www/html && wp core version'),
    wp_cli: sh(cli, 'wp --version'),
    composer: sh(cli, 'composer --version 2>/dev/null | head -1'),
    phpunit: sh(cli, 'cd /var/www/html/wp-content/mu-plugins/stealthaudit && vendor/bin/phpunit --version | head -1'),
    mysqld: sh(database, daemon),
    // Through PHP mysqli (the product's driver), not the mysql client binary.
    server: sh(cli, `cd /var/www/html && wp eval 'global $wpdb; echo implode(" | ", $wpdb->get_row("${sql}", ARRAY_N));'`),
    connection: sh(cli, `cd /var/www/html && wp eval 'global $wpdb; echo $wpdb->prefix, " ", $wpdb->charset, " ", $wpdb->collate, " ", $wpdb->db_server_info();'`),
    images: Object.fromEntries([cli, web, database].map((name) => [name, execFileSync('docker', ['inspect', '--format', '{{.Config.Image}} {{.Image}}', name]).toString().trim()])),
  };
  for (const tag of [db, `wordpress:7.1.4-php${php}-apache`, `wordpress:cli-2.12.0-php${php}`]) {
    // BuildKit may keep FROM bases only in its build cache; ask the registry for the digest then.
    for (const command of [['image', 'inspect', '--format', '{{join .RepoDigests " "}}', tag], ['buildx', 'imagetools', 'inspect', '--format', '{{json .Manifest.Digest}}', tag]]) {
      try {
        results.versions.images[tag] = execFileSync('docker', command, { stdio: ['ignore', 'pipe', 'pipe'] }).toString().trim();
        break;
      } catch {
        results.versions.images[tag] = 'digest unavailable';
      }
    }
  }
  console.log(JSON.stringify(results.versions, null, 2));

  for (const suite of ['test:unit', 'test:integration']) {
    console.log(`[${slug}] composer ${suite} ...`);
    const run = wpEnv(['run', 'cli', '--env-cwd=wp-content/mu-plugins/stealthaudit', 'composer', 'run-script', '--timeout=0', suite], `${suite.replace(':', '-')}.log`);
    // Pristine output is part of the pass: any PHP diagnostic printed outside PHPUnit fails it.
    const noise = run.output.match(/^.*(?:PHP )?(?:Deprecated|Warning|Notice|Fatal error|Parse error):.*$/gm) || [];
    results.suites[suite] = { ok: run.ok && noise.length === 0, summary: summary(run.output), noise: noise.slice(0, 20) };
    run.ok &&= noise.length === 0;
    console.log(`[${slug}] ${suite}: ${run.ok ? 'PASS' : 'FAIL'} ${results.suites[suite].summary}`);
    failed ||= !run.ok;
  }
  if (flag('e2e')) {
    console.log(`[${slug}] e2e (several minutes) ...`);
    const run = spawnSync(process.execPath, ['tests/e2e/run.mjs'], { cwd: ROOT, env: { ...env, STEALTHAUDIT_E2E_ENV: `wp-env-stealthaudit-matrix-${slug}`, STEALTHAUDIT_E2E_HTTP_PORT: port }, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    writeFileSync(join(OUT, 'e2e.log'), `${run.stdout}${run.stderr}`);
    results.suites['test:e2e'] = { ok: run.status === 0, summary: summary(`${run.stdout}`) };
    console.log(`[${slug}] test:e2e: ${run.status === 0 ? 'PASS' : 'FAIL'} ${results.suites['test:e2e'].summary}`);
    failed ||= run.status !== 0;
  }
} catch (error) {
  failed = true;
  results.error = String(error.message || error);
  console.error(`[${slug}] ${results.error}`);
} finally {
  // Diagnostics PHP wrote while serving HTTP (WP_DEBUG_DISPLAY is off, so they only reach the
  // Apache error log). Any line from StealthAudit's own files fails the run; others are reported.
  try {
    const web = execFileSync('docker', ['logs', container('wordpress')], { stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 256 * 1024 * 1024 });
    writeFileSync(join(OUT, 'web.log'), web);
    const php = (web.toString().match(/^.*PHP (?:Deprecated|Warning|Notice|Fatal error|Parse error):.*$/gm) || []);
    const own = php.filter((line) => line.includes('/mu-plugins/stealthaudit'));
    results.web = { php_diagnostics: php.length, stealthaudit: own.slice(0, 20), other: [...new Set(php.filter((line) => !own.includes(line)).map((line) => line.replace(/^\[[^\]]*\] /, '')))].slice(0, 20) };
    if (own.length) {
      failed = true;
      console.error(`[${slug}] web log has ${own.length} StealthAudit PHP diagnostics; see artifacts/matrix/${slug}/web.log`);
    }
  } catch (error) {
    results.web = { error: String(error.message || error).split('\n')[0] };
  }
  if (!flag('keep')) {
    console.log(`[${slug}] destroying the isolated environment ...`);
    const destroy = wpEnv(['destroy', '--force'], 'destroy.log');
    results.destroyed = destroy.ok;
    if (destroy.ok) rmSync(join(ROOT, `.wp-env.matrix-${slug}.json`), { force: true });
    if (!destroy.ok) console.error(`[${slug}] destroy failed; see artifacts/matrix/${slug}/destroy.log`);
  }
  results.finished = new Date().toISOString();
  writeFileSync(join(OUT, 'results.json'), JSON.stringify(results, null, 2) + '\n');
}
process.exit(failed ? 1 : 0);
