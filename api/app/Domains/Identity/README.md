# Identity domain

`AccountSqlPhase` owns the transaction and admission boundary shared by current
identity writers. Its native writer allowlist remains closed; moving the class
does not authorize legacy account creation or finance mutations in native mode.
Consumers use this class directly, without an AuthorityMigration forwarding alias.

`AccountCreationService` persists registration and installer administrator models
through that boundary. Native registration continues to use the Account domain's
current native identity service. `AdminAccountModeration` owns locked bulk
selection, protected-account checks, banning and session revocation.
`AdminAccountProtection` owns deletion eligibility and atomic session removal;
an Eloquent deletion veto must roll back the transaction.

User events continue to freeze their source before deferred callbacks. Scheduling,
Node notifications and the existing fenced postcommit/outbox retain their current
transaction contracts. The writer fence and its admission registry still live in
AuthorityMigration while their remaining consumers are separated. Legacy capture
configuration, evidence appenders and verifier commands are retired.
