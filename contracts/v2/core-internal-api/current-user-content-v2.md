# Local Internal subscription summary and notices v2

Status: B5.5 implementation verified, local/testing only. Fixed operations
`user.subscription-summary.get` (exact empty input) and `user.notice.list`
(`page` integer 1..9999, `per_page` integer 1..100, no optional keys) require
the current non-admin Sanctum User and `user-gateway` service proof.

The summary delegates the existing Account subscription read and returns only
the closed display fields: current plan reference/traffic limit, upload,
download, traffic limit, reset day, device/speed limits, expiry and next reset.
IDs, bytes and device limits are decimal strings; dates are nullable seconds.
The existing private subscription URL may be constructed inside the native
Core read but is excluded from the Internal DTO, Gateway response and audit.
Missing current plans keep the original Core 409 rather than guessing Terms.
This reads the current legacy authority and does not activate shadow subjects.

Notices delegate the native visible-only sort/query/pagination and preserve
title, content, image URL and tags. IDs are decimal strings and creation is in
seconds. Live pages are not a snapshot; Gateway rejects inconsistent counts,
duplicate IDs, unsafe numbers and extra fields. The existing 64KiB response
and five-second total deadline remain; large content fails without fallback.

The local Gateway opt-in selects `/user/subscription/summary` and `/notices`
while retaining public DTOs and the default Native transport. Neither read
grants anonymous access, returns credentials, mutates subscriptions or permits
checkout. Existing actor/trust/replay/rate/audit gates apply; production PKI,
Admin access and SC-v2 require their own acceptance.

Fixed Core `60e6eca` and Gateway `31bde17` plus only this slice passed 806
Core API tests (29 skipped, 6482 assertions), 526 Gateway and 68 SDK tests,
typecheck/build and 35 real Core/MariaDB/TLS1.3 checks. They verify current
subscription display, missing-plan 409, credential exclusion, visible notice
sorting/content/pages, strict dispatch/input, unsafe number or field rejection,
current User/service gates and no fallback. These equivalence checks apply to
supported non-admin User actors. [Source and verification evidence](../../../docs/architecture/v2/internal-user-content-verification.json).
