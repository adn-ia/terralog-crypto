// Behaviour tests: what must fail, fails. A changed byte, a wrong key, a truncated file,
// a swapped header must never come back as plausible clear data.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import * as tc from '../src/terralog-crypto.mjs';

const C = tc.createTerralogCrypto({ subtle: webcrypto.subtle, getRandomValues: (a) => webcrypto.getRandomValues(a) });
const bytes = (n) => {   // getRandomValues is capped at 65 536 bytes per call
  const u = new Uint8Array(n);
  for (let i = 0; i < n; i += 65536) webcrypto.getRandomValues(u.subarray(i, Math.min(n, i + 65536)));
  return u;
};
const flip = (u, i) => { const c = u.slice(); c[i] ^= 1; return c; };
const keyA = await C.contentKey('material-A-' + 'x'.repeat(32));
const keyB = await C.contentKey('material-B-' + 'x'.repeat(32));

test('factory refuses missing dependencies', () => {
  assert.throws(() => tc.createTerralogCrypto({}), TypeError);
});

test('b64uDecode is strict', () => {
  for (const bad of ['a', 'ab=', 'a+b/', 'é', 'abcde']) assert.throws(() => tc.b64uDecode(bad), /base64url/);
  assert.equal(tc.b64uDecode('').length, 0);
});

test('notebook inputs: case and spacing do not change the notebook, one character does', async () => {
  const a = await C.deriveNotebook('Ana', '111111', 'une phrase assez longue pour tenir');
  const b = await C.deriveNotebook('  ANA ', '111111', 'Une  Phrase  assez longue pour tenir ');
  const c = await C.deriveNotebook('Ana', '111112', 'une phrase assez longue pour tenir');
  assert.deepEqual(a, b);
  assert.notEqual(a.link, c.link);
  // The separator prevents ('Ana1', '11111') from colliding with ('Ana', '111111').
  const d = await C.deriveNotebook('Ana1', '11111', 'une phrase assez longue pour tenir');
  assert.notEqual(a.link, d.link);
});

test('reading material is per notebook', async () => {
  const m = 'master-' + 'y'.repeat(36);
  assert.notEqual(await C.readingMaterialV2(m, 'notebookOne_xxxxxxxxxxxxxxxx'),
                  await C.readingMaterialV2(m, 'notebookTwo_xxxxxxxxxxxxxxxx'));
});

test('text: round trip, fresh IV, empty stays empty', async () => {
  const s1 = await C.encryptText(keyA, 'Bonjour');
  const s2 = await C.encryptText(keyA, 'Bonjour');
  assert.notEqual(s1, s2);
  assert.equal(await C.decryptText(keyA, s1), 'Bonjour');
  for (const empty of ['', null, undefined]) assert.equal(await C.encryptText(keyA, empty), empty);
  await assert.rejects(C.decryptText(keyA, 'Bonjour'), /not-encrypted/);
});

test('text: wrong key, changed byte, truncation all fail', async () => {
  const s = await C.encryptText(keyA, 'secret');
  await assert.rejects(C.decryptText(keyB, s));
  const raw = tc.b64uDecode(s.slice(5));
  for (const i of [0, 11, 12, raw.length - 1]) {
    await assert.rejects(C.decryptText(keyA, 'RTC1:' + tc.b64uEncode(flip(raw, i))), undefined, 'byte ' + i);
  }
  await assert.rejects(C.decryptText(keyA, 'RTC1:' + tc.b64uEncode(raw.slice(0, 27))), /short/);
  // Several keys: the right one is found whatever its place.
  assert.equal(await C.decryptText([keyB, keyA], s), 'secret');
});

test('media RTC1: round trip; any changed byte fails', async () => {
  const data = bytes(5000);
  const out = await C.encryptMediaV1(keyA, data, 'image/jpeg');
  assert.equal(String.fromCharCode(...out.slice(0, 4)), 'RTC1');
  const back = await C.decryptMedia(keyA, out);
  assert.equal(back.type, 'image/jpeg');
  assert.deepEqual(back.bytes, data);
  for (const i of [4, 15, 16, 100, out.length - 1]) await assert.rejects(C.decryptMedia(keyA, flip(out, i)));
  await assert.rejects(C.decryptMedia(keyB, out));
  await assert.rejects(C.decryptMedia(keyA, data), /format/);
});

test('media RTC1: the type is inside the ciphertext', async () => {
  const out = await C.encryptMediaV1(keyA, bytes(10), 'video/mp4');
  assert.equal(Buffer.from(out).indexOf('video/mp4'), -1);
});

test('media RTC2: the header is authenticated (epoch, size, mark)', async () => {
  const data = bytes(3000);
  const out = await C.encryptMediaV2(keyA, data, 'audio/mp4', 7);
  const back = await C.decryptMedia(keyA, out);
  assert.equal(back.epoch, 7);
  assert.deepEqual(back.bytes, data);
  for (const i of [16, 17, 20]) await assert.rejects(C.decryptMedia(keyA, flip(out, i)), undefined, 'header byte ' + i);
});

