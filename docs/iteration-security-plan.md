# Iteration plan and evidence boundaries

This plan follows the autonomous frontend, backend and security review requested
on 2026-10-24. Source findings are separate from functional acceptance. This is
a prioritized first iteration, not a certification that the project has no
other vulnerabilities.

## Priorities

| Priority | Area and finding | Implementation and acceptance |
| --- | --- | --- |
| P1 | Historical audit search uses raw payloads and URIs even though responses redact them. Matching counts can disclose private values. | Restrict keyword search to method, source IP and administrator email; prevent raw legacy action filters from probing rotated paths. Regression cases run in Core API and the MySQL internal administration shard. |
| P1 | Locked npm graph has security advisories in runtime and development packages. | Inventory exact locked versions, review upstream advisories and compatible fixes, then make a separate dependency change with type/build, component and browser acceptance. A package advisory alone does not establish an exploitable application path. |
| P2 | User startup reads local storage before mounting Vue. A storage policy exception can prevent mounting. | Fall back to browser language when storage is unavailable; retain supported saved-language behavior. Add storage-denial regression cases in Web CI. |
| P2 | Markdown preview images and links inherit the current administrator document as their referrer source. | Set explicit no-referrer attributes while preserving Markdown source and existing raw HTML/executable-link rejection. Cover rendered attributes in Web CI. |
| P2 | Installation recovery needs usable tools before activation succeeds. | Companion Core and Deploy diagnostic changes provide bounded, redacted reports and preserve original recovery intent. Paired immutable-image acceptance covers fresh, upgraded and interrupted installation states. |
| Follow-up | Backend ingress, service identity, module/package boundaries and public content/attachment paths. | Initial source review inspected admission, original actor grants, fixed operation bindings, bounded Provider transport, audit projection, static asset rendering and session-owned attachment transfer. Expand adversarial cases only from concrete findings; preserve transaction, receipt, grant and recovery owners. |

## First source change

Audit search no longer scans historical bodies or URIs. The frontend describes
the accepted metadata filters. Existing historical response sanitization,
administrator authorization and pagination remain active. No historical audit
rows are rewritten. Legacy actions containing a private administrator path are
unsearchable even with case variants accepted by MySQL collations.

The browser changes preserve language selection and Markdown authoring while
making their failure and privacy behavior explicit. They introduce no new
business operation or authority.

## Validation and delivery

Local checks are restricted to source, syntax, boundaries, types and builds.
Functional, database, network and browser acceptance run in GitHub Actions.
Record the exact source SHA, tested tree and final selected job conclusions in
the pull request. Dependency metadata queries are an inventory, not application
acceptance. Preserve lockfiles and report residual advisories with their actual
reachability and fix requirements.

Keep the diagnostics change and dependency changes reviewable separately.
Draft pull requests and successful candidate checks do not imply merge, image
publication, release or production deployment.
