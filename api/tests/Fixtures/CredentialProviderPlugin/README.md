# Trusted Provider plugin boundary fixture

This is a B4.3 local/testing developer fixture, disabled by default. It calls
the actual PluginManager and ModuleLifecycle adapter, an isolated Core SQL
transaction and the existing synthetic Provider process. It adds no HTTP route
or production plugin installation. PHP Plugin Package v1 runs trusted in
process; this fixture does not isolate hostile PHP or interrupt another worker.

The package contains only `Plugin.php` and `config.json`. The test launcher
copies those two files to a disposable reserved package directory; this README,
launcher and evidence report are not installed as plugin payload. Tests refuse
an existing package or asset directory and remove only their own fixture paths.
They retain package, configuration, receipt/tombstone and Core histories while
checking removal refusals, then clean their disposable test data in teardown.

## Reproduce

Use a complete fixed Core checkout, not an `api`-only archive. The recorded
baseline is `60e6ecae545078e7e771aa73c429828a557b0e27`. Overlay only the ten
tested code/fixture files in [verification.json](verification.json), copy the
locked Linux `api/vendor` into the snapshot, and run
`composer dump-autoload --no-scripts --optimize` there. A vendor symlink can
resolve application autoload paths to the donor checkout and is unsuitable.
No dependency or lockfile changes were made. Use a test `.env` derived from
`.env.example`, a disposable APP_KEY, Redis `127.0.0.1:16379` and databases
20/21; never copy an operator's private environment.

From `api` on Linux with PHP 8.4, SQLite, zip, pcntl and posix extensions:

```bash
bash tests/Fixtures/CredentialProviderPlugin/run-linux.sh --sqlite
bash tests/Fixtures/CredentialProviderPlugin/run-linux.sh --full
```

The MariaDB test requires a pre-created disposable `txboard_provider_test`
database and a dedicated `txboard_provider_fixture` user granted only that
database. Supply its test-only password through `DB_PASSWORD`; the launcher
does not create users or broaden grants.

```bash
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=13306
export DB_DATABASE=txboard_provider_test DB_USERNAME=txboard_provider_fixture
# Set DB_PASSWORD to the dedicated local test user's password.
bash tests/Fixtures/CredentialProviderPlugin/run-linux.sh --mariadb
```

This mode refuses other database/user names. Each test migrates and drops only
its listed disposable fixture tables. Do not run against a shared database,
production data, the concurrency database or another agent's Redis namespace.
Forked database sessions are joined; barriers and private process state are
removed. A failing run may leave disposable fixture paths for investigation;
inspect their ownership before cleaning them.

## Verified boundary and limits

The tests exercise both-state rollback on a real SQL trigger failure, permanent
fencing before PHP cleanup, cleanup exceptions, missing/damaged schema, lost
binding with recorded operations, current grants/runtime identity, ordinary v1
query isolation, real archive replacement refusal and Module adapter behavior.
Canonical code checks prevent MariaDB collation aliases from selecting the
reserved row through an ordinary mutation path. A separate-session issue waits
for an uncommitted fence and fails after commit; SIGKILL after commit preserves
the original disable reference and permits bounded private cleanup recovery.

Hooks are removed from the current container. Loaded PHP and previously
registered callbacks on other workers are not cancelled, and there is no
cross-worker acknowledgement. A boot check is not atomic with PHP execution.
Unknown database history loss is not safely recoverable through this fixture.
Even completed private cleanup refuses uninstall/delete because Node drain,
lease expiration, secret-delivery convergence and settlement handoff are not
implemented. No production credential, TLS, Node or HA acceptance is claimed.

See the [Core contract](../../../../contracts/v2/credential-provider/local-fixture-plugin-boundary-v1.md)
for the lock order and authority boundary. Actual versions, source hashes and
the distinction between total, passed and skipped tests are in the report.
