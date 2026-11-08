// SPDX-License-Identifier: GPL-3.0-or-later
// Safety adapter for the pinned wp-env 12.1.0 Docker runtime. Upstream publishes
// HTTP/MySQL on every interface; constrain all generated ports before startup.
//
//   node tools/wp-env.cjs [--development] <wp-env command...>
//   node tools/wp-env.cjs --matrix=<php>,<db>[,prefix=<p>][,collate=<c>][,port=<n>][,tag=<word>][,source=none] <wp-env command...>
//
// --matrix selects an ADDITIONAL isolated compatibility environment (see tools/matrix.mjs and
// docs/testing/verification.md): its own generated, gitignored config file, WP_ENV_HOME,
// compose project, volumes and port. It never touches the development (8870) or test (8880)
// environments. Images are exact tags only; wp-env's floating `wordpress:php8.x` bases and the
// unpinned phpMyAdmin service are replaced or dropped, and the database may be MySQL.
// tag=<word> only names a separate environment for the same combination (benchmarks/run.mjs
// uses tag=bench), so a benchmark never shares containers or volumes with a matrix run.
// source=none (tests/package/run.mjs) builds a CLEAN site for the packaged ZIP: the repository is
// NOT mapped into WordPress (the ZIP is installed into wp-content/mu-plugins instead), Composer
// and its global PHPUnit are removed from both images, and the repository is mounted only in
// the CLI container at /opt/stealthaudit-dev, outside the webroot, for the test harness.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');

const ROOT = path.resolve(__dirname, '..');
const PHP_IMAGES = {
  '8.3': { web: 'wordpress:7.1.4-php8.3-apache', cli: 'wordpress:cli-2.12.0-php8.3' },
  '8.4': { web: 'wordpress:7.1.4-php8.4-apache', cli: 'wordpress:cli-2.12.0-php8.4' },
  '8.5': { web: 'wordpress:7.1.4-php8.5-apache', cli: 'wordpress:cli-2.12.0-php8.5' },
};

function parseMatrix(spec) {
  const [php, db, ...extra] = spec.split(',');
  if (!PHP_IMAGES[php]) throw new Error(`--matrix: PHP must be one of ${Object.keys(PHP_IMAGES).join(', ')}`);
  if (!/^(mariadb|mysql):\d+\.\d+\.\d+$/.test(db || '')) throw new Error('--matrix: database must be an exact image tag such as mariadb:10.11.19 or mysql:8.4.13');
  const options = { php, db, prefix: null, collate: null, port: 8881, tag: null, source: true };
  for (const item of extra) {
    const [key, value] = item.split('=');
    if (key === 'prefix' && /^[a-z][a-z0-9_]{0,15}_$/.test(value)) options.prefix = value;
    else if (key === 'collate' && /^[a-z0-9_]{1,64}$/.test(value)) options.collate = value;
    else if (key === 'tag' && /^[a-z]{1,12}$/.test(value)) options.tag = value;
    else if (key === 'source' && value === 'none') options.source = false;
    else if (key === 'port' && /^\d{4,5}$/.test(value) && ![8870, 8880].includes(Number(value))) options.port = Number(value);
    else throw new Error(`--matrix: invalid option ${item}`);
  }
  const extras = [options.prefix && `p${options.prefix.replace(/_/g, '')}`, options.collate && `c${options.collate.replace(/^utf8mb4_/, '').replace(/_/g, '')}`, options.tag].filter(Boolean);
  options.slug = [`php${php.replace('.', '')}`, db.replace(/[:.]/g, ''), ...extras].join('-');
  return options;
}

const args = process.argv.slice(2);
const development = args[0] === '--development';
if (development) args.shift();
const matrixArg = args[0]?.startsWith('--matrix=') ? args.shift().slice('--matrix='.length) : null;
const matrix = matrixArg ? parseMatrix(matrixArg) : null;

