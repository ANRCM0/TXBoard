# Phase 4 — Release qualification and production acceptance

TXBoard's `txboard-image` workflow now treats a main-branch image push as a
**release**. The `publish` job is blocked until `release-gate` passes for the
same commit. The gate runs:

- PHP 8.2 full Laravel API regression suite (SQLite in-memory, Redis service);
- TypeScript checks, tests, production SPA builds, frontend performance budget;
- MCP TypeScript checks and build (Node 24).

The same SHA must additionally pass the container build, healthcheck and MCP\nsmoke tests in `verify`, including on `main`. Both jobs must succeed before\n`publish` can start.\n\nA failed or cancelled gate cannot update the registry `latest` tag. Manual
`workflow_dispatch` runs on non-main branches cannot publish. PR builds still
run the existing Docker image/runtime smoke checks and never push an image.

## Production verification is a separate release step

CI's SQLite suite and container smoke tests are *not* production acceptance.
Before updating a live TXBoard instance:

1. Pin a tested `sha-...` image tag (or immutable digest), not a moving
   `latest` tag. Record the image digest and previous known-good digest.
2. Back up and verify restore of MySQL, persistent storage, plugin packages,
   theme assets, `.env`/APP_KEY, and Redis data required for recovery. Never
   log credentials or copy production secrets into CI.
3. Rehearse migration and rollback in a staging deployment using MySQL 8.4
   and Redis, realistic records, Octane, Horizon and TX-Node. Some MySQL DDL
   and arbitrary plugin effects cannot be rolled back by a transaction.
4. Run the installation/health probes, billing reconciliation, duplicate and
   reverse-order traffic-batch settlement, node offline/reconnect and failed
   plugin upgrade tests. Verify that disabled-plugin routes are inaccessible
   even before a worker restart.
5. Restart Octane and queue workers after any PHP plugin or theme lifecycle
   change, then check the app/HTTP endpoints, queue backlog and scheduler.
6. Exercise a full restore of the known-good image **and** its compatible
   backup on staging. Restoring code alone is not guaranteed to reverse schema
   changes.
7. During a canary rollout, watch error rates, p95/p99 request latency,
   memory/worker restarts, queue delay, ledger settlement delay, and node
   reconnection success. Compare against a measured baseline before rollout.

No live MySQL, production latency/load, restart orchestration, or disaster
recovery run is claimed by CI. These are explicit Phase 4 acceptance gates,
not assumed outcomes of a green GitHub Actions check.
