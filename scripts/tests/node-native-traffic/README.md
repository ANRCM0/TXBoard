# Native traffic and quota acceptance

This opt-in runner owns only the exact synthetic `txboard_native_traffic_test` database/account and a distinct Redis prefix. It starts real PHP Core, Workerman, a trusted transparent same-origin TLS proxy, ordinary native Node protocol streams and a Redis worker. The caller provisions an empty schema; any repeat first backs up and resets that exact fixture. Credentials remain in private files, not the public result.

Run `python3 scripts/tests/node-native-traffic/run.py API NODE NEW_EVIDENCE vless|vmess` with `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=13306`, `DB_DATABASE=txboard_native_traffic_test`, `DB_USERNAME=txboard_native_traffic`, the synthetic `DB_PASSWORD`, and local Redis host/port. `REDIS_DB` and `REDIS_CACHE_DB` must differ; defaults60/61 require a local Redis configured with at least62 databases, while CI supplies8/9.

Production meter/report/settlement code produces all tested traffic. The proxy only forwards bytes or injects an explicit transport fault; it does not forge Node counters or Workerman frames. SQL receive, rate/day freezing, exact replay, queue loss, worker settlement, quota and registered administrator reset are observed separately. The independent `receipt_fault.php` helper kills its own disposable PHP worker at an actual SQL transaction boundary on an original Node-produced receipt. It never writes a synthetic usage payload.

Signed traffic periods identify the authorization epoch observed by the Node. They do not prove an offline wall-clock lease cutoff, production PKI, all protocol variants, HA, or a global producer/queue drain. The public result states which actual cases passed. Local compile with no fixture skips the live test and cannot substitute for this runner.
