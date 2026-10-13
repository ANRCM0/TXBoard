# Bounded TCP collector and actual Core interoperability v1

Status: verified isolated producer/consumer interoperability slice. This is an
actual producer/consumer fixture for the [explicit TCP collector](bounded-tcp-collector-v1.md),
not a production v2 Service handoff or admission system.

The exclusive disposable MariaDB database is `txboard_bounded_usage_test` with
its own single-database user. The launcher refuses a populated database before
migrations. Core builds real synthetic subscription, Terms, known period, subject,
source, tariff and auth snapshot through the existing UsageRegistry fixture. No
Provider proof becomes a usage grant and no production account is referenced.

The launcher completes migration and race-helper compilation before allocating
the finite Core grant. Cold compiler/cache work therefore consumes no grant
window; the shared Core fixture lease is not extended for CI.

The Node helper requests the real native handshake over verified TLS1.3 loopback
HTTPS, obtains its assigned Session and grant IDs, and uses actual sing-box
ConnTracker memory TCP Read/extended Write to produce eleven physical upload and
seven download bytes. The frozen journal and spool retain original random batch
identity. Existing HTTPTransport and Reporter use native Node Bearer/NodeID;
Node's TLS identity is separate from Internal/Admin mTLS and no client certificate
is invented here. A transparent local TLS terminator only forwards the three
closed Node paths to the actual Laravel HTTP kernel.

A client-side fault consumes the actual successful submit response after Core
commits, then returns a transport error. Reporter queries the original batch and
persists the real pending receipt. The fixture independently verifies batch,
canonical digest and accepted outbox in SQL before running the existing settlement
worker. Same batch replay preserves receipt; changed canonical bytes with the
same batch ID return 409 BATCH_PAYLOAD_CONFLICT. Wrong Bearer and unknown Node ID
cannot query the receipt. Core's original frozen tariff is three halves with
per-direction floor: eleven/seven raw becomes sixteen/ten billed, total twenty-six.

After Node journal/spool close/reopen and Core settlement, the helper queries the
real settled receipt, resubmits the original batch, and queries again. SQL verifies
one batch/one immutable ledger entry and unchanged raw/period totals. Payload,
pending/settled receipts and actual journal metadata are retained as fresh isolated
artifacts. Public evidence excludes private TLS key/test bearer/executable;
metadata hashes refer to original newly produced artifacts, not fabricated prior
history. Closing a spool retains its read-only receipt history.

The fixture changes no Core runtime, default Node Service, production database,
installer or frontend. Existing v1 cumulative counters still count the traffic;
this test never activates a second authoritative production bill. Real production
handoff, trustworthy recovery time, distributed revocation, credential admission
and restore coverage remain independent gates.


Actual Linux Go1.26.3 race-enabled helper and PHP8.4.16/MariaDB11.4.12 passed the
whole sequence against the dedicated empty fixture database. The helper compiled
and executed inside the Node module, preserving internal-import restrictions;
Go vet and PHP syntax checks passed. A second launch against the populated fixture
was refused before DDL or server startup. Source, dependency, original Session,
random batch/receipt identity and new artifact hashes are recorded in the
[verification report](../../../node/docs/bounded-core-interop-verification.json).


The final r2 rerun preserves the r1 artifacts and an explicit private SQL backup.
Only runner ordering changed: compile first, seed the unchanged finite grant
second, then start certificate/HTTP/TLS. This avoids relying on compiler caches or
expanding authorization time to cover cold CI work. R2 produced a fresh original
batch/receipt and passed the same actual SQL/HTTPS settlement/replay assertions.
