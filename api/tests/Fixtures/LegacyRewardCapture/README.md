# Isolated legacy reward capture fixture

Run `LegacyRewardCaptureTest` in local/testing with disposable synthetic data.
The default connection is a fresh named in-memory SQLite database. Explicit
`CORE_CAPTURE_TEST_MYSQL=1` uses only `txboard_concurrency_test`; the test refuses
other database names before dropping/recreating fixture tables. Never use site
data or production credentials. MariaDB runs require exclusive fixture access.

The fixture covers actual one/multiple-level commission, wallet mode, native
fractional assignment, partial Order batches, same-User cycles, gift balance and
quota/expiry/plan/reset, inviter rewards, random/festival results, native limits,
source gaps, storage/late SQL rollback, default-off query boundaries and readonly
verification, cross-policy refusal and the native ignored code-save soft failure.
Independent local sessions demonstrate stale commission duplicate
work, single-use gift arbitration and SIGKILL before/after transaction commit.

These are current persisted User endpoints, not authoritative journals or a
v2 subscription projection. Native commission duplicates remain possible;
reusable gifts retain their original repeat behavior. Hook/cache/notifications,
power loss, Oracle MySQL 8 and production activation are separate evidence.

See [contract](../../../../contracts/v2/legacy-reward-capture-v1.md).
