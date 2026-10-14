# Finite native Node authorization acceptance

This opt-in runner exercises the real Core, Workerman, Machine supervisor, kernel listeners, VLESS and VMess streams, Redis worker and dedicated MariaDB ledger. It has no production credentials and only permits the exact synthetic `txboard_native_authorization_test_singbox` or `txboard_native_authorization_test_xray` database, using account `txboard_native_authorization` at `127.0.0.1`. A caller must provision an empty schema. The runner refuses nonempty schemas, reuses no existing evidence directory, never resets a database and never flushes Redis.

Run on the isolated Linux fixture with PHP/Sodium, MariaDB, Redis, OpenSSL, the cached locked Go toolchain and CGO available:

```bash
APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=13306 \
DB_DATABASE=txboard_native_authorization_test_singbox DB_USERNAME=txboard_native_authorization \
DB_PASSWORD="$SYNTHETIC_DB_PASSWORD" REDIS_PORT=16379 REDIS_DB=54 REDIS_CACHE_DB=55 \
python3 scripts/tests/node-native-authorization/run.py \
  /opt/txboard-baseline/native-authorization-integrated-r1/api \
  /opt/txboard-baseline/native-authorization-integrated-r1/node \
  /opt/txboard-baseline/native-authorization-integrated-r1/evidence singbox
```

The second kernel run uses `xray` with `DB_DATABASE=txboard_native_authorization_test_xray` and a new evidence directory. Each kernel has its own dedicated empty schema, so neither run needs to reset the other. Preserve and back up existing evidence and the dedicated schemas before any separately reviewed reset. Redis54/55 avoid the existing live Machine52/53 and traffic60/61 fixture slots; each execution additionally uses a unique prefix. CI may pass another pair of distinct reserved slots.

The runner compiles a race-enabled Node test with a Go overlay; the overlay source lives in this folder and does not mutate the checked-out Node tree. It reuses the accepted Machine live-test transport helpers and creates three distinct OS processes using the same kernel and authorization state paths. The synthetic Core Ed25519 seed is random, never persisted or printed, and removed from the Node subprocess environment. Only the public trust tuple is written to the private fixture directory. The transparent TLS facade records public scope, revision, expiry, hashes and traffic identities; it never logs bearer headers, UUIDs or signed envelope bodies.

The synthetic lease is 40 seconds and the dedicated authorization refresh is 5 seconds. Ordinary administrator pull is 60 seconds, longer than the lease, so the test exercises renewal independently of that pull setting.

The acceptance sequence is:

1. Open actual VLESS and VMess streams on two children sharing one authenticated Machine WSS socket; observe each child's signed complete HTTP snapshot.
2. Set the primary user’s banned flag through the registered per-user administrator update API and process its real `node_sync` queue while refusing only that child's signed users requests. Observe the actual unsigned WS hint and prove both established streams continue before signed delivery.
3. Restore signed users delivery. Observe the primary empty complete snapshot, closure of its existing stream and listener, and continued real sibling traffic.
4. Unban through the administrator API and admit a new primary stream with a newly signed snapshot.
5. Refuse all native HTTP transport and close the shared WSS socket. Existing streams and a new stream continue before the finite lease deadline.
6. Concurrently observe both existing streams and listeners close at expiry, comparing each each child?s IO cutoff with its persisted signed deadline using a two-second observation tolerance. Retain Node-produced traffic spools including the original batch ID, body, observation period and file hash.
7. Kill the captured process at the recorded boundary. A different process using the same disk state remains closed while Core is still unreachable.
8. Restore transport and start a third process. Fresh signed snapshots reopen both children; the original spooled batches settle in the Core ledger with the same IDs, body and periods.

`result.json` is emitted only after all cases pass, with private stage files and transport observations retained for audit. A compile-only run, absent fixture skip or runner preparation is not real acceptance. Forged signatures/targets, revision/key rollback, malformed state, clock rollback and persistence failure also require focused Core/Node tests; production key provisioning, Provider secret delivery, key rotation overlap, production PKI, HA, hardware anti-rollback and the full protocol matrix remain separate gates.

The wrapper command targets the existing trusted local WSL distribution `TXBoard-Baseline`; no SSH host, trusted-host record or external server is altered by this runner. Source freezing, database provisioning and final execution belong to the phase owner after Core and Node contracts align.

Execute kernel runs sequentially when they share an API source directory: Workerman stores its own PID state there. CI matrix jobs are isolated runners and each owns its own checkout and services. A live-run failure requires a fresh empty schema and evidence directory; this runner intentionally refuses retries over nonempty data.
