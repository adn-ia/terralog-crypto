# Terralog encryption — format specification

Version of this document: 1 (November 2026). It describes what the app writes and reads
today, including its weaknesses. Section 9 lists the known limits; please read it before
anything else if you are reviewing.

Notation: `‖` is concatenation, `SEP` is the single byte 0x1F (unit separator), strings are
UTF-8, `b64u` is base64url without padding (RFC 4648 §5), `H` is SHA-256.

## 1. The model in one paragraph

A person keeps a travel journal (a *notebook*) on their phone and shares it with family
through a link. There is no account. The relay (a small PHP server) stores and serves
encrypted rows and files; it is the adversary this design is meant to defeat: someone who
reads the server's disk or database should not learn what people wrote, photographed, or
where they went. The owner re-finds their notebook on a new phone by typing three things:
a first name, a 6-digit code, and a passphrase. Everything else is derived from those.

## 2. Notebook derivation

```
material = norm(firstName) ‖ SEP ‖ norm(code) ‖ SEP ‖ norm(passphrase)
out      = PBKDF2-HMAC-SHA256(material, salt = "roadtrip.carnet/v1", 600 000 iterations, 768 bits)
link       = b64u(out[0..32])     notebook address, given to the relay
ownerToken = b64u(out[32..64])    proves the right to write
master     = b64u(out[64..96])    never leaves the device
norm(t)    = NFKC(t), trimmed, lowercased, runs of whitespace replaced by one space
```

- The salt is fixed and public. It cannot be random: a new phone must recompute the same
  address from the three inputs alone, without asking the server anything.
- The passphrase must be at least four words, or, for scripts written without spaces, 8 signs
  (CJK) or 12 (Hangul). Since November 2026, for **new notebooks only**, it must also reach 16
  signs once spaces and punctuation are removed (12 for Hangul, 8 for CJK), and is refused if
  it is all digits, a repetition, or a contiguous slice of the Latin alphabet or of a keyboard
  row (azerty, qwerty, qwertz). An existing notebook keeps the passphrase it was made with.

## 3. Per-trip addresses

A notebook holds several trips, and each shared trip is a separate unit on the relay. The
first trip uses the address of §2. Each further trip gets its own address, derived from the
master key and the trip identity frozen at its creation (an internal name and a millisecond
timestamp):

```
base       = master ‖ SEP ‖ norm(tripName) ‖ SEP ‖ decimal(createdMs)
trip link  = b64u(H("lien"  ‖ SEP ‖ base))
trip token = b64u(H("jeton" ‖ SEP ‖ base))
```

No stretching here: `master` is already 256 bits of PBKDF2 output.

## 4. Reading material and the content key

The *reading material* is the secret a follower needs. It travels in the URL fragment of the
share link, which browsers never send to the server:

```
https://…/?c=<link>&lg=<language>#k=<material v2>&k1=<material v1>

material v2 = b64u(H("lecture/v2" ‖ SEP ‖ master ‖ SEP ‖ c))     per notebook page (since October 2026)
material v1 = b64u(H("lecture/v1" ‖ SEP ‖ master))                 per owner (older; still read)
contentKey  = AES-256-GCM key, raw = H("coffre/v1" ‖ SEP ‖ material)
```

`c` is the address in the page URL. New content is written with v2. `k1` lets a follower
read content written before v2. A reader tries every material it holds, in order; AES-GCM
rejects a wrong key, so trying is safe.

## 5. Text fields — `RTC1:`

Used for titles, notes, place names, thumbnails (data URLs), the itinerary document, comment
texts and author names, link names, and the encrypted position `pos`. (The budget never
leaves the device: the relay refuses it.)

```
value = "RTC1:" ‖ b64u( IV(12 random bytes) ‖ AES-256-GCM(contentKey, IV, utf8(text)) ‖ tag(16) )
```

No associated data. An empty value stays empty. A value without the prefix is text written
before encryption existed (before 28 August 2026) and is shown as is.

Since 10 November 2026 the app refuses to send any of these fields in clear (before that, a
missing key made it fall back to clear text; see §9.6).

## 6. Media files — `RTC1` (written) and `RTC2` (read only)

```
RTC1 = "RTC1" ‖ IV(12) ‖ AES-256-GCM(contentKey, IV, len(1) ‖ mimeType ‖ bytes) ‖ tag(16)
```

The MIME type is inside the ciphertext. The file is uploaded in pieces to the relay, which
stores it under a name it draws itself (`depot/<random>.rtc`).

`RTC2` adds an authenticated header. The app can read it but does not write it yet (and the
relay accepts only uploads that start with `RTC1`):

