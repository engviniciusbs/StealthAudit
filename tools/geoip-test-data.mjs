// SPDX-License-Identifier: GPL-3.0-or-later
// Development only: fetch MaxMind's official MMDB *test* files into artifacts/geoip (gitignored).
//
//   node tools/geoip-test-data.mjs
//
// Source: https://github.com/maxmind/MaxMind-DB (test-data/), pinned to one commit and verified
// by SHA-256. Those files are synthetic test data, Copyright MaxMind, Inc., dual-licensed
// Apache-2.0 or MIT (repository README); LICENSE-MIT is fetched alongside them. They are never
// committed, packaged or used as a real GeoLite/GeoIP database, and no license key is involved.
// Files already present with the expected hash are kept, so repeated runs make no request.
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, renameSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const COMMIT = 'd12e37856df12a62bb4647da66d20feddb8783a6';
const FILES = {
  'test-data/GeoIP2-City-Test.mmdb': '1394af009121820d43b620fea19fc4b6f09141698091c00f70f225a982389035',
  'test-data/GeoIP2-Country-Test.mmdb': 'fc12d6470b35b70bd3e4534dd433d3e262a6c73f7682f85c344e832d4190f02b',
  'test-data/GeoIP2-Anonymous-IP-Test.mmdb': '0c824f38e31ff020be2b52a10f05830a9b8775cf31110088cf6fb5a2ed22c47f',
  'test-data/GeoIP2-City-Test-Broken-Double-Format.mmdb': 'a340a6871b8bee8351befb8ad26f5229453705dddee0e948a35a65916c931e9c',
  'LICENSE-MIT': '91276db973f25602d1aa43491f59cbc84cb88e6f151e1d0cc82a755563ce0195',
};
const TIMEOUT_MS = 30_000; // per file; a stalled network fails the run instead of hanging it
const OUT = join(resolve(dirname(fileURLToPath(import.meta.url)), '..'), 'artifacts/geoip');
const sha256 = (bytes) => createHash('sha256').update(bytes).digest('hex');

mkdirSync(OUT, { recursive: true });
let fetched = 0;
for (const [source, expected] of Object.entries(FILES)) {
  const target = join(OUT, source.split('/').pop());
  if (existsSync(target) && sha256(readFileSync(target)) === expected) continue;
  const url = `https://raw.githubusercontent.com/maxmind/MaxMind-DB/${COMMIT}/${source}`;
  const response = await fetch(url, { redirect: 'error', signal: AbortSignal.timeout(TIMEOUT_MS) });
  if (!response.ok) throw new Error(`GeoIP test data: HTTP ${response.status} for ${source}`);
  const bytes = Buffer.from(await response.arrayBuffer());
  if (sha256(bytes) !== expected) throw new Error(`GeoIP test data: checksum mismatch for ${source}; nothing written`);
  writeFileSync(`${target}.part`, bytes);
  renameSync(`${target}.part`, target);
  ++fetched;
}
console.log(`GeoIP test data ready in artifacts/geoip (MaxMind-DB @ ${COMMIT.slice(0, 12)}, ${fetched} fetched)`);
