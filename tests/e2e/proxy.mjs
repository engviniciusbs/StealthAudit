// SPDX-License-Identifier: GPL-3.0-or-later
// Development only: loopback TLS terminator with a shared HTTP cache in front of wp-env.
//
// - Self-signed certificate generated per run with OpenSSL into a temporary directory, removed on close.
// - Listens on 127.0.0.1 only and forwards to the wp-env Apache on 127.0.0.1:8880.
// - Overwrites X-Forwarded-Proto with "https" and drops every other client forwarding header;
//   the wp-env wp-config.php turns that header into $_SERVER['HTTPS'] = 'on' (is_ssl() is untouched).
// - Shared cache (RFC 9111 via http-cache-semantics, shared: true) keyed by method + URL, ignoring
//   cookies except through Vary, so any response it may store is visible in the log.
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import http from 'node:http';
import https from 'node:https';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import CachePolicy from 'http-cache-semantics';

const HOP = new Set(['connection', 'keep-alive', 'proxy-connection', 'transfer-encoding', 'upgrade', 'te', 'trailer']);

function certificate() {
  const dir = mkdtempSync(join(tmpdir(), 'stealthaudit-e2e-tls-'));
  const conf = join(dir, 'openssl.cnf');
  writeFileSync(conf, '[req]\ndistinguished_name=dn\nprompt=no\nx509_extensions=ext\n[dn]\nCN=localhost\n[ext]\nsubjectAltName=DNS:localhost,IP:127.0.0.1\nbasicConstraints=CA:FALSE\nkeyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n');
  execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-sha256', '-days', '1', '-keyout', join(dir, 'key.pem'), '-out', join(dir, 'cert.pem'), '-config', conf], { stdio: 'ignore' });
  const pair = { key: readFileSync(join(dir, 'key.pem')), cert: readFileSync(join(dir, 'cert.pem')) };
  rmSync(dir, { recursive: true, force: true });
  return pair;
}

export async function startProxy({ port = 8443, upstream = { host: '127.0.0.1', port: 8880 } } = {}) {
  const log = [];
  const cache = new Map();
  const tls = certificate();
  const fingerprint = execFileSync('openssl', ['x509', '-noout', '-fingerprint', '-sha256'], { input: tls.cert }).toString().trim();

  const server = https.createServer(tls, (req, res) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => {
      const body = Buffer.concat(chunks);
      const key = `${req.method} ${req.url}`;
      const request = { url: req.url, method: req.method, headers: req.headers };
      const entry = { method: req.method, path: req.url, origin: req.headers.origin || null, site: req.headers['sec-fetch-site'] || null, cookie: Boolean(req.headers.cookie) };
      const hit = cache.get(key);
      if (hit && hit.policy.satisfiesWithoutRevalidation(request)) {
        log.push({ ...entry, status: hit.status, cache: 'HIT', storable: true });
        res.writeHead(hit.status, { ...hit.policy.responseHeaders(), 'x-e2e-cache': 'HIT' });
        res.end(hit.body);
        return;
      }
      const headers = {};
      for (const [name, value] of Object.entries(req.headers)) {
        if (!HOP.has(name) && !name.startsWith('x-forwarded-') && name !== 'forwarded') headers[name] = value;
      }
      headers['x-forwarded-proto'] = 'https';
      headers['content-length'] = String(body.length);
      const upstreamReq = http.request({ ...upstream, method: req.method, path: req.url, headers }, (up) => {
        const parts = [];
        up.on('data', (c) => parts.push(c));
        up.on('end', () => {
          const payload = Buffer.concat(parts);
          const response = { status: up.statusCode, headers: up.headers };
          const policy = new CachePolicy(request, response, { shared: true });
          const storable = policy.storable();
          if (storable) cache.set(key, { policy, status: up.statusCode, body: payload });
          log.push({ ...entry, status: up.statusCode, cache: 'MISS', storable, cacheControl: up.headers['cache-control'] || null });
          const out = {};
          for (const [name, value] of Object.entries(up.headers)) if (!HOP.has(name)) out[name] = value;
          out['x-e2e-cache'] = 'MISS';
          out['content-length'] = String(payload.length);
          res.writeHead(up.statusCode, out);
          res.end(payload);
        });
      });
      upstreamReq.on('error', () => { res.writeHead(502); res.end(); });
      upstreamReq.end(body);
    });
  });
  await new Promise((resolve, reject) => server.once('error', reject).listen(port, '127.0.0.1', resolve));
  return {
    origin: `https://localhost:${port}`,
    fingerprint,
    log,
    cache,
    close: () => new Promise((resolve) => { server.closeAllConnections?.(); server.close(() => resolve()); }),
  };
}

// A different site (127.0.0.1 vs localhost) serving a page that tries cross-site requests.
export async function startAttacker({ port = 8444, target }) {
  const page = `<!doctype html><meta charset="utf-8"><title>attacker</title>
<form id="f" method="post" enctype="text/plain" action="${target}/?rest_route=/stealthaudit/v1/revoke"><input name='{"a":"' value='"}'></form>
<img id="i" src="${target}/?rest_route=/stealthaudit/v1/events" alt="">
<script>
window.results = {};
fetch(${JSON.stringify(target + '/?rest_route=/stealthaudit/v1/events')}, { credentials: 'include' }).then(r => { window.results.read = r.status; }, () => { window.results.read = 'blocked'; });
fetch(${JSON.stringify(target + '/?rest_route=/stealthaudit/v1/revoke')}, { method: 'POST', mode: 'no-cors', credentials: 'include', headers: { 'Content-Type': 'text/plain' }, body: '{}' }).then(() => { window.results.post = 'sent'; }, () => { window.results.post = 'blocked'; });
</script>`;
  const server = http.createServer((req, res) => { res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' }); res.end(page); });
  await new Promise((resolve, reject) => server.once('error', reject).listen(port, '127.0.0.1', resolve));
  return { origin: `http://127.0.0.1:${port}`, close: () => new Promise((resolve) => server.close(() => resolve())) };
}
