# Core Account Sessions v1

> K4 candidate subset, 2026-10-17. Stacked on order-read candidate `8734af1`,
> not a merged baseline. Exact-commit CI and domain review are pending.

## Ownership and scope

Identity owns `Contracts\AccountSessions` and the scoped implementation
`NativeAccountSessions`. HTTP and Internal API entry points delegate the same
existing Sanctum session lifecycle, without another session store, new route,
wire field, permission, migration or general-purpose command bus.

| Method | Input | Result |
| --- | --- | --- |
| `listing(int $userId, ?int $limit = null): array` | Actual authenticated actor ID; existing optional server-side limit | Existing descending-ID session projection |
| `revoke(int $userId, int $sessionId): bool` | Actual actor ID and target session ID | True only when that actor's token is deleted; missing or other-owner target is false |
| `end(int $userId, ?int $currentTokenId): void` | Actual actor ID and actual current deletable token ID, or null | Delete only that actor's current token; null/missing is a no-op |

Scalar IDs are not authorization proof. The entry point resolves the actual
actor and current token; caller input never selects the owner or the token used
by `end`. HTTP preserves its existing null/transient-token no-op behavior. The
Internal API continues to require an actual Sanctum PersonalAccessToken.

Listing preserves `id`, `name`, `abilities`, `last_used_at`, `created_at`,
`updated_at`, `expires_at`, date formatting and optional limit semantics. It
does not expose the token hash or bearer. HTTP IDs stay integers; Internal API
IDs pass through the existing decimal-string projection. No new list bound or
input option is introduced in this batch.

Mutations use the existing `AccountSqlPhase::SESSION_REVOKE`: installation
admission, managed transaction and actor User lock precede owned-token deletion.
`end` retains the owned-token row lock and missing-token no-op. No outer SQL
transaction, network call, unlocked bulk deletion or cross-user authority is
added. Password change/reset, registration/login, admin bulk session revocation
and existing AuthService participants are explicitly outside this slice.

## Entry points and retries

| Entry | Target | Preserved protocol behavior |
| --- | --- | --- |
| HTTP `AuthController::sessions` | `AccountSessions::listing` | Current Sanctum actor, optional server-side limit, existing envelope |
| HTTP `AuthController::revoke` | `AccountSessions::revoke` | Missing/other-owner target remains 404; success is `ok: true` |
| HTTP `AuthController::logout` | `AccountSessions::end` | Only current deletable token; `ok: true` including no-op |
| `identity.sessions.list` | Fixed `SessionReadOperations::listing` | Both service domains; current authenticated actor; read/no receipt |
| `identity.sessions.revoke` | Fixed `SessionMutationOperations::revoke` | Existing `workflow` receipt; `session_id` only; missing target remains 404 |
| `identity.session.end` | Fixed `SessionMutationOperations::end` | Existing `once` mode; no receipt and no input fields |

The existing read-handler rule remains read-only. A distinct compiled command
target is admitted only for the two exact session operations, classes, methods
and modes above, with a non-null authenticated actor and no controller/read
handler. No dynamic plugin/caller target, arbitrary status or permission decision
is accepted. `MutationOperationResult` is adapter-local array data; a null revoke
result uses the same normalized 404 exception path as the previous controller.

Commands run after the existing receipt admission, not around it. Authentication,
current session/account policy, service domain/operation scope, admin path, role,
current token ability and grant checks still occur before receipt replay. Revoke
uses the same actor/operation/key and input hashes. Completed success can replay;
conflicting input rejects; started/unknown workflow outcomes are not re-executed.
Exceptions retain the prior started-receipt behavior rather than becoming a new
retry path. Ending or revoking the authenticating token means later calls must
fail authentication even if an older receipt exists.

All successful handler results rejoin the existing allowlist, recursive secret
redaction, decimal conversion, size limit, normalized dependency errors and
private/no-store response pipeline. Receipt encryption and Internal audit remain
entry-owned. Neither a command result nor a domain interface bypasses service or
actor authorization. PHP plugins remain trusted in-process code, not a sandbox.

## Acceptance and exclusions

Targeted tests cover actual alternating Bearer owners, HTTP/Internal shared-port
replacement without controller resolution, owned and cross-owner/missing targets,
actual-current-token selection, transaction rollback and installation rejection,
closed input, current admin permissions and service scope, workflow replay,
conflicts and unknown outcomes, response filtering/bounds and sanitized failures.
Static guards cover pure contracts, concrete-owner access and host-free fixed
adapters; these are heuristics, not a runtime authorization proof.

Local checks remain static only. Runtime/MySQL acceptance runs in Actions for
the exact candidate commit. This subset does not finish K4/K5, migrate all
controller dispatch or authorize merge, production release or image publication.

See [account reads](core-account-read-ports-v1.md),
[order reads](core-order-read-ports-v1.md) and
[Internal API](native-internal-api-v1.md).
