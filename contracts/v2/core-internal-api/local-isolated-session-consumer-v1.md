# Isolated Gateway and SDK session consumer v1

> 已退役（2026-10-16）：本文保留旧隔离切片的历史协议和验收说明。对应 Core/Gateway 运行入口、配置、SDK 原型消费者、夹具及专属 CI 已移除；下文 Current/local-testing 状态仅描述当时实现。正式 Internal API 仍属 C4 待交付，不提供旧路径或旧版本兼容。见 [当前清理边界](../../../docs/development/core-cleanup.md)。

Status: Current / verified isolated B5.14. See [fixed-source evidence](../../../docs/architecture/v2/internal-session-consumer-verification.json). Delegates the [current Core session operations](local-isolated-sessions-v1.md); no production or SC-v2 activation.

`GATEWAY_INTERNAL_SESSIONS_ENABLED=true` requires an existing local/testing Internal mTLS configuration, HPKE with its mandatory replay store, Redis account limiter, and disabled legacy account workflows. The independent default-off Core session and isolated-copy gates remain required. No Native fallback, shared service actor, Gateway session database, refresh token or automatic mutation retry is introduced.

| Gateway route | Core operation | Request | Public success data |
| --- | --- | --- | --- |
| `GET /gateway/v1/user/sessions` | `user.session.list` v1 | Current User Bearer, no query | `{items:[...]}` |
| `POST /gateway/v1/secure/auth/session-revoke` | `user.session.revoke` v1 | Current User Bearer and HPKE-sealed `{session_id:"<positive canonical decimal, at most 18 digits>"}` | `{ok:true}` |
| `POST /gateway/v1/secure/auth/session-logout` | `user.session.logout` v1 | Current User Bearer and HPKE-sealed `{}` | `{ok:true}` |

Mutation AAD uses the existing v1 prefix with distinct `session-revoke` / `session-logout` operation labels. All previous operation bytes remain unchanged. Plaintext mutation routes do not exist. This is the existing v1 request encryption over HTTPS; response encryption and SC-v2 remain separate gates. The current Bearer travels in Authorization and is included in the Internal boundary proof; Core revalidates actual session ownership and User role.

List is bounded to 100 current owner rows with decimal-string IDs, names, abilities and native nullable ISO timestamps. The Gateway accepts only the closed Core envelope and exact `internal-isolated-sessions/1` metadata; unknown fields, token/hash fields, duplicate or non-descending IDs, invalid timestamps and excess rows fail closed. All responses use no-store; no session list is shared or cached. All routes share a fail-closed Redis budget of 30 requests per current Bearer and 60 per sanitized source IP per minute. Credentials are case-sensitive and only SHA-256 digests enter limiter keys. This is a credential budget, not a multi-token User identity budget.

SDK exposes `auth.sessions.list()`, `auth.sessions.revoke(sessionId)` and `auth.logout()`. Mutation input IDs stay strings, with no Number conversion. The SDK never stores or clears the caller's token; successful logout/revoking the current session requires the caller to update its own state. POST is attempted once. Loss of acknowledgement, deadline, audit failure or transport failure can conceal a committed deletion; use another valid credential to inspect remaining sessions. Retrying a revoked current credential fails authentication, and repeated/foreign revoke retains the native 404.

Acceptance requires actual SDK → Gateway → TLS1.3 boundary → Core/Sanctum/MariaDB plus real Redis, owner/role checks, encrypted replay and wrong-operation rejection, list bounds and credential exclusion, rate-store failure, lost/unknown mutation result, no Native calls, and full Gateway/SDK regression.
