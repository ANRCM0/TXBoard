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

Genuine UDP, XUDP and classicTCPmux requests are separate protocol cases. Unsupported
traffic must not reach an origin, create an accepted usage batch or fall back to
v1. A detected mux admission hole remains a failed gate until a reviewed runtime
fix establishes a reliable boundary; one packet rejection does not prove all mux
families rejected. Collector/tail predicates are not weakened to pass tests.

A higher Core-issued source can retain and replay original older batches unchanged
while starting a fresh timeline. Core instance/receipt-generation drift requires
recovery and refuses old storage before listeners. Restoring the dedicated SQL DB
to a real earlier snapshot can remove a formerly persisted receipt: the confirmed
local original record then requires recovery and must not silently re-submit.
Original source/grant/Terms/period/tariff identity and exact per-direction billing
remain immutable during all recovery checks.

The runner requires APP_ENV=testing, mysql,127.0.0.1, exact dedicated database
`txboard_live_revocation_test` and principal `txboard_live_revocation`, zero tables
beforeDDL and a new evidence directory. Any reset/restore is separately explicit,
uses only this verified test DB, and retains0600pre/postdump hashes. Private tokens,
passwords, keys and executable are excluded from exported artifacts. This is not a
production source-opening endpoint or a complete B3 acceptance claim.