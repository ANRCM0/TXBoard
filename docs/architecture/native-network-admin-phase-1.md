# TXBoard Native Network Admin — Phase 1 (Group / Route)

> Development work only. Native TXAPI is the sole new management contract. TX-Node follows TXBoard's eventual network protocol changes, not the reverse. This does **not** attest to production or external Node compatibility.

## Scope and decisions

- Admin groups use `GET/POST /txapi/admin/{admin_path}/network-groups` and `DELETE .../network-groups/{id}`.
- Admin routes use `GET/POST /txapi/admin/{admin_path}/network-routes`, `PUT .../network-routes/sort`, `POST .../network-routes/simulate`, and `DELETE .../network-routes/{id}`.
- React Admin retains existing UI-level `server.ts` exports but imports underlying native service modules. V2 `server/group/*` and `server/route/*` registrations and unused controllers are removed, not proxied.
- TXAPI admin authentication remains Sanctum administrator + rotating path + audit + 120/min top-level throttle. No user, Node or Agent identity is accepted as an administrator.
- Lists expose explicit DTO fields, not full arbitrary Eloquent model data. Node membership counts scan projected IDs in chunks instead of issuing one per-group/per-route query. Group deletes reject existing User/Plan/Node references; route deletes reject assigned nodes.
- Route simulation preserves the existing panel-rule evaluation rules (built-in private range checks, ordered rules, unresolved geosite/geoip patterns); it is a panel-side simulation, **not** a guarantee about what any not-yet-adapted Node runtime will do.
- No live Node protocol, machine runtime, financial callback, schema migration or actual deployment was modified by this increment.

## Breaking-change policy for development

The project is still in development. Do not create or prolong V1/V2 admin compatibility just for hypothetical external clients; migrate the official first-party callers then retire their old route registrations and implementations. TX-Node is responsible for conforming to the future TXBoard native wire protocol; no cross-repo compatibility shim is a release requirement. Existing payment/ledger invariants and permissions remain non-negotiable, and explicitly altering the Node transport is a separate change with its own contract and tests.

## Evidence and outstanding work

- PHP feature tests exercise admin authorization, path isolation, validation, CRUD, in-use protection, stable ordering and retired V2 paths.
- TypeScript contract tests assert all migrated requests use the instance-specific TXAPI path.
- The strict release audit still blocks while node/machine and other management modules use legacy endpoints.
- **Not yet verified in this milestone**: live Node/Machine operations, full TX-Node adaptation, group/route behavior in a running data plane, staging database backups/restore and production deployment.
- Next: native node CRUD/protocol generators, then machine registration/credentials/telemetry/runtime actions and retirement of their V2 admin routes. After that the TX-Node repository can implement the resulting native wire contract without compatibility shims.
