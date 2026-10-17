# Node offline Provider window proof v1

Status: verified isolated implementation slice. This extends the
[independent signature diagnostic](node-offline-signature-proof-v1.md) with a
bounded, process-local time/trust observation. It is not a Node authorization,
credential delivery, usage attribution, revocation ACK or Service lease consumer.

`Verifier.OpenWindow` accepts the original signed statement, verifies it using
operator-provisioned immutable public trust, and checks both key validity and the
signed finite UTC window. A revoked key, future statement, exact expiry or
invalid sample refuses opening. A forged caller-created `Proof` cannot initialize
a window. Callers cannot supply a UTC time through the public API.

The window owns a private UTC and monotonic clock, sampled before signature work
and checked again afterwards. Its monotonic deadline is the initial exact
subsecond remainder until the signed expiry, no longer than sixty seconds. A
later wall-clock slowdown cannot extend that deadline. Every check also rejects
UTC regression, monotonic regression, a wall-clock expiry, and invalid time or
counter arithmetic. Equal observations are allowed. It allocates no timers,
workers, network requests, credentials or persistent history.

`Window.Check(currentVerifier)` requires explicitly provisioned current trust.
The same key ID, epoch, SPKI digest and validity bounds must still exist and must
not be revoked. A predecessor can remain valid only while the supplied trust
still contains that exact key. Removed, replaced, revoked, nil or malformed trust
is terminal. Trust provisioning/refresh remains the caller's responsibility;
reusing stale trust does not prove that a key has not since been revoked.

Check and Close serialize under one instance lock. Any refusal, expiry or Close
permanently ends that object. Later clock/key recovery cannot reopen it. Checks
return an independent proof copy with `window_validation=valid_at_observation`;
`admission_granted=false`, nested business evidence and live Core eligibility
remain unevaluated. Original pure signature diagnostics still inspect expired
or revoked historical signatures without evaluating a time window.

This is a local diagnostic for the existing synthetic fixture domain. Default
Node Service, kernel credentials, metering bindings, v1 reports and v2 grants do
not import or call it. It neither closes sessions nor prevents a caller from
constructing a separate diagnostic instance. There is no trusted external time,
boot/restore fence, durable denial, unobserved rollback detection, suspend/HA
proof, distributed revocation or offline convergence. Production admission must
satisfy those separate gates before using any time observation as authority.

Tests use synthetic signatures and controlled private clocks for expiry,
regression, crypto-time consumption, trust refresh and concurrent closure.
The former Core interoperability fixture and its environment switch are retired;
they are not current test instructions. Current pure Go tests do not require PHP.

The [historical verification report](../../../node/docs/provider-window-verification.json)
records the old source/log snapshot, not current runtime admission. The current
[calling boundary](../../../node/docs/native-provider-node.md#offline-proof-library)
retains the diagnostic-only library usage and its limits.