```
header = "RTC2" ‖ IV(12) ‖ epoch(1) ‖ size(4, big-endian)                       21 bytes
RTC2   = header ‖ AES-256-GCM(contentKey, IV, len(1) ‖ type ‖ bytes, AAD = header) ‖ tag
```

## 7. Backups — `.tlg` files and the `TLGC1` container

Backups are local files the person keeps (Files app, a folder, a USB key). They are not sent
to the relay.

```
K   = 32 random bytes, drawn once when backups are enabled; never regenerated
KEK = PBKDF2-HMAC-SHA256(normBackup(phrase), salt(16 random), 600 000 iterations) → AES-256-GCM
envelope = { sel: b64u(salt), tours: 600000, iv: b64u(iv), ct: b64u(AES-GCM(KEK, iv, K)) }
```

Changing the phrase re-wraps the same K under a new salt and IV. The backup phrase is
separate from the notebook passphrase and must be at least 16 characters after
normalization. When reading, `tours` is clamped to [100 000, 1 200 000].

```
.tlg  = "TLGS1\n" ‖ u32le(len(H)) ‖ H ‖ chunk_0 ‖ … ‖ chunk_{n-1}
H     = JSON, in clear: {"format":"terralog-sauvegarde","version":1,"genre":"media"|"manifeste",
        "tranche":1048576,"idFichier":b64u(id8),"nbTranches":n,"tailleClair":size,
        "enveloppe":{…}[,"uid":…]}
chunk_i = AES-256-GCM(K, nonce = id8 ‖ u32be(i), AAD = H ‖ u32be(i) ‖ (i == n-1 ? 0x01 : 0x00),
                      up to 1 MiB of clear) ‖ tag(16)
```

- The exact header bytes are the AAD of every chunk: changing a count or a size fails.
- The last-chunk flag and the index make truncation, reordering and extension fail.
- An empty file still has one empty chunk, so its header is authenticated.
- `uid` names the media a file holds (an opaque identifier). A reader must check it against
  the media it expects, because all files of one backup share K.

```
TLGC1 = "TLGC1\n" ‖ u32le(count) ‖ ( u32le(len) ‖ .tlg )*count     the manifest comes first
```

## 8. What the relay stores

| Table | Stored in clear | Stored encrypted (`RTC1:`) |
|---|---|---|
| notebooks | random internal id (24 chars), H(salt ‖ ownerToken) with a random salt per notebook, creation and update times, a purged flag (the title column stays empty) | — |
| links | row id, **H(link), unsalted**, role (owner / family), creation and revocation times | the name given to the link ("Mum") |
| publications | id, step and visit ids, media kind (image / video / audio), media path, removed flag, times; `lat`/`lng` only for posts made before 28 August 2026 | title, note, place name, thumbnail, position `pos` |
| comments | id, step, visit and parent ids, removed flag, times | author name, text |
| documents | document name ("itineraire"), update time | content |
| push subscriptions | browser push endpoint, its keys, a hash of the endpoint, failure count | — |
| media files (`depot/<name>.rtc`) | size, file time | the whole file (`RTC1`) |
| small files on disk | per notebook, the time of the last change and of the last notification; during an upload, its size and start time | — |

After a purge, a unit's rows, media and family links are deleted. The owner link row and the
salted token hash stay, without dates or name: without them, someone who read the address in
the host's logs could create a new unit at that address; with them, only the real owner token
reopens it.

Family links are 32 random bytes drawn by the server. Owner links are derived (§2, §3).

Media files are static files: the web server serves them to anyone who has their URL. The
name is the only access control: 128 random bits drawn by the relay or, when the client gives
a stable media key so that a resend overwrites instead of duplicating, the first 128 bits of
SHA-256(internal notebook id ‖ key ‖ size), the internal id being known only to the relay.

The relay does not log IP addresses; the hosting provider may. The code and the passphrase
never leave the device and are not stored. The device stores, in clear, in the browser's
local storage: the first name, the **master key** and the **owner token** (see §9.7).

## 9. Known limits

These are stated plainly because a reviewer will find them anyway.

### 9.1 Offline guessing if the database leaks

The notebook address is `PBKDF2(first name ‖ code ‖ passphrase)` with a **fixed** salt, and
the relay stores `H(address)` **without salt**. Someone who obtains the `links` table can
test guesses offline, at the cost of 600 000 PBKDF2 iterations per guess, and each guess is
checked against every notebook at once. A correct guess yields the master key: every
reading material, so every text and file of that notebook, and the owner token.

