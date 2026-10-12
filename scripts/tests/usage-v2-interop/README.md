# Usage v2 local HTTPS interoperation

This Linux fixture runs the actual TX-Node `usagev2.Reporter` and durable spool against Laravel's real HTTP controllers and an isolated MariaDB database. TLS uses a task-local self-signed CA certificate trusted by the Go client; verification is enabled. It is a development test, not a deployment.

Prerequisites: PHP dependencies and bcmath, Go for the TX-Node lockfile, Python 3, OpenSSL, curl, and a disposable `txboard_usage_test` database/user. The fixture refuses any other database name. It runs additive migrations, creates non-secret test shadow identities/node/grant, uses ports 18081/18443, and retains temporary logs and spool for inspection. No credentials are committed in the fixture data.

```bash
export DB_HOST=127.0.0.1 DB_PORT=13306 DB_DATABASE=txboard_usage_test
export DB_USERNAME=<isolated-test-user> DB_PASSWORD=<isolated-test-password>
bash scripts/tests/usage-v2-interop/run.sh /path/to/TXBoard/api /path/to/TX-Node
```

The client obtains Core's real handshake grant, captures an explicitly bounded interval, fsyncs it, submits after querying, closes and reopens the spool, replays the exact same submission, runs Core's SQL worker, and confirms the same receipt becomes settled while its payload remains retained. It copies only the required TX-Node package into its temporary module and does not modify TX-Node source.