test('backup envelope: short phrase refused, wrong phrase gives null, iterations bounded', async () => {
  const K = C.newBackupKey();
  await assert.rejects(C.wrapBackupKey(K, 'trop courte'), /phrase/);
  const env = await C.wrapBackupKey(K, 'une phrase de sauvegarde assez longue');
  assert.equal(env.tours, 600000);
  assert.equal(await C.unwrapBackupKey(env, 'une autre phrase de sauvegarde longue'), null);
  assert.equal(await C.unwrapBackupKey({ ...env, ct: env.ct.slice(0, 10) }, 'une phrase de sauvegarde assez longue'), null);
  // A hostile iteration count is clamped (to 1 200 000), not obeyed: the unwrap fails fast-ish
  // instead of freezing. Here the count changes the KEK, so the result is null.
  assert.equal(await C.unwrapBackupKey({ ...env, tours: 1e12 }, 'une phrase de sauvegarde assez longue'), null);
});

test('backup file: round trip across chunk boundaries', async () => {
  const K = await C.importBackupKey(C.newBackupKey());
  for (const n of [0, 1, 1048576, 1048577]) {
    const data = bytes(n);
    const out = await C.encryptBackupFile(K, data, { kind: 'media', envelope: { sel: 'a' }, uid: 'u' });
    assert.deepEqual(await C.decryptBackupFile(K, out), data, n + ' bytes');
  }
});

test('backup file: truncation, extra bytes, header edits, chunk swap all fail', async () => {
  const K = await C.importBackupKey(C.newBackupKey());
  const data = bytes(1048576 + 100);
  const out = await C.encryptBackupFile(K, data, { kind: 'media', envelope: { sel: 'a' } });
  await assert.rejects(C.decryptBackupFile(K, out.slice(0, out.length - 1)), /truncated/);
  await assert.rejects(C.decryptBackupFile(K, new Uint8Array([...out, 0])), /length/);
  // Drop the last chunk and claim one chunk only: the header is the AAD, so this fails.
  const h = C.readBackupHeader(out);
  const forged = JSON.stringify({ ...h.header, nbTranches: 1, tailleClair: 1048576 });
  const fb = new TextEncoder().encode(forged);
  const len = new Uint8Array([fb.length & 255, (fb.length >> 8) & 255, 0, 0]);
  const firstChunk = out.slice(h.start, h.start + 1048576 + 16);
  const cut = new Uint8Array([...new TextEncoder().encode('TLGS1\n'), ...len, ...fb, ...firstChunk]);
  await assert.rejects(C.decryptBackupFile(K, cut), /tampered/);
  // A byte changed in the clear header (still valid JSON) fails too.
  const s = Buffer.from(out); const at = s.indexOf('"media"') + 1; s[at] = 'M'.charCodeAt(0);
  await assert.rejects(C.decryptBackupFile(K, new Uint8Array(s)), /tampered/);
  // Wrong key.
  await assert.rejects(C.decryptBackupFile(await C.importBackupKey(C.newBackupKey()), out), /tampered/);
});

test('backup container: pack and unpack', () => {
  const files = [bytes(10), bytes(0), bytes(300)];
  const back = tc.unpackContainer(tc.packContainer(files));
  assert.deepEqual(back, files);
  assert.throws(() => tc.unpackContainer(tc.packContainer(files).slice(0, 20)), /truncated/);
});

test('b64u: long inputs round-trip, non-canonical tails are refused', () => {
  for (const n of [100, 1000, 4097]) {
    const b = bytes(n);
    assert.deepEqual(tc.b64uDecode(tc.b64uEncode(b)), b);
  }
  assert.equal(tc.b64uEncode(new Uint8Array([0xff])), '_w');
  assert.throws(() => tc.b64uDecode('_x'), /base64url/);   // same byte, unused bits set
});

test('a random source that does not fill the array is refused', async () => {
  const bad = tc.createTerralogCrypto({ subtle: webcrypto.subtle, getRandomValues: (a) => new Uint8Array(a.length) });
  await assert.rejects(bad.encryptText(keyA, 'x'), /rng/);
});

test('isolation: a text or media encrypted for one notebook does not open with another', async () => {
  const s = await C.encryptText(keyA, 'privé');
  await assert.rejects(C.decryptText(keyB, s));
  const m = await C.encryptMediaV1(keyA, bytes(100), 'image/png');
  await assert.rejects(C.decryptMedia(keyB, m));
  assert.equal((await C.decryptMedia([keyB, keyA], m)).type, 'image/png');   // several keys, as the app tries them
});

test('media RTC1: type length limits', async () => {
  const t120 = 'é'.repeat(120);                         // 240 bytes: fits in the length byte
  assert.equal((await C.decryptMedia(keyA, await C.encryptMediaV1(keyA, bytes(5), t120))).type, t120);
  await assert.rejects(C.encryptMediaV1(keyA, bytes(5), '€'.repeat(100)), /type-too-long/);   // 300 bytes (3 per character)
});