What limits it: the passphrase rules of §2 (for notebooks created since November 2026),
the first name and the code adding some entropy, and the cost per guess. What does not:
there is no per-notebook salt and no server-side secret. A per-notebook salt would break
recovery on a new phone, which must find its notebook from the three inputs alone.

### 9.2 No associated data in RTC1

Neither text values nor RTC1 media bind the ciphertext to where it is stored. The relay
cannot read or alter content, but it can **move** it: serve one notebook file in place of
another file of the same notebook, or put one encrypted field in place of another field of
the same notebook (a comment text in place of another comment). It can also withhold or
replay old rows. Texts and media also share one key and carry no type tag, so a file can be
re-framed as a text value and the reverse; what then decrypts is meaningless bytes, not a
forgery, but nothing rejects it. RTC2 authenticates its header but still does not bind a file to its
address; doing so needs an identifier known both when writing and when reading.

### 9.3 RTC2 is read but not written

The reading path for RTC2 is in the app; the writing path exists but has no caller, and the
relay rejects any upload that does not start with `RTC1`. Moving to RTC2 needs both changed.

### 9.4 Material v1 is shared across the notebooks of one owner

Before October 2026 the reading material depended on the master key only, so it was the
same for all trips of one owner. Content written then is encrypted under v1. Every link that
carries v1 — old links as `#k=<v1>`, new links as `&k1=<v1>` so that followers can still read
old content — therefore opens the pre-October content of **all** trips of that owner, not only
the shared one, for whoever also gets those ciphertexts. The relay serves a unit's rows only
to holders of one of its links, but media files are served to anyone who has their URL (§8).
Content written since October uses v2, per notebook.

### 9.5 Metadata

The relay sees, in clear: how many posts, comments and media a notebook has and when they
were made, their sizes, which steps they belong to, the media kind, and push endpoints. It
sees the IP address of each request. It does not see names, texts, places, positions or
images.

It also receives **the link itself, in clear, on every request**: in the page URL (`?c=`,
with the language `&lg=`) and in the body of every call. The database keeps only its hash,
but the hosting provider's access log may keep the URL. A family link is enough to read a
unit's encrypted rows and to post comments; the owner link alone is not enough to write
(the owner token is also required). This is why the reading material travels in the
fragment, which is never sent.

### 9.6 Clear-text fallback (fixed)

Until 10 November 2026, the text encryption function returned the text unchanged when no key
was available or when encryption failed. In practice this happened for followers holding a
link from before 28 August 2026 (no `#k=`): their comment and name were stored in clear.
The app now refuses to send.

### 9.7 Keys at rest on the device

The master key and the owner token are kept in clear in the browser's local storage. They
never leave the device, but anyone who can read that storage — a stolen unlocked phone, a
browser backup, or code running in the page (§9.8) — gets every reading material of every
trip and the right to write. An optional local lock code (stored as a salted hash) only hides
the screen.

If the browser refuses to store the owner token (private mode, full storage), the app falls
back to putting it in the URL (`?c=…&k=<token>`), so that the notebook stays usable. The
token then reaches the server and may appear in the host's access log.

### 9.8 The web app's code comes from the server

In the browser, the code doing the encryption is served by the same server it protects
against. A server that changed that code could take the keys. This is the usual limit of
encryption in a web page.

### 9.9 Changing the backup phrase revokes nothing

K is never regenerated. Anyone holding an old phrase and any file wrapped under it (the
envelope is in every `.tlg` header) gets K, and K decrypts every backup made before and after
the change. Only deleting the backups and enabling backups afresh (a new K) bounds that.

### 9.10 Smaller points

- The separator 0x1F is not removed from inputs: `("a\x1Fb", "c", …)` and `("a", "b\x1Fc", …)`
  give the same material. It can only be produced by pasting, and it lets nobody into a
  notebook they could not already open.
- AES-GCM with random 96-bit IVs under one key per notebook: safe up to about 2³² messages per
  key, far above a notebook's size.
- The RTC1 media type is cut at 120 UTF-16 code units; a type over 255 UTF-8 bytes would
  overflow its length byte in the app. The reference module refuses such a type instead, so
  the byte-for-byte equivalence of §10 holds for types up to 255 bytes. MIME types are
  ASCII, so this does not happen in practice. RTC2 cuts the type at 200 bytes.
- Thumbnails are data URLs inside an encrypted field; their length still reveals their size.

## 10. Test vectors

`test/vectors.json` is generated by running the app's own code (not this module) with a
replayed random source; the module must reproduce every byte. See `test/vectors.test.mjs`.
