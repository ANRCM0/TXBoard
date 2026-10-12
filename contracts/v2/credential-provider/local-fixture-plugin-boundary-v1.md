# Local Credential Provider Plugin Boundary v1

Status: B4.3 implemented local/testing fixture slice, default off; not a
production Provider lifecycle or Node drain acceptance.
This slice connects the existing [synthetic lifecycle](local-fixture-lifecycle-v1.md)
to trusted PluginManager disable and uninstall boundaries. It changes neither
production credentials nor the [Provider target lease/revocation gates](README.md).

## Scope and authority

Only the project-owned test plugin code `credential_provider_fixture` is eligible.
Its manifest remains Plugin Package v1; declaring a capability or placing a
provider ID in plugin config does not create a binding. Other plugin codes are
ineligible by both the binding API and a single-value database constraint, and
retain their current v1 behavior without querying a fixture binding table.

A trusted local launcher explicitly binds an installed, enabled plugin instance
to one enabled `synthetic-fixture-v1` registration at its current epoch and script
digest. Both existing fixture flags, local/testing environment and a new
default-off `credential_provider_fixture.plugin_bridge_enabled` must permit
initial binding. There is no HTTP binding endpoint, caller-selected process,
URL, shell command or path. The fixed fixture's independent PHP process remains
under the parent's OS identity. Plugin Package v1 PHP runs trusted in process;
neither runtime is described as an adversarial-code sandbox.

The table `tx_credential_provider_shadow_plugin_binding` stores only
an immutable technical association: binding ID, fixed plugin code, plugin
installation row ID, registration ID/epoch and created time. Unique constraints
prevent multiple bindings for a plugin or registration; restrictive references
retain both histories. It stores no authority, enabled state, cursor, usage,
secret or second business truth. Existing rows cannot safely yield this link:
provider ID and plugin code have unrelated identities, and a manifest/config
claim cannot prove ownership or distinguish reinstallation generations.
Lifecycle state and the existing disable operation remain authoritative in their
current tables. Nonempty binding history prevents migration rollback.

The reserved code is denied on missing/damaged schema or unknown identity.
Closing a feature flag, losing the binding, changing epoch or changing runtime
identity cannot turn a previously bound instance into ordinary v1 behavior.
No path treats a failed schema query as proof of being unbound. The fixed fixture
launcher seeds its own disposable package and installed/enabled row before
binding; this is not an ordinary PluginManager install or a production installer.
Ordinary install/enable, upgrade, replacement and deletion of the reserved code
are denied even when its binding is absent. Automatic worker boot and schedules
require a valid current binding and fixture gates. All other codes are provably outside this bridge's
fixed scope, rather than inferred unbound from a swallowed database failure.

All manager mutation codes are first required to be canonical lowercase ASCII
`[a-z0-9_]+`, consistent with Plugin Package v1. Uppercase, accented and trailing
space/newline aliases are refused before a mutation, since MariaDB's current
collation can otherwise equate them with the reserved row. A missing binding
cannot be recreated once its registration has recorded an operation or lifecycle
row. This is not recovery from unknown database loss: a trusted restore which
loses both the association and all its history requires an external verified
recovery decision, not automatic re-enrollment.

## Atomic disable and lock order

The manager performs existing dependency preconditions before changing state.
For the reserved bound instance it rejects an outer caller transaction, then
starts one Core transaction with this order:

1. Lock the current `tx_plugins` row by fixed code and installation ID.
2. Lock and validate its immutable binding row.
3. Lock the current Provider registration and validate its ID/epoch.
4. Lock the existing Provider lifecycle row; freeze the greatest recorded issue
   ID and set registration issuance closed using the original lifecycle rules.
5. Persist plugin `is_enabled=false` in the same transaction and commit.

The binding ID deterministically names the existing fixture disable business
key. Repeated disables reuse that key and original high-water mark, including
when the plugin was already disabled; the reserved branch bypasses the v1 early
return and verifies Provider fencing. A different disable key or damaged current lifecycle
is a refusal, not an automatic reset. Binding creation also uses plugin row,
binding, registration order. No Provider issue/query/revoke/cleanup path acquires
a plugin row or binding lock after holding a registration lock. These paths
retain their existing registration-first order internally; no reverse edge is
introduced. Deadlock retries cover only SQL without external effects.

After commit, remove hooks owned by the plugin, then call its existing PHP
cleanup outside the transaction. Cleanup failure cannot reopen either durable
state. Provider private cleanup remains a separate explicit bounded call to the
existing lifecycle service, at 1..32 records and a deadline at most five seconds
away; the manager does not loop over an unbounded history or run IPC inside the
plugin fencing transaction. Status and cleanup reuse the bound original key.
They preserve existing query/revoke grants, hashes, receipts and unknown-result
recovery. A SQL failure leaves both states unchanged and prevents PHP cleanup.

