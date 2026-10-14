# Live Service revocation and recovery v1

Status: B3.9 current candidate, isolated-copy only. This slice keeps the bounded
TCP, finite grant, immutable receipt, no-fallback and production gates unchanged.

A native polling snapshot distinguishes absent users (HTTP304) from a valid empty
set (HTTP200). The nodesync immutable copy must retain that distinction. A changed
native user/config snapshot closes isolated mode before reloading credentials,
listeners or rebinding old counters. Native ban, nonempty user change and node
configuration update are driven through registered Admin HTTP transactions and
then observed through real Node HTTP polling; Node credentials cannot authorize
Admin writes. Ordinary unchanged polling remains harmless.

Each actual VLESS/AEAD VMESS boundary begins with a nonzero sealed/settled historical
batch measured through the real Service-owned sing-box kernel and independent
origin/client streams. Native scheduled heartbeat ACK precedes the long-grant
policy triggers. A later measured connection stays open across the worker boundary.
Its unknown unfinished tail cannot become a zero-success or invented final batch.
Policy failure, finite expiry and shutdown close the actual connection and listener,
retain all old payloads/receipts and never start a v1 traffic authority.

A four-second lease belongs to a separately Core-assigned source and actual new
Service process over the retained mode directory. Existing isolated Core source,
grant and generation commands are the provisioners. No caller rewrites source
numbers, prior immutable grants or local source markers. Compilation and Core/TLS
warming precede finite grant issuance; timing failures remain failures.

The expiry acceptance first settles the real historical batch, then stops that
Service process before provisioning source epoch 2 and its four-second Core grant.
A separate process reopens the same mode directory, accepts a real VLESS or VMess
flow with a measured unfinished tail, and must close the connection and listener
at expiry with at most 1.5 seconds of scheduler observation allowance. The old
settled receipt and SQL totals remain unchanged; the tail creates no batch.

Genuine UDP, XUDP and classicTCPmux requests are separate protocol cases. Unsupported
traffic must not reach an origin, create an accepted usage batch or fall back to
v1. A detected mux admission hole remains a failed gate until a reviewed runtime
fix establishes a reliable boundary; one packet rejection does not prove all mux
families rejected. Collector/tail predicates are not weakened to pass tests.

The UDP and XUDP cases use the locked VLESS/VMess clients and an exact loopback UDP
origin route. They accept either a pre-auth protocol rejection or bounded tracker
fail-stop after packet admission, but require zero origin datagrams and unchanged
settled history. Classic TCP CommandMux remains a separate acceptance boundary.

Classic TCP CommandMux uses a real authenticated outer VLESS/VMess command and the
locked sing-vmess address serializer and frame constants. Any TCP connection to the
synthetic origin is a failed acceptance result, retained with its decoded payload
hash; UDP/XUDP rejection does not satisfy this check.

In bounded usage mode, TX-Node rejects an inbound TCP stream whose remote address
is an invalid `Socksaddr` before outbound routing. The locked classic mux server
represents its logical streams this way; those streams cannot use the ordinary
socket-bound accounting path. The tracker fails closed and stops the isolated
Service. Normal socket-backed TCP streams remain eligible for accounting.

A higher Core-issued source can retain and replay original older batches unchanged
while starting a fresh timeline. Core instance/receipt-generation drift requires
recovery and refuses old storage before listeners. Restoring the dedicated SQL DB
to a real earlier snapshot can remove a formerly persisted receipt: the confirmed
local original record then requires recovery and must not silently re-submit.
Original source/grant/Terms/period/tariff identity and exact per-direction billing
remain immutable during all recovery checks.

The source-continuation acceptance stops the original Service after its historical
batch settles, then provisions epoch 2 with Core's source and grant commands. An
idempotent installation replay must return the same source. A fresh process opens
the retained directory, queries the old settled receipt and captures a completed
37-byte upload/19-byte download through the real protocol. Frozen 3:2 directional
floor billing adds 55/28 bytes, yielding 119/146 bytes (265 total) across exactly
two batches and entries. Original local JSON hashes and full historical SQL batch
and entry hashes remain identical. Observed requests after the new process starts
must contain no implicit submission of the historical batch. The old epoch,
digest and receipt remain distinct from the new epoch, batch and receipt.

Generation drift uses Core's explicit generation command with a new configured
receipt generation, then restarts the actual Core HTTP process. Its real handshake
must advertise generation 2 while local history remains generation 1. The retained
directory refuses binding and Service initialization stops before kernel startup.
Changing only the Core instance configuration over the original SQL database is
a separate Core-side rejection: the native handshake returns the explicit
CORE_INSTANCE_RECOVERY_REQUIRED usage error, and isolated Service initialization
stops without a listener. It does not prove a new legitimate Core instance's local
storage comparison; no persisted source identity is rewritten to pass this case.

The missing-receipt acceptance saves a complete 0600 SQL dump after seeding and
before any historical capture, and another dump after the original batch settles.
It stops the original Service and Core HTTP process, restores the earlier dump into
the verified exclusive test database and checks zero batch/entry/outbox rows and
zero billing. Core then provisions epoch 2 and a finite grant so same-epoch restart
refusal cannot mask the receipt check. The new real Service may start its listener;
its normal worker must query the original confirmed receipt, observe an authentic
404 BATCH_NOT_FOUND and fail-stop with its listener closed. No usage submit is
permitted after restart, original local JSON remains unchanged, and restored SQL
must still contain zero usage rows and zero billing. This is a runtime rejection,
not an assertion that the listener never opened.

The runner requires APP_ENV=testing, mysql,127.0.0.1, exact dedicated database
`txboard_live_revocation_test` and principal `txboard_live_revocation`, zero tables
beforeDDL and a new evidence directory. Any reset/restore is separately explicit,
uses only this verified test DB, and retains0600pre/postdump hashes. Private tokens,
passwords, keys and executable are excluded from exported artifacts. This is not a
production source-opening endpoint or a complete B3 acceptance claim.
