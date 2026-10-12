# SC-v2 shared response owner experiment v1

Status: B6.3 isolated engineering experiment, local/testing and disabled by
default. Gateway commit `6ea649b1d4224498127382344d3dbad1ef332a24` adds only
`tests/experimental/sc-v2-shared-owner`; it is not a package export and has no
Gateway, Core, SDK HTTP or business consumer. This does not approve production
cryptography or complete the [SC-v2 target gates](README.md).

The [fixture contract](https://github.com/ANRCM0/TXBoard-Gateway/blob/6ea649b1d4224498127382344d3dbad1ef332a24/tests/experimental/sc-v2-shared-owner/README.md)
and [fixed verification evidence](https://github.com/ANRCM0/TXBoard-Gateway/blob/6ea649b1d4224498127382344d3dbad1ef332a24/tests/experimental/sc-v2-shared-owner/evidence.json)
record the implementation and limits. It reuses the unchanged
[offline crypto experiment](offline-crypto-experiment-v1.md), with only
`lab.echo` version `1`, opaque fixture bytes and no actor or business callback.

## Admission and one response owner

Trusted launcher configuration supplies the explicit recipient key pair,
fixture Redis socket, epoch and run ID. The wrapper snapshots and validates the
fixed domain, operation, transport binding and bounded lifetime, then verifies
the HPKE request. It privately derives the digest from the verified metadata,
encapsulation and ciphertext; a caller cannot supply a claim digest or TTL.
HPKE base mode authenticates ciphertext integrity, not a sender or Core actor.

Only that private verified capability can attempt the fixed Lua claim. Redis
`TIME` and the Node clock must agree within one second; request lifetime is at
most 60 seconds, future skew at most five seconds. A wall/monotonic anchor
quarantines observed drift or rollback. The claim checks the fixed epoch and
atomically uses `SET NX PX 70000` on the epoch and full request digest. The TTL
covers the accepted interval under these clock assumptions; it does not solve
clock rollback across VM restarts.

Only an acknowledged winner receives a one-use response closure. It consumes
its use before inspecting caller input or awaiting work, then checks the owner,
epoch and lifetime before any response exporter or encryption call. Duplicate,
refused or ambiguous claims cannot encrypt a response, including an error.
Post-claim failures retain the claim: there is no release, renewal, takeover,
retry, response cache, receipt replay or secret reconstruction. A lost response
cannot be regenerated from the same request context.

The fixture disables Redis reconnect and offline queuing and bounds setup,
queue capacity and total command time. Timeout or unknown execution destroys
and quarantines the client; late Promise completion cannot revive an owner.
An executed claim with a lost ACK remains consumed or unknown. Internal
plaintext copies are cleared where the wrapper owns them; engine and library
secret destruction is not guaranteed.

## Continuous-primary assumption and adoption blockers

The observed one-winner property assumes one continuous primary, preserved
claim keys and stable clocks. A pinned run ID at setup and owner/epoch checks
detect some observed changes. Disconnect, missing owner, epoch change or
ambiguous execution refuses further use. A fresh fixture epoch requires fresh
keys after all old workers have stopped; changing a namespace does not recover
ownership of an old context.

**This is not a cryptographic fence.** Redis validation and WebCrypto response
encryption are not atomic. An owner check cannot fence a subsequent storage
change. Unobserved key deletion, eviction, stale restore with the same sentinel,
failover losing an acknowledged claim or split brain can admit a second owner
that derives the same response material. The non-crypto fault test demonstrates
a second NX winner after deliberate record loss without performing a second
response encryption. AOF, `WAIT`, a namespace switch or a pre-seal read do not
establish the missing guarantee.

Unknown data loss and HA/recovery semantics remain blockers for **every real
network or business consumer**. This experiment does not verify power-loss
durability, partitions, backup restore, actor authorization, key discovery or
rotation/revocation, encrypted business error policy, business idempotency or
an independent production protocol audit. Existing Gateway v1 replay behavior
and Core transaction/idempotency authority remain unchanged. No runtime
integration may infer approval from these test results.

## Verification boundary

The fixed Gateway base `2a5d2ee2dd01a7568a0b121236b158fa5339fd8e` plus the
experiment passed 19 unit tests on Windows and 19 on Linux, 14 real Redis tests
and one real 70-second TTL test. The isolated Linux full check passed 467 Gateway
and 68 SDK tests plus both workspace typecheck/build checks.

Real fixtures cover 64 independent clients across four workers with one winner,
SIGKILL after claim, executed claims with lost/delayed/drip ACKs, clock mismatch,
expiry, Redis outage and retained claims after failure. Instrumentation checks
that refused and duplicate paths perform zero response exports or encryption.
Post-crypto expiry may reject a response after its sole encryption; it does not
authorize another attempt. The evidence separates Git blob, archived snapshot,
working-tree and LF-normalized hashes. It is engineering evidence for this
isolated scope, not formal crypto acceptance or production HA evidence.
