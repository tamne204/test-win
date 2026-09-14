# NON-PRODUCTION TEST FIXTURE — DO NOT SHIP

`integrity.manifest.json` in this directory is a **frozen test specimen**, not a
build artifact and **not** a production manifest.

## Provenance

It is a byte-identical copy of the historically committed
`apps/capcut-v2/desktop/resources/integrity.manifest.json` (version `2.1.1`),
which was signed with the **canonical production Ed25519 key**
(`keyId` from `CANONICAL_KEY_ID`).

It is preserved here — and only here — so that the adversarial harness
`tests/test_integrity_adversarial_m3.js` can continue to assert that:

- a genuine, canonically-signed manifest **verifies** against the production
  public key embedded in `src/main/integrity_guard.js` (`BASE.1`, `BASE.2`), and
- a manifest signed with a **revoked** key is **rejected** (`KEY.REVOKED.1`).

These assertions are only meaningful against a real production-key signature,
which is why the specimen is committed instead of generated on the fly.

## Why the real manifest is not committed

Release Standard §12 (`STOP COMMITTING GENERATED APP.ASAR/MANIFEST`) requires
that no build-generated artifact be committed to the source tree. The real
manifest is produced per-release by
`apps/capcut-v2/desktop/scripts/after_pack_integrity.js`, which writes it
directly into `<appOutDir>/resources/`. The source-tree copy is now
**gitignored**.

## Rules

- **Never** wire this file into a build, an installer, or `electron-builder`.
- **Never** treat it as an authoritative manifest for runtime integrity.
- `BASE.3` deliberately does **not** use this file; it builds its own
  self-contained mock release with an ephemeral Ed25519 keypair.
- The file is intentionally stale (`version: 2.1.1`) — do **not** "update" it.
  Its staleness is irrelevant because it is only ever signature-verified, never
  version-matched.
