// SPDX-License-Identifier: GPL-3.0-or-later
// `npm run package` launcher (build tooling, never shipped). Exports the committed tree with
// `git archive` (so .gitattributes export-ignore applies) and runs tools/package.php with the
// PHP 8.3 + Composer of the wp-env test CLI container (npm run env:test:start). The workspace,
// its vendor/ and node_modules/ are never touched; the build happens in a temporary directory.
//
//   npm run package                                   dist/stealthaudit-<version>.zip (+ .sha256, .contents.txt, .build.json)
//   npm run package -- --allow-dirty                  include uncommitted TRACKED changes (marked dirty; not a release)
//   npm run package -- --package-version 0.1.0-dev+update --out artifacts/package-e2e   test-only marker build
import { execFileSync, spawnSync } from 'node:child_process';
import { mkdirSync, rmSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const argv = process.argv.slice(2);
const value = (name) => {
  const i = argv.indexOf(`--${name}`);
  return i >= 0 ? argv[i + 1] : undefined;
};
const git = (...args) => execFileSync('git', args, { cwd: ROOT, encoding: 'utf8' }).trim();

const commit = git('rev-parse', 'HEAD');
const changes = git('status', '--porcelain', '--untracked-files=no');
const dirty = changes !== '';
if (dirty && !argv.includes('--allow-dirty')) {
  console.error('[package] tracked files have uncommitted changes; commit them or pass --allow-dirty (the build is then marked dirty):');
  console.error(changes);
  process.exit(1);
}
// `git stash create` records the tracked working tree as a commit object without touching
// the index, the working tree or the stash list.
const tree = dirty ? git('stash', 'create') || commit : commit;
mkdirSync(join(ROOT, 'build'), { recursive: true });
const archive = 'build/package-source.tar';
git('archive', '--format=tar', '-o', archive, tree);

// ZIP entry times (SOURCE_DATE_EPOCH-style) = time of the last commit that touched anything the
// package is made from: shipped sources, Composer manifests or the build tooling. Reproducible per
// commit; later whenever packaged bytes can change (see the opcache note in
// docs/operations/installation.md); unchanged by docs-only commits, so their SHA-256 stays the same.
const PACKAGED = ['loader', 'bootstrap.php', 'src', 'assets', 'templates', 'bin', 'config', 'LICENSE', 'THIRD-PARTY-NOTICES.md', 'composer.json', 'composer.lock', 'tools/package.php', 'tools/package-lib.php', 'tools/scoper.inc.php', 'tools/notices.php', 'tools/package.mjs'];
const epoch = git('log', '-1', '--format=%ct', commit, '--', ...PACKAGED);
const args = ['tools/wp-env.cjs', 'run', 'cli', '--env-cwd=wp-content/mu-plugins/stealthaudit', 'php', 'tools/package.php', `--archive=${archive}`, `--commit=${commit}`, `--dirty=${dirty ? 1 : 0}`, `--source-date-epoch=${epoch}`];
if (value('package-version')) args.push(`--package-version=${value('package-version')}`);
if (value('out')) args.push(`--out=${value('out')}`);
const run = spawnSync(process.execPath, args, { cwd: ROOT, stdio: 'inherit', env: { ...process.env, MSYS_NO_PATHCONV: '1' } });
rmSync(join(ROOT, archive), { force: true });
process.exit(run.status ?? 1);
