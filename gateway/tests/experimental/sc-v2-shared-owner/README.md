# Isolated shared response owner experiment

Status: B6.3 engineering fixture only, local/testing, disabled by default. This
directory is not a package export and is not imported by Gateway, Core or the
normal SDK. Passing it does not approve SC-v2, a network transport, production
cryptography or a strongly consistent Redis fence.

The fixed baseline is Gateway `2a5d2ee2dd01a7568a0b121236b158fa5339fd8e`.
It reuses the committed SDK experiment and its unchanged codec/exporter labels.
The only operation is `lab.echo` version `1`: opaque fresh fixture bytes, no
Core callback, bearer, user authorization or business side effect.

## Run

Use Node >=22 and the existing locked dependencies. Linux also needs Bash and a
local `redis-server`/`redis-cli`; no Docker, production Redis or Core DB is used.

```sh
npm ci --no-audit --no-fund
npm run build
node --test tests/experimental/sc-v2-shared-owner/shared-owner.test.mjs
bash tests/experimental/sc-v2-shared-owner/run-linux.sh
bash tests/experimental/sc-v2-shared-owner/run-linux.sh --ttl
npm run check
```

The first test works on Windows using private named-pipe synthetic Redis peers.
Actual Redis/crash/TTL evidence is Linux only. The launcher creates a private
`/tmp/txboard-sc-owner.*` directory and port-0 Unix socket, 16MiB/noeviction Redis
with AOF and appendfsync always, then stops it and deletes that directory. It
never touches the baseline Redis, any shared database or an existing config.
The TTL test waits about 70 seconds and prints progress every 10 seconds.
No fixture environment variable enables an HTTP route.

## Trusted admission and lifetime

`createSharedOwnerFixture({enabled,environment,socketPath,epoch,runId,keyPair})`
is a test-only factory. Default false/undefined/empty closes it; invalid enable
values and production refuse. All config belongs to the launcher. The trusted
explicit ECDH/P-256 pair permits a nonextractable private key; `kid` is the full
SHA-256 of its public key. The caller must provide a corresponding pair.
No cryptographic context, secret, raw claim, caller digest/TTL or ownership
receipt is exposed. Redis paths are limited to launcher-owned fixture locations.

Open reserves its bounded pending slot **before** reflecting the envelope. It
snapshots closed own data records and canonical strings, checks fixed user
domain, `lab.echo`, version, origin and path, then verifies the HPKE request.
The wrapper privately recomputes SHA-256 of the unchanged request digest bytes
using the same snapshot. This binds metadata, actual enc and ct; it does not
authenticate an actor because HPKE base mode has no sender authentication.

Only a successfully opened request becomes a private verified capability for
the claim Lua script. Node and Redis `TIME` must agree within 1s; issued/expiry
are validated safe finite integers with lifetime <=60s, future skew <=5s and
strict expiry. A captured wall/monotonic anchor quarantines drift or rollback
over 1s. This is not a solution to global time rollback across VM restarts.

The script verifies the fixed epoch sentinel and server time, then atomically
sets `<fixed-prefix>:<epoch>:<full-digest>` NX PX **70000**. Earliest valid claim
is issued-5s and expiry is at most issued+60s, so 70s covers the remaining valid
interval with 5s margin under the stated clock assumptions. Digest, marker and
TTL are not selected by envelope fields or API consumers. There is no release,
renew, retry, takeover, result cache, resume or material reconstruction API.

Only an exact first-owner ACK returns plaintext plus one `seal(bytes,binding)`.
Seal claims spent before input reflection or await, moves/clears its private SDK
closure holder, copies caller bytes/binding synchronously, and uses a fixed
read-only pre-seal owner/epoch/deadline check. Only then can the committed SDK
export response key/nonce and encrypt once. Invalid inputs, errors, expiry,
outage and ambiguous claims consume or refuse; a duplicate cannot encrypt even
an error. Lost responses are not regenerated. Internal plaintext copies are
cleared; caller-owned returned bytes remain the caller's responsibility. Unused
local holders are dropped at expiry or factory close; no engine erasure is claimed.

## Total deadlines and failure isolation

Pinned `redis`/`@redis/client` 5.6.0 uses no offline queue, no reconnect and a queue
cap of 128. Connect timeout is 2500ms; setup has an additional 3s total deadline,
and each command a 500ms total deadline. Timeout/not-ready/error quarantines and
destroys the client. Late completion cannot reenable the factory or owner;
startup awaits also check closed state so late public-key work cannot connect.
The pending crypto operation itself is not cancelled by this timer.

