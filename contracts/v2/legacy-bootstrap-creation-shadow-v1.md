# Legacy installer bootstrap creation evidence v1

> Retired: 2026-10-16 native development cleanup removes this capture runtime, flags and verifier entry points. The text below records historical behavior only. Database migrations and existing evidence remain; current account writes do not emit this shadow evidence. See [Core cleanup](../../docs/development/core-cleanup.md).

Status: Current isolated slice. Covers only Core TxboardInstall::registerAdmin's actual User save. It does not replace TXBoard-Deploy, run host commands, refactor full installer CLI output or reconstruct old bootstrap history.

Strict default-off `LEGACY_BOOTSTRAP_CREATION_SHADOW_ENABLED` requires local/testing plus the existing isolated-copy capture context. Disabled mode calls the original save directly and adds no transaction/context/evidence query. Enabled mode accepts only a new unsaved User and rejects an outer transaction, then atomically saves User and its initial evidence. Native short-password validation, PASSWORD_DEFAULT hash, UUID/token generation, is_admin and defaults stay in Core's original method. Native saving cancellation returns false; Observer timing remains before registerAdmin returns after commit.

The shared initial table records fixed `install.admin.create`, version `bootstrap-creation-shadow/v1`, actual inserted User as actor_id, fixed context and canonical closed 15 nonsecret source fields. This self reference is a creation source reference, not an authenticated administrator claim. Admin/registration gates and hashes retain their original versions. Each verifier rejects other source policies. No installer email/password/token/UUID, live credential, financial journal, Terms, subject/grant, mapping or completion marker enters this evidence.

`core:bootstrap-creation-shadow-verify --user=<id> --isolated-copy` checks bounded immutable initial/context/content evidence, current endpoint and at most the first captured mutation in a fresh SELECT-only transaction. Changed/missing User is explicit. Model/schema history protection remains shared. It cannot attest to installation completion, default plugin installation, external effects, entire mutation-chain history or production migration readiness.

Only save/evidence SQL is atomic. Errors after commit, after-commit Observer, full install settings/plugin/filesystem effects and the original console's credential output are separate boundaries. Existing credentials are never silently regenerated or reused by a new migration key. All production switches remain off.

Acceptance requires actual Core registerAdmin execution, native credential/role/default semantics, independent default-off gates, cancelled save and rollback, original duplicate-email behavior, policy/hash compatibility, read-only verification, and real independent MySQL session visibility. Host installer lifecycle is not part of this slice.
