# Live protocol Service usage verification v1

Status: B3.8 candidate; explicit isolated-copy testing only. This contract does not
activate production admission or supersede isolated-service-v2-v1.md.

## Producer and consumer

The producer is a normally constructed TXBoard Service with the real sing-box
kernel. A genuine locked VLESS or AEAD VMESS TCP client connects to its loopback
listener with the native synthetic User UUID, reaches one exact loopback origin,
and exchanges asymmetric application payloads. No memory kernel, direct tracker
invocation, caller-provided traffic map, or manually generated batch is accepted.

The enforcing ConnTracker measures the decoded proxy stream at actual Read/Write
calls. The protocol headers and encrypted wire sizes are separate observations.
Independent origin/client payload lengths and SHA256 values must agree with the
persisted raw directions. Upload is client to origin; download is origin to client.
EOF must complete all in-flight operations before a worker seals a UTC interval.
Unknown tail cannot become a successful zero-byte claim or v1 fallback.

## Listener and route scope

Only the prepared bounded usage mode forces every generated inbound to IPv4
127.0.0.1 before listener creation. The default v1 listener policy is unchanged.
The fixture checks the actual listening socket and rejects wildcard/IPv6 binds.
The fixture route override is one TCP AND rule with 127.0.0.1/32 and exactly the
allocated origin port; ordinary private-network blocks remain in place.

## Scheduled heartbeat and billing

Before shutdown, the actual Service 5-second report ticker must receive a native
successful ACK. Its report has no nonempty Traffic or identified traffic batch.
The observation proxy records only timestamps, method/path, bounded hashes and
non-secret ACK metadata. It does not substitute a fake ACK or invoke the report.
The isolated Core SQL checks assert the native User upload/download remain 0/0,
then match the original canonical batch digest, source epoch, persisted receipt,
accepted outbox, immutable grant/Terms/period/tariff identities and exact 3:2 floor
per direction. Exactly one batch and one entry must survive settlement and replay.
Changed canonical content under the original batch ID is a real HTTP409 conflict.
The original source/session/receipt persist through shutdown and spool reopening;
starting a fresh live Service with the old epoch must refuse before listeners.

## Isolation and evidence

The runner accepts only APP_ENV=testing, mysql, DB_HOST=127.0.0.1, dedicated database
`txboard_live_protocol_test`, and principal `txboard_live_protocol`. The DB must
contain zero tables before DDL; no implicit reset, production database, or shared
fixture is permitted. VLESS and VMESS have separate retained runs and artifacts.
The test binary is compiled before finite grants are issued. Private key, bearer,
password, executable and lockfiles never enter exported public evidence.

B3.8 acceptance requires both protocol runs, race/vet and source hashes. It proves
this bounded Service path only; multi-Service, UDP/mux, production PKI/Provider,
gray rollout and complete B3 acceptance remain separate requirements.