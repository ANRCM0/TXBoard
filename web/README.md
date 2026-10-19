# TXBoard Frontends

The frontends are source workspaces in the TXBoard repository. Develop, review,
build, and deploy them together with the same revision of the Core SDK. The former
TX-F repository is a historical import; its fingerprints remain in
`contracts/frontend/source.v1.json` for provenance. It is not a build dependency.

Run these commands from the repository root:

```sh
npm ci
npm run dev:admin
npm run typecheck:web
npm run verify:web
```

`web/admin` contains the React administrator application; `web/user` contains the
Vue user application. `web/shared` holds framework-independent helpers. Both
applications consume `gateway/packages/core-sdk` through the npm workspace.

Admin calls use the generated, controlled Gateway transport. Core remains the
authority for current sessions, operation grants, record versions, and financial
state. Credentials and unresolved command inputs stay in session memory. Unknown
write results require an explicit retry with the original input and idempotency
key; navigating to another page must not create a replacement action.

This adaptation phase enables support tickets and private attachments, notices,
knowledge, coupons, gift cards and batches, and reviewed commission transfers into
the native wallet. Commission approval is a wallet transfer; external payout and
commission reversal actions are not exposed by the current backend contract.
Unadapted navigation destinations continue to display the existing pending page.
New user application product work remains deferred in this phase.

`web-ci` runs component and browser regression checks. Browser fixtures validate
the built UI against strict generated Gateway contracts and record their synthetic
transport explicitly. Native backend Actions and actual image acceptance are
separate evidence; neither implies a production deployment.
