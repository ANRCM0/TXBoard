# Offline response salt candidate

B6.4 is a separate offline SDK experiment at `@txboard/theme-sdk/experimental/sc-v2-response-salt`. It uses the [RFC9458 response derivation pattern](https://www.rfc-editor.org/rfc/rfc9458.html#section-4.4) with a distinct application profile, and is not OHTTP wire format. The existing [offline profile](sc-v2-crypto-experimental.md) and v1 SDK remain available with their existing bytes.

The recipient generates 32 fresh random bytes for each one-use response closure. HKDF-SHA256 uses the HPKE exporter secret and salt `enc || response_nonce`; key and nonce use distinct `key` / `nonce` info strings. The closed response additionally authenticates its canonical nonce, original request metadata/digest, response kind and status. See the [candidate byte contract](../../contracts/v2/secure-channel/response-salt-experiment-v1.md).

Duplicate recipient openings can produce different responses using independently derived material rather than the old deterministic response key/nonce. This depends on WebCrypto CSPRNG and HKDF; observed uniqueness in tests is not a proof of RNG health or a shared business replay claim. Broken/cloned entropy, admission and recovery, discovery/rotation/revocation, business idempotency and independent protocol review remain transport gates. No Gateway HTTP, Core operation or production enable flag imports this candidate.

```sh
npm run check
python3 scripts/verify-sc-v2-response-salt-vector.py
node scripts/test-sc-v2-response-salt-browser.mjs
```

The manual generator `scripts/generate-sc-v2-response-salt-vector.mjs` uses already-public `hpke-kat.json` fixture scalars and a deterministic public response nonce. Its reference includes exported fixture material so Python can independently validate HKDF/framing with standard-library HMAC. Runtime methods accept no caller nonce, exporter override or deterministic RNG. Tests replace ambient RNG only for the fixed reference; this is not independent HPKE verification or a production cryptographic audit.

Both response closures consume their use before reflection or awaits, reject unknown/accessor/symbol fields, clear owned temporary bytes and recheck expiry after asynchronous crypto. Failure does not recover a context. Engine/library secret destruction and unused closure lifetime are not guaranteed. New and old HPKE info labels reject each other's request contexts; no profile fallback exists.
