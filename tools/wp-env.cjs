// SPDX-License-Identifier: GPL-3.0-or-later
// Safety adapter for the pinned wp-env 12.1.0 Docker runtime. Upstream publishes
// HTTP/MySQL on every interface; constrain all generated ports before startup.
const path = require('node:path');
const os = require('node:os');
const root = path.dirname(require.resolve('@wordpress/env/package.json'));
const builderPath = path.join(root, 'lib/runtime/docker/build-docker-compose-config.js');
const build = require(builderPath);
require.cache[builderPath].exports = (...args) => {
  const config = build(...args);
  for (const service of Object.values(config.services)) {
    if (service.ports) service.ports = service.ports.map(port => `127.0.0.1:${port}`);
  }
  return config;
};
const args = process.argv.slice(2);
const development = args[0] === '--development';
if (development) args.shift();
process.env.WP_ENV_HOME = path.join(os.homedir(), development ? '.wp-env-stealthaudit-dev' : '.wp-env-stealthaudit-tests');
process.argv = [process.execPath, path.join(root, 'bin/wp-env'), ...args, '--config=' + (development ? '.wp-env.json' : '.wp-env.tests.json')];
require(path.join(root, 'lib/cli.js'))().parse(process.argv.slice(2));
