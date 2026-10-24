# Dependency security and browser compatibility

The 2026-10-24/25 locked npm inventory reported ten vulnerable package entries,
including inherited parent entries. This count is not ten independently
exploitable application vulnerabilities. The update addresses the locked graph
and adds repeatable audits to the existing CI entry point.

## Reviewed updates

| Package or group | Selected version | Scope |
| --- | --- | --- |
| Zod | 4.6.7 | Core SDK and administrator sign-in validation; GHSA-mrhx-c7xc-m3w5 |
| React Router DOM | 7.18.4 | Browser data router, guarded navigation and runtime basename; GHSA-wrjc-x8rr-h8h6 and GHSA-337j-9hxr-rhxg |
| Form resolvers | 5.9.2 | Zod 4-compatible sign-in adapter |
| DOMPurify | 3.4.17 | Exact root override for Monaco 0.57.0's pinned dependency; GHSA-cg2g-xh64-v4q7 and GHSA-mrv2-3pqr-j5jr |
| Vite | 6.4.5 / 7.3.7 | Existing administrator/user major lines; development-server advisories |
| Vitest | 4.1.12 | One reviewed test version across workspaces; mocker and worker dependency advisories |
| source-map-js | 1.2.2 | Build dependency; GHSA-68fv-2mgg-jv7q |

The regenerated lock removes the retired Gateway SDK entry and deduplicates
the workspace graph. Existing DM Sans and Manrope versions remain pinned to
5.2.8. Monaco, React 18 and the PHP lock remain on their existing versions.
Do not use an audit tool's forced downgrade or an incompatible schema adapter
as a substitute for compatibility validation.

## Preserved contracts and loading behavior

Core's canonical operation JSON remains the source for PHP resources and the
same-repository SDK. The generator emits referenced definition validators once
in dependency order, preserving the full Zod schema API, closed objects,
Unicode code-point lengths, safe integer limits, anchored patterns and exact
SQL decimal identifiers. Composing a derived schema must not mutate an
operation's validator. No operation, grant or transaction owner changes.

React Router retains its data router because unsaved-draft blocking depends on
it. The obsolete v6 future flag is removed. The authenticated layout loads only
after its guard admits the route. Confirmation state lives in one UI-independent
store shared by session cleanup and editor hooks; the former component module
re-exports the existing functions for compatibility. The host subscribes to the
same snapshot when it mounts, including a request queued before its lazy shell
loads. Abort, supersession, cancel and action ordering remain owned by that store.

These changes keep the existing initial JS, route, CSS and editor budgets.
They do not raise the budget or defer the whole application to an artificial
entry chunk. SDK classes and transport validation remain available to consumers.

## CI and evidence

Web CI runs the locked npm audit and fails on high or critical findings.
Lower-severity findings remain in its uploaded JSON. Core API CI runs Composer's
locked audit and retains its JSON even on failure. An unavailable audit is not a
successful security check. The ordinary component, production/development
browser, real Core HTTP, MySQL and native integration checks remain required
according to the existing selector.

Regressions cover invalid sign-in data without credential requests, exact
decimal limits, Unicode lengths, nested unknown fields, nullable timestamps,
public schema composition and confirmations queued before host mounting.
Existing browser acceptance covers runtime administrator mounts, drafts,
permission withdrawal, logout, lost-response recovery and editor workers.

Local type/build and source checks are separate from Actions acceptance.
Advisory snapshots, exact source/tested trees and run conclusions belong in CI
artifacts and pull requests. A clean dependency audit does not establish the
absence of application vulnerabilities, an OS/container scan, a release or a
production deployment.