let configFile = development ? '.wp-env.json' : '.wp-env.tests.json';
if (matrix) {
  configFile = `.wp-env.matrix-${matrix.slug}.json`;
  const base = JSON.parse(fs.readFileSync(path.join(ROOT, '.wp-env.tests.json'), 'utf8'));
  // Generated each time from the test config; gitignored (/.wp-env.matrix-*.json).
  const site = { ...base, phpVersion: matrix.php, port: matrix.port };
  if (!matrix.source) site.mappings = {};
  fs.writeFileSync(path.join(ROOT, configFile), JSON.stringify(site, null, 2) + '\n');

  // wp-env writes FROM wordpress:php<v> / wordpress:cli-php<v>; pin the exact tags instead.
  // Patched before wp-env's docker-config module captures fs.promises.writeFile.
  const write = fs.promises.writeFile;
  fs.promises.writeFile = (file, data, ...rest) => {
    // Tests-*.Dockerfile belong to the disabled tests environment and are never built.
    if (typeof file === 'string' && /(^|[\\/])(WordPress|CLI)\.Dockerfile$/.test(file) && typeof data === 'string') {
      const images = PHP_IMAGES[matrix.php];
      data = data.replace(/^FROM wordpress:cli-php[\d.]+$/m, `FROM ${images.cli}`).replace(/^FROM wordpress:php[\d.]+$/m, `FROM ${images.web}`);
      if (!data.includes(`FROM ${images.cli}`) && !data.includes(`FROM ${images.web}`)) throw new Error(`matrix: unexpected Dockerfile base in ${file}`);
      // WP-CLI 2.12.0 itself raises PHP 8.5 deprecations before WordPress loads; on the CLI
      // image send displayed diagnostics to stderr so they stay visible but cannot corrupt
      // the JSON the e2e fixture prints on stdout. Apache is unchanged, and tools/matrix.mjs
      // scans both streams of the suites for PHP diagnostics.
      if (/(^|[\\/])CLI\.Dockerfile$/.test(file)) data = data.replace(/^USER www-data$/m, "RUN echo 'display_errors = stderr' >> /usr/local/etc/php/php.ini\nUSER www-data");
      if (!matrix.source) {
        // A WordPress host without Composer: drop the binary and the global PHPUnit it installed.
        const strip = 'USER root\nRUN rm -f /usr/local/bin/composer && rm -rf /home/*/.composer /root/.composer';
        data = /(^|[\\/])CLI\.Dockerfile$/.test(file) ? data.replace(/^USER www-data$/m, `${strip}\nUSER www-data`) : `${data}\n${strip}\n`;
      }
    }
    return write.call(fs.promises, file, data, ...rest);
  };
}

const root = path.dirname(require.resolve('@wordpress/env/package.json'));
const builderPath = path.join(root, 'lib/runtime/docker/build-docker-compose-config.js');
const build = require(builderPath);
require.cache[builderPath].exports = (...builderArgs) => {
  const config = build(...builderArgs);
  for (const service of Object.values(config.services)) {
    if (service.ports) service.ports = service.ports.map(port => `127.0.0.1:${port}`);
  }
  if (matrix) {
    delete config.services.phpmyadmin; // unpinned image and not needed by the suites
    const db = config.services.mysql;
    db.image = matrix.db;
    if (matrix.db.startsWith('mysql:')) {
      delete db.environment.MARIADB_AUTO_UPGRADE;
      // The init-time server listens only on the socket, so a TCP ping means the real server.
      db.healthcheck = { ...db.healthcheck, test: ['CMD-SHELL', 'mysqladmin ping -h 127.0.0.1 --protocol=tcp -uroot -p"$$MYSQL_ROOT_PASSWORD" --silent'] };
    }
    if (!matrix.source) {
      // Harness only (tests/e2e/fixture.php, tests/package/*.php, artifacts/): CLI container, outside the webroot.
      config.services.cli.volumes = [...config.services.cli.volumes, `${ROOT}:/opt/stealthaudit-dev`];
    }
    for (const name of ['wordpress', 'cli']) {
      const environment = config.services[name].environment;
      if (matrix.prefix) environment.WORDPRESS_TABLE_PREFIX = matrix.prefix;
      if (matrix.collate) environment.WORDPRESS_DB_COLLATE = matrix.collate;
    }
  }
  return config;
};

const home = matrix ? '.wp-env-stealthaudit-matrix' : development ? '.wp-env-stealthaudit-dev' : '.wp-env-stealthaudit-tests';
process.env.WP_ENV_HOME = path.join(os.homedir(), home);
process.argv = [process.execPath, path.join(root, 'bin/wp-env'), ...args, '--config=' + configFile];
require(path.join(root, 'lib/cli.js'))().parse(process.argv.slice(2));
