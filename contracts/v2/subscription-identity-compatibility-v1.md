# Legacy subscription identity compatibility read v1

Status: Retired on 2026-10-16. Historical design and validation record only.

The native development baseline does not require transitional identity
observations. The reader, middleware, request attribute and dedicated tests have
been removed. Current subscription routes retain their actual authentication
middleware without this observer. Historical database migrations and stored
evidence are retained; no data is deleted or converted by this cleanup. The
remainder records the retired behavior and its previous validation.

The native `client.subscribe` route still authenticates `User.token` through
`Client`, including the existing precedence of a query token over a path token.
`SubscriptionController`, `UserService`, `ServerService` and native protocol
renderers continue using the original User. This adapter observes a current
User-to-Subscription-to-Subject identity; it does not grant access.

## Activation and permissions

`core_shadow.subscription_identity_read` defaults to false. Both this flag and
`core_shadow.isolated_copy` must be explicitly true, and the application must be
`local` or `testing`. Disabled middleware performs no shadow reads. Unsafe
activation produces a fixed, request-local blocked observation while the native
response continues unchanged. There is no additional HTTP route or permission.
The adapter receives only the numeric User ID already authenticated by `Client`.
It cannot authenticate a token, User/Admin session, Node or Provider operation.

The request attribute `core_shadow.subscription_identity` has a fixed shape:
`version`, `scope`, `authority`, `authorizes`, `status`, `error_code`,
`legacy_user_id`, `subscription_id`, `access_subject_id`. IDs are canonical
decimal strings. `authority` is `legacy` and `authorizes` is always false.
Successful reads are `mapped`; unavailable or ambiguous evidence is `blocked`
with a fixed code. It is not added to the response, headers, logs or SQL.
No static mutable state, process cache or persistent observation is created.

## Read consistency and limits

The reader rejects an existing outer database transaction. It starts its own
transaction and takes current shared reads of the native User, persistent slot,
mapping and subscriptions in ascending ID order, then the mapped original
Terms, Period, Subject and exception. Cooperating shadow writers acquire the
exclusive User lock first; the reader never upgrades that shared lock and never
creates a slot. A mapped slot belonging to another subscription blocks the read.
No legacy or shadow table is modified, including verified timestamps.

Only explicit identity columns are selected. Source snapshots, historic orders,
Terms payloads, counters and credentials are not loaded. Each identity query
returns at most one record; the subscription set has at most two records.
Credential collisions use column-to-column native User self joins, limited to
the authenticated User and one conflicting User ID. Blank credentials are
checked inside SQL. Credential bytes are neither selected by this reader nor
bound to its queries. Equality follows the native database collation; this is
current ownership evidence, not a new token verifier or a global uniqueness
constraint. A concurrent legacy writer that ignores these locks remains outside
this proof; use a quiesced isolated copy for migration acceptance.

The map must be `mapped`, have valid migration-key/hash/version syntax, and have
no unresolved exception. Its Subscription must belong to the native User, its
original Terms and Period to that Subscription, and that Period to those Terms.
Its Subject must belong to the Subscription with `legacy-shadow` and the exact
nonsecret `user:<id>` provider reference. Missing objects, cross ownership,
unverified maps, blank or duplicate token/UUID identities block observation.
Subject status, current Terms and entitlement are not new authorization inputs.

## Compatibility and remaining gates

Missing, damaged or ambiguous shadow data cannot change the native response.
Existing banned/expired/zero-quota checks and the native ability to download an
exhausted positive-quota user's configuration remain as implemented. Native
protocol responses still contain their existing credentials for the authenticated
legacy user. No extra credentials or private URLs enter shadow DTOs or logs.
Native User/Admin credential rotation remains authoritative: the old URL fails,
the new URL renders the new UUID, and the stable mapped IDs remain unchanged.

This slice does not verify full source hashes or historical identity ownership,
import UUIDs into a Provider, create SubscriptionLink digests/key lifecycles,
rotate credentials, deliver Provider authorization, alter Node lists or switch
writers. A `mapped` observation is not a full migration-reconciliation result or
an authorization decision. Historical link retention and secret-import evidence
still require their separate migration gates.

## Validation

Synthetic fixture validation passed on SQLite (20 passed, 2 MariaDB-only tests
skipped, 418 assertions) and MariaDB 11.4.12 (22 passed, 0 skipped, 429 assertions).
Both run the actual native route, Client, controller, server selection and all
11 native renderer families, including SS2022 password derivation. They compare
status, body and relevant native headers with observation disabled and enabled;
cover query-token precedence, configured paths, availability, filtering, duplicate
identities, damaged mappings, native credential rotation and Node REST UUIDs.
Reader query logs contain SELECTs only, explicit bounded identity selections and
no credential bindings. Shadow table snapshots remain unchanged; no reader logs
are emitted, including SQL failures. These are synthetic-copy checks, not a
production link migration or global concurrency fence.

Independent MariaDB sessions prove the reader waits behind an uncommitted User
writer and sees a committed UUID collision afterward. A stale outer REPEATABLE
READ transaction is explicitly rejected without querying; a subsequent fresh read
detects the changed mapping.

The full API gate used a complete ext4 archive of committed Core
`846a1818c96a075853631b9a6bab72b0f18342ce`, overlaid with exactly this slice's six
files and matching locked vendor, with dedicated Redis databases 16/17. It passed
765 tests, skipped 21 engine-specific tests, and made 5927 assertions with zero
failures or warnings. It includes the current User/Admin/Node/payment regression
suite. The slice adds only this observation middleware to the existing subscription
route; no endpoint, token writer or Provider capability is added.