An in-flight issuance holding the registration lock completes before disable
can commit. Its recorded ID belongs to the frozen issue prefix. An issue whose
recording committed but whose dispatch has not obtained that lock is denied
after the disable commits. Disable does not cancel a process already executing
under its registration lock, and makes no claim about OS-wide cancellation.

## Uninstall and reactivation gates

Uninstall first applies or verifies the same permanent stop-issuance fence.
It then checks current cleanup evidence. Incomplete, missing or unknown evidence
refuses removal. Even `fixture_cleanup_complete=true` cannot authorize removal:
Node convergence and lease expiration, outstanding secret deliveries and
historical settlement handoff are unimplemented in this slice. The result is a
safe pending/refusal, never ordinary uninstall success. Plugin row, config,
published assets, package files, private receipts/tombstones and all Core
AccessSubject/Usage/Money history remain present. Delete reaches the same gate
before any filesystem deletion, even if the installed row is unexpectedly
missing. No forced override, purge or fabricated Node receipt is provided.

Enable, install, upgrade and package replacement refuse the reserved code before
PHP boot/migrations, asset publication or file promotion, including when the
binding row is missing. This prevents lost history from enabling a reset path.
There is no enable/reset-binding/reset-tombstone operation. A worker's prior
loaded PHP class is not an authorization grant. Existing routes check current
plugin state; the manager checks the fixture before returning an enabled cached
instance. Hook removal affects the current application container, without a
cross-worker acknowledgement. The slice does not claim to interrupt PHP already
loaded or executing on another worker. ModuleLifecycle continues to delegate through
PluginLifecycleAdapter to PluginManager and exposes its existing generic safe
runtime failure; Module Registry does not mutate or directly access Provider SQL.

## Files and verification

New runtime files:

- `api/app/Domains/CredentialProviderFixture/FixturePluginBinding.php`:
  immutable launcher binding and strict current identity/schema validation.
- `api/app/Domains/CredentialProviderFixture/FixturePluginLifecycleGate.php`:
  specialized disable transaction, bounded cleanup/status delegation and removal
  or reactivation refusal; no arbitrary callback or command dispatcher.
- `api/database/migrations/2026_10_12_000011_create_provider_plugin_fixture_binding.php`:
  technical link, constraints and nonempty rollback refusal.
- `api/tests/Feature/CredentialProviderFixture/FixturePluginBoundaryTest.php`
  and dedicated trusted plugin/launcher fixtures.

The three existing files changed after design approval are:

- `api/app/Services/Plugin/PluginManager.php`: fixed-code gates at disable,
  uninstall/delete, enable/install/update, package replacement before promotion,
  worker initialization and schedule registration. No generic Module mutation.
- `api/app/Domains/CredentialProviderFixture/FixtureLifecycleService.php`:
  narrowly extract the existing SQL disable primitive for a verified registration
  lock held inside the manager transaction; its standalone API still refuses
  outer transactions. IPC and fact semantics remain unchanged.
- `api/config/credential_provider_fixture.php`: add only the default-off bridge
  flag. Existing PluginServiceProvider and ModuleLifecycle need no new semantics.

Tests invoke the actual manager and Module adapter with the fixed trusted PHP
plugin and real private fixture process. They cover atomic plugin/provider
disable, cleanup throwing or missing code, already-disabled replay, losing flags
or schema, binding/epoch conflict, incomplete and completed fixture cleanup both
blocking uninstall/delete, reactivation/update/archive replacement refusal,
assets/config/history retention and unchanged unbound v1 behavior. A real
MariaDB session barrier proves an issue waits for an uncommitted manager fence
and is refused after commit. Reserved reactivation is rejected at entry without
waiting for those locks; no concurrent enable success is claimed. Lost-response
and SIGKILL-after-commit recovery retain the original operation and cursor.
No Node convergence evidence is simulated as success.

Use only the independent disposable `txboard_provider_test` database with its
dedicated user; refuse other database names and do not use the existing
concurrency database or Redis databases 16/17. The fixed full API snapshot must
contains complete committed Core
`60e6ecae545078e7e771aa73c429828a557b0e27` plus only the reviewed slice files and
locked vendor, excluding concurrent Internal/Subscription work. Full API gate:
852 total, 821 passed, 31 skipped, 6589 assertions. Dedicated MariaDB 11.4.12
gate: 20 passed, 182 assertions. The two separate-session tests are intentionally
skipped in SQLite; MySQL 8.4 is unverified.

The [trusted fixture instructions](../../../api/tests/Fixtures/CredentialProviderPlugin/README.md)
and [source/evidence manifest](../../../api/tests/Fixtures/CredentialProviderPlugin/verification.json)
record reproducible commands, exact sources, test counts and limitations. A
successful synthetic private cleanup still produces an uninstall refusal;
Node lease expiration/convergence, production credentials and settlement handoff
remain required before a production removal path can be designed.
