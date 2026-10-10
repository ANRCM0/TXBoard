# TXBoard internal V1/V2 cleanup

> Development refactor after PR #182. Source-of-truth routes are `/txapi/*` and the independent subscription URL.

## Deleted and rehomed code

- **28 controller files removed** from `api/app/Http/Controllers/V1` and `V2`. These PHP namespaces are no longer supported. No compatibility class aliases are registered.
- **Subscription output is live**: `V1/Client/ClientController` was moved to `App\\Http\\Controllers\\SubscriptionController`. The signed/specified subscription URL remains `/{subscribe_path}/{token}`, served through the `client` token middleware; raw subscription formats are intentionally not JSON envelopes.
- **Agent is live**: the three V2 Agent controllers were rehomed under `App\\Http\\Controllers\\Txapi\\Agent`. Their existing scoped Agent auth, pairing, approval and response semantics stay unchanged; moving a class must not silently change a protocol response.
- **Middleware retired**: `User`, `Server` and `ServerV2` and their Kernel aliases were removed. `TxapiUser`, `TxNodeAuth`, `AgentAuth` and the `Client` subscription middleware remain in use.
- **Workerman transport**: `NodeWorker` accepts the native `/txapi/node/v1/ws` upgrade, header credentials and versioned `NativeNodeFrame` envelopes only. Old `/ws?token=...` authentication and unversioned JSON messages are rejected. Its device push, Redis fanout, node/machine reconciliation and disconnect cleanup remain.

## Not a schema wipe

`v2_*` tables and columns are **active persisted models and ledgers**, including orders, traffic, accounts and audit records. Their historical prefix does not make them unused. This refactor does not drop or rename any database object. A schema rename requires explicit migration, backup/restore and ledger reconciliation work.

## Regression expectations

- `php artisan route:list --json` shows zero registered `/api/v1/*` or `/api/v2/*` routes.
- The PHP contract suite verifies that old controller directories and middleware files are gone and that native Agent and subscription routes point at their new classes.
- Native Node WebSocket tests verify that a query-token upgrade on `/ws` and an unversioned message are not accepted.
- Run the full API, MySQL, route-inventory, container-image, frontend and MCP checks on this branch before merging. These checks do not substitute for live payment, external Node/Agent or staging tests.

## Deferred follow-up (not a reason to resurrect V1/V2)

Audit helper methods in `NodeEventHandlers` for event types not yet supported by the native WS inbound schema, and remove historical audit masking or config comments only after checking persisted records. In particular, do **not** delete log redaction code solely because it names a former API: old stored events may still contain sensitive paths.
