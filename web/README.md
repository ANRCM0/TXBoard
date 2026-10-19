# TXBoard Frontends

The frontends are source workspaces in the TXBoard repository. Develop, review,
build, and deploy them together with the same revision of the Core SDK.

Run these commands from the repository root:

```sh
npm ci
npm run dev:admin
npm run typecheck:web
npm run verify:web
```

`web/admin` contains the React administrator application; `web/user` contains the
Vue user application. `web/shared` holds framework-independent helpers. Both
applications consume `packages/core-sdk` through the npm workspace.

Admin calls use the generated, controlled Core browser ingress. Core remains the
authority for current sessions, operation grants, record versions, and financial
state. Credentials and unresolved command inputs stay in session memory. Unknown
write results require an explicit retry with the original input and idempotency
key; navigating to another page must not create a replacement action.

This adaptation phase enables support tickets and private attachments, notices,
knowledge, coupons, gift cards and batches, and reviewed commission transfers into
the native wallet. Commission approval is a wallet transfer; external payout and
commission reversal actions are not exposed by the current backend contract.
The current administration navigation is connected to reviewed operations.
Module/plugin/theme entries inspect runtime state; lifecycle changes remain in
the controlled deployment flow. Queue pages omit raw job payloads and exception
messages. Dedicated settings and payment configuration forms use versioned
updates and never return existing credential values. Node/machine detail reads
use exact IDs, and traffic resets use the current native subscription version.
New user application product work remains deferred in this phase.

Catalog management reads complete verified publication snapshots and paginated
history. Editing an existing plan publishes a new revision with the observed
current revision as a precondition; it preserves the terms already purchased.
Historical publications remain read-only. Offer availability has a separate
version check, and the capacity for a new publication requires an explicit choice.
Network configuration covers groups, routes and access-audit rules/events. Route
sorting verifies the complete current set, and node routing simulation is a
bounded read that displays any non-authoritative result and unresolved patterns.

`web-ci` runs component and browser regression checks. Browser fixtures validate
the built UI against strict generated browser contracts and record their synthetic
transport explicitly. Native backend Actions and actual image acceptance are
separate evidence; neither implies a production deployment.

Theme packages use `@txboard/theme-sdk` 0.2 and declare `core-browser/v1` with
their explicit operation capabilities. `createThemeClient` exposes the generated
`call`, `callResult`, and `capabilities` methods and keeps credentials in the
host's memory. The minimal consumer lives in `examples/theme-native`.
Build the theme SDK with `npm run build:theme-sdk`. Older themed REST and
experimental HPKE methods are no longer provided.

`tools/module-host` is a development acceptance host for controlled Core module
presentation and operations. It is not a production service.
