# TXBoard Agent permissions (Phase 3)

The `/api/v2/agent` surface requires an authenticated administrator with a
dedicated `agent:` Sanctum token. It explicitly rejects wildcard `*`
abilities and unknown scopes, even when a broadly privileged token has an
`agent:` label. Every read/write endpoint must check its operation-specific
AgentAbility, and node-scoped actions must also enforce AgentTargetScope.

Agent tokens are **not** plugin capabilities. Plugins are currently trusted
server-side PHP code loaded in the Laravel process; restricting their declared
menus/hooks does not sandbox PHP execution or access to payments, order rows,
secrets or other services. Do not install untrusted third-party PHP code.
A real least-privilege extension boundary requires a separate process with an
authenticated, allow-listed HTTP/RPC gateway; that hard isolation is not part
of the current lifecycle refactor.

Failure drills: send an ordinary Sanctum token, a wildcard token renamed to
`agent:`, an Agent token without the required ability, and an Agent token
whose target scope excludes the node. All must be denied without dispatching
a node operation or updating core business state.
