# Identity domain

`Contracts\AccountQueries` exposes the current native account/subscription read
projections to HTTP and the three fixed Internal API account read operations.
`NativeAccountQueries` delegates entitlement facts to Subscription's public port;
it does not cache actors, read authentication headers or write account state.
`AccountIdentity` is an immutable scalar projection of the entry's current actor,
not an authorization credential. The [precise read contract](../../../../contracts/v2/core-account-read-ports-v1.md)
records entry ownership, output differences and remaining ingress scope.

`AccountSqlPhase` owns the transaction and admission boundary shared by current
identity writers. Its native writer allowlist remains closed; moving the class
does not authorize legacy account creation or finance mutations in native mode.
Consumers use this class directly. The supported writer set belongs to Identity;
it does not depend on a historical migration inventory or worker epoch.

`AccountCreationService` persists registration and installer administrator models
through that boundary. Native registration continues to use the Account domain's
current native identity service. `AdminAccountModeration` owns locked bulk
selection, protected-account checks, banning and session revocation.
`AdminAccountProtection` owns deletion eligibility and atomic session removal;
an Eloquent deletion veto must roll back the transaction.

`AccountSessionGuard` retains Sanctum authentication and token policies while
locking the current account and token for last-used writes. Session issuance and
revocation follow the same account lock order, including nested caller transactions.

User events continue to freeze their source before deferred callbacks. Ordinary
scheduling and Node notifications run after commit. The isolated migration proof
registry, version matrix and postcommit handoff prototype are retired; current
native runtime outbox ownership remains unchanged.