test('backup file: swapping two chunks fails (index is in the nonce and the AAD)', async () => {
  const K = await C.importBackupKey(C.newBackupKey());
  const out = await C.encryptBackupFile(K, bytes(2 * 1048576), { kind: 'media', envelope: { sel: 'a' } });
  const h = C.readBackupHeader(out);
  const L = 1048576 + 16, a = out.slice(h.start, h.start + L), b = out.slice(h.start + L, h.start + 2 * L);
  const swapped = new Uint8Array(out.length);
  swapped.set(out.subarray(0, h.start)); swapped.set(b, h.start); swapped.set(a, h.start + L);
  await assert.rejects(C.decryptBackupFile(K, swapped), /tampered/);
});

test('backup file: the uid of another media is refused before decryption', async () => {
  const K = await C.importBackupKey(C.newBackupKey());
  const out = await C.encryptBackupFile(K, bytes(50), { kind: 'media', envelope: { sel: 'a' }, uid: 'photo-1' });
  assert.equal((await C.decryptBackupFile(K, out, { expectedUid: 'photo-1' })).length, 50);
  await assert.rejects(C.decryptBackupFile(K, out, { expectedUid: 'photo-2' }), /swapped/);
});

test('backup file: hostile header numbers are refused', async () => {
  const K = await C.importBackupKey(C.newBackupKey());
  const out = await C.encryptBackupFile(K, bytes(10), { kind: 'media', envelope: { sel: 'a' } });
  const h = C.readBackupHeader(out);
  const forge = (patch) => {
    const fb = new TextEncoder().encode(JSON.stringify({ ...h.header, ...patch }));
    const len = new Uint8Array([fb.length & 255, (fb.length >> 8) & 255, 0, 0]);
    return new Uint8Array([...new TextEncoder().encode('TLGS1\n'), ...len, ...fb, ...out.subarray(h.start)]);
  };
  for (const patch of [{ nbTranches: 1000001 }, { tranche: tc.BACKUP_CHUNK_MAX + 1 }, { tailleClair: -1 }, { idFichier: 'abc' }, { version: 2 }]) {
    await assert.rejects(C.decryptBackupFile(K, forge(patch)), /header/, JSON.stringify(patch));
  }
});

test('backup envelope: an iteration count below the floor is raised, not obeyed', async () => {
  const K = C.newBackupKey(), p = 'une phrase de sauvegarde assez longue';
  const env = await C.wrapBackupKey(K, p);
  // Written with 600 000; read with tours = 1000 → clamped to 100 000 → different KEK → null.
  assert.equal(await C.unwrapBackupKey({ ...env, tours: 1000 }, p), null);
  assert.deepEqual(await C.unwrapBackupKey({ ...env, tours: '600000' }, p), K);
});

test('backup container: empty is refused at writing', () => {
  assert.throws(() => tc.packContainer([]), /empty/);
});

// An envelope written by hand with any iteration count, to prove the bounds are applied.
async function envelopeAt(K, phrase, iterations) {
  const salt = bytes(16), iv = bytes(12);
  const base = await webcrypto.subtle.importKey('raw', new TextEncoder().encode(tc.normalizeBackupPhrase(phrase)), 'PBKDF2', false, ['deriveKey']);
  const kek = await webcrypto.subtle.deriveKey({ name: 'PBKDF2', hash: 'SHA-256', salt, iterations }, base, { name: 'AES-GCM', length: 256 }, false, ['encrypt']);
  const ct = new Uint8Array(await webcrypto.subtle.encrypt({ name: 'AES-GCM', iv }, kek, K));
  return { sel: tc.b64uEncode(salt), iv: tc.b64uEncode(iv), ct: tc.b64uEncode(ct) };
}

test('backup envelope: the iteration count read from a file is bounded on both sides', async () => {
  const K = C.newBackupKey(), p = 'une phrase de sauvegarde assez longue';
  // Floor: a file honestly written at 50 000 does not open (the reader uses 100 000).
  const low = await envelopeAt(K, p, 50000);
  assert.equal(await C.unwrapBackupKey({ ...low, tours: 50000 }, p), null);
  const atFloor = await envelopeAt(K, p, 100000);
  assert.deepEqual(await C.unwrapBackupKey({ ...atFloor, tours: 1 }, p), K);
  // Ceiling: a header claiming 10^12 iterations is read with 1 200 000, so it opens an
  // envelope made with 1 200 000 instead of freezing.
  const atCeiling = await envelopeAt(K, p, 1200000);
  assert.deepEqual(await C.unwrapBackupKey({ ...atCeiling, tours: 1e12 }, p), K);
});

test('media RTC2: a type cut at 200 bytes in the middle of a character decodes like the app (U+FFFD)', async () => {
  const type = 'a'.repeat(199) + 'é';                  // 201 bytes; the cut falls inside 'é'
  const back = await C.decryptMedia(keyA, await C.encryptMediaV2(keyA, bytes(3), type));
  assert.equal(back.type, 'a'.repeat(199) + '�');  // TextDecoder, non-fatal, as in 18-coffre.js
  assert.equal(back.bytes.length, 3);
});
