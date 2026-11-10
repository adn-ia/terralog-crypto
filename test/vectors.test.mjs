// Known-answer tests. test/vectors.json was produced by running the Terralog app code itself
// (not this module) with a replayed random source; every value below must come out identical.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { webcrypto } from 'node:crypto';
import * as tc from '../src/terralog-crypto.mjs';

const v = JSON.parse(fs.readFileSync(new URL('./vectors.json', import.meta.url), 'utf8'));
const hex = (u) => Buffer.from(u).toString('hex');
const fromHex = (s) => new Uint8Array(Buffer.from(s, 'hex'));
const sha = async (u) => hex(new Uint8Array(await webcrypto.subtle.digest('SHA-256', u)));
const pattern = (n) => { const u = new Uint8Array(n); for (let i = 0; i < n; i++) u[i] = (i * 31 + 7) & 255; return u; };

/** A module whose random source replays the bytes the app drew. */
function replaying(draws) {
  const queue = draws.slice();
  return tc.createTerralogCrypto({
    subtle: webcrypto.subtle,
    getRandomValues(a) {
      const h = queue.shift();
      assert.ok(h && h.length === a.length * 2, 'random draw out of step');
      a.set(fromHex(h));
      return a;
    }
  });
}
const plain = tc.createTerralogCrypto({ subtle: webcrypto.subtle, getRandomValues: (a) => webcrypto.getRandomValues(a) });

test('normalization', () => {
  for (const n of v.normalize) {
    assert.equal(tc.normalizeNotebookInput(n.input), n.notebook, JSON.stringify(n.input));
    assert.equal(tc.normalizeBackupPhrase(n.input), n.backup, JSON.stringify(n.input));
  }
});

test('base64url', () => {
  for (const x of v.b64u) {
    assert.equal(tc.b64uEncode(fromHex(x.hex)), x.b64u);
    assert.equal(hex(tc.b64uDecode(x.b64u)), x.hex);
  }
});

test('notebook derivation (PBKDF2, 600 000 iterations)', async () => {
  for (const n of v.notebook) {
    const r = await plain.deriveNotebook(n.firstName, n.code, n.passphrase);
    assert.deepEqual(r, { link: n.link, ownerToken: n.ownerToken, master: n.master });
  }
});

test('trip derivation', async () => {
  for (const t of v.trip) {
    const r = await plain.deriveTrip(t.master, t.tripName, t.created);
    assert.deepEqual(r, { link: t.link, ownerToken: t.ownerToken });
  }
});

test('reading material v2 and v1', async () => {
  const m = v.readingMaterial;
  assert.equal(await plain.readingMaterialV2(m.master, m.notebookAddress), m.v2);
  assert.equal(await plain.readingMaterialV1(m.master), m.v1);
});

test('text fields (RTC1:)', async () => {
  for (const t of v.text) {
    const M = replaying(t.random);
    const key = await M.contentKey(t.material);
    assert.equal(await M.encryptText(key, t.text), t.ciphertext);
    assert.equal(await plain.decryptText(key, t.ciphertext), t.text);
  }
});

test('media RTC1', async () => {
  for (const m of v.mediaV1) {
    const M = replaying(m.random);
    const key = await M.contentKey(m.material);
    const out = await M.encryptMediaV1(key, pattern(m.size), m.type);
    assert.equal(await sha(out), m.sha256);
    assert.equal(hex(out.slice(0, 48)), m.head);
    const back = await plain.decryptMedia(key, out);
    assert.equal(back.format, 'RTC1');
    assert.equal(back.type, m.type);
    assert.equal(hex(back.bytes), hex(pattern(m.size)));
  }
});

test('media RTC2', async () => {
  for (const m of v.mediaV2) {
    const M = replaying(m.random);
    const key = await M.contentKey(m.material);
    const out = await M.encryptMediaV2(key, pattern(m.size), m.type, m.epoch === null ? undefined : m.epoch);
    assert.equal(await sha(out), m.sha256);
    const back = await plain.decryptMedia(key, out);
    assert.equal(back.format, 'RTC2');
    assert.equal(back.type, m.type);
    assert.equal(back.epoch, m.epoch === null ? 0 : m.epoch);
    assert.equal(hex(back.bytes), hex(pattern(m.size)));
  }
});

test('backup key envelope', async () => {
  const b = v.backupKey;
  const M = replaying(b.envelopeRandom);
  assert.deepEqual(await M.wrapBackupKey(fromHex(b.key), b.phrase), b.envelope);
  assert.equal(hex(await plain.unwrapBackupKey(b.envelope, b.phrase)), b.key);
  // The passphrase is normalized: case and spacing do not matter.
  assert.equal(hex(await plain.unwrapBackupKey(b.envelope, b.phrase.toUpperCase().replace(/ /g, '   '))), b.key);
  assert.equal(await plain.unwrapBackupKey(b.envelope, b.phrase + 'x'), null);
});

test('backup files (.tlg)', async () => {
  const b = v.backupKey;
  const key = await plain.importBackupKey(fromHex(b.key));
  for (const f of v.backup) {
    const M = replaying(f.random);
    const out = await M.encryptBackupFile(key, pattern(f.size), { kind: f.kind, envelope: b.envelope, uid: f.uid });
    assert.equal(await sha(out), f.sha256, f.size + ' bytes');
    assert.equal(hex(await plain.decryptBackupFile(key, out)), hex(pattern(f.size)));
    const h = plain.readBackupHeader(out);
    assert.deepEqual(h.header.enveloppe, b.envelope);
  }
});

test('server-side fingerprints match PHP', async () => {
  const s = v.server;
  assert.equal(await plain.linkFingerprint(s.link), s.linkFingerprint);
  assert.equal(await plain.ownerTokenFingerprint(s.token, s.salt), s.ownerTokenFingerprint);
});
