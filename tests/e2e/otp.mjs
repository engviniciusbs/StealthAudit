// SPDX-License-Identifier: GPL-3.0-or-later
// Independent QR decoding (jsQR over the pixels the browser painted) and RFC 6238 TOTP
// (node:crypto only). Shares no code with the plugin's PHP libraries.
import { createHmac } from 'node:crypto';
import jsQR from 'jsqr';
import { PNG } from 'pngjs';

export function base32(text) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const clean = text.toUpperCase().replace(/=+$/, '');
  let bits = '';
  for (const ch of clean) {
    const index = alphabet.indexOf(ch);
    if (index < 0) throw new Error('invalid base32');
    bits += index.toString(2).padStart(5, '0');
  }
  const bytes = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
  return Buffer.from(bytes);
}

export function hotp(key, counter, digits = 6, algorithm = 'sha1') {
  const message = Buffer.alloc(8);
  message.writeBigUInt64BE(BigInt(counter));
  const mac = createHmac(algorithm, key).update(message).digest();
  const offset = mac[mac.length - 1] & 0x0f;
  const binary = ((mac[offset] & 0x7f) << 24) | (mac[offset + 1] << 16) | (mac[offset + 2] << 8) | mac[offset + 3];
  return String(binary % 10 ** digits).padStart(digits, '0');
}

export function totp(key, seconds, { digits = 6, period = 30, algorithm = 'sha1' } = {}) {
  return hotp(key, Math.floor(seconds / period), digits, algorithm);
}

// RFC 6238 appendix B, SHA-1 rows (8 digits, ASCII key "12345678901234567890").
export function selfTest() {
  const key = Buffer.from('12345678901234567890');
  const rows = [[59, '94287082'], [1111111109, '07081804'], [1111111111, '14050471'], [1234567890, '89005924'], [2000000000, '69279037'], [20000000000, '65353130']];
  for (const [time, expected] of rows) {
    if (totp(key, time, { digits: 8 }) !== expected) throw new Error(`RFC 6238 vector failed at T=${time}`);
  }
  return rows.length;
}

export function parseOtpauth(uri) {
  const url = new URL(uri);
  if (url.protocol !== 'otpauth:' || url.host !== 'totp') throw new Error('not an otpauth TOTP URI');
  const params = Object.fromEntries(url.searchParams);
  return {
    label: decodeURIComponent(url.pathname.replace(/^\//, '')),
    secret: params.secret,
    issuer: params.issuer ?? null,
    algorithm: (params.algorithm ?? 'SHA1').toUpperCase(),
    digits: Number(params.digits ?? 6),
    period: Number(params.period ?? 30),
    params: Object.keys(params).sort(),
  };
}

const luminance = (r, g, b) => {
  const lin = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; };
  return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
};

// Decode a PNG screenshot of the QR element and measure what a camera needs.
export function decodeQr(png) {
  const image = PNG.sync.read(png);
  const code = jsQR(new Uint8ClampedArray(image.data.buffer, image.data.byteOffset, image.data.length), image.width, image.height);
  if (!code) throw new Error('jsQR found no QR code');
  const { topLeftFinderPattern: tl, topRightFinderPattern: tr } = code.location;
  const dimension = 17 + 4 * code.version;
  const module = Math.hypot(tr.x - tl.x, tr.y - tl.y) / (dimension - 7);
  const quiet = Math.min(tl.x - 3.5 * module, tl.y - 3.5 * module) / module;
  let dark = 255, light = 0;
  for (let i = 0; i < image.data.length; i += 4) {
    const y = luminance(image.data[i], image.data[i + 1], image.data[i + 2]);
    dark = Math.min(dark, y); light = Math.max(light, y);
  }
  return { text: code.data, version: code.version, dimension, modulePx: module, quietZoneModules: quiet, contrast: (light + 0.05) / (dark + 0.05), width: image.width, height: image.height };
}