The pinned client's command abort/timeout only applies **before socket write**;
the implementation removes those listeners when moving a command into the
reply queue. It cannot prove Redis did not execute. An executed-but-lost ACK is
therefore consumed/unknown, never retried. The additional total timer handles
delayed and partial slow-drip replies.
[node-redis 5.6.0 command options](https://github.com/redis/node-redis/blob/redis%405.6.0/docs/command-options.md)

All failure output is the fixed `SC_OWNER_INVALID`; logs do not include original
errors, raw inputs, key material or Redis credentials. Test instrumentation
observes pinned HMAC exporter labels, encrypt-only AES imports and WebCrypto
encrypt calls. Before sender setup the instruments are inactive, so the counts
refer to recipient responses. Request decryption is permitted before claim;
response material is not exported on a refused/duplicate claim.

## Preserved limits: this is not a crypto fence

The one-winner result assumes one continuous primary, preserved keys and stable
clocks. Run ID is pinned at connection setup; fixed epoch and owner markers are
checked on admission/pre-seal. Observed disconnect, unknown reply, missing owner,
epoch change or restart quarantines. Claims remain after invalid seal, crypto
failure, worker crash, cancellation and response loss. A worker joining the
unchanged live epoch cannot resume an existing lost owner. A new fixture epoch
is only for fresh keys after all old workers stop.

**Redis ownership validation and WebCrypto encryption are not atomic.** The
pre-seal check cannot fence a subsequent Redis change. Unobserved deletion,
eviction, stale restore with the same sentinel, failover losing a claim or split
brain can permit a second owner. AOF, WAIT, a namespace switch or a pre-seal read
must not be represented as eliminating this hazard. The raw non-crypto test
demonstrates a second NX winner after deliberate record loss and never calls
response crypto. Known/ambiguous loss never re-derives material. Unknown loss is
an unresolved blocker for **every real network/business consumer**.
[Redis replication limitations](https://redis.io/docs/latest/operate/oss_and_stack/management/replication/)

No HA/old-backup recovery, power-loss durability, partitions, clock rollback
across restarts, real actor policy, key discovery/rotation/revocation, business
idempotency or production protocol audit is claimed. Existing v1 nonce replay
and Core transaction/idempotency semantics remain independent and unchanged.

## Evidence and cleanup

`evidence.json` records fixed source/dependency hashes, actual environments and
counts. Provenance distinguishes raw `git show` blob bytes, the actual archived
Linux snapshot, the Windows working tree and LF-normalized bytes. Windows Git
2.55.0 with system `core.autocrlf=true` produced CRLF archive entries despite LF
Git blobs; forcing that setting false, or using Linux Git 2.49.1 with it unset,
produced LF entries. This was verified on raw subprocess/tar bytes. In particular
the lock Git blob is `a60bdbf6de2788282dfa87077316f55df950c7192e6a33960ead97dc953dc940`,
while this experiment's archive snapshot and Windows lock working bytes are
`e93bef881fc3ddddd3f0179cd2ce87a9eed191a7a8b92703150b68b6c07aa60d`.
Their LF-normalized bytes are equal. This is archive conversion, not a dependency
change or a correction to earlier evidence using an LF Git blob. Runtime files
and test results were unchanged by this provenance documentation update.

Tests cover 64 independent clients across four workers, single actual
response encryption, duplicate error refusal, SIGKILL after claim, real executed
claim with dropped/delayed/drip ACK, clock mismatch, invalid Lua admission,
missing owner/epoch, OOM/noeviction, startup mismatch and stopped Redis; local
tests additionally cover reentry capacity, asynchronous close, returned/caller
byte ownership, malformed ACK and crypto/expiry failures. The separate TTL run
checks a real 70s record through the accepted interval and rejects the same
expired envelope after deletion.

Fresh private JWKs exist only in fixture process memory/inherited child IPC; they
are not arguments, files or logs. Children are stopped and joined before their
test ends. At launcher completion all owners/clients/workers are stopped, then
the dedicated Redis stops, then its owned directory is deleted. No unexpired
claim is removed while that fixture can accept requests. Log/report artifacts
contain safe test names, codes, aggregate counts and provenance hashes only.
