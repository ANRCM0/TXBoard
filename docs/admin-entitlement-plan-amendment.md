# Administrator Entitlement Plan Amendment

Source status: authored on 2026-10-21. Functional acceptance remains pending GitHub Actions for the submitted candidate. This document does not claim execution, merge or deployment evidence.

The administrator changes an existing active or suspended subscription through the existing `subscription.admin.amendment.apply` owner. The selected target is a published immutable catalog revision with its plan ID, revision ID and content hash. The request also carries the observed subscription, terms and usage-period revisions, an audit reason and the original idempotency key.

The owner locks the current subscription aggregate and target Plan, rejects an obsolete catalog head, verifies the entire immutable publication and appends new subscription terms plus their transition and permanent receipt. It imports the target quota, access group, speed and device limit. Explicit quota/speed/device overrides remain separate changes. The resulting quota must cover consumed and accepted pending usage. Current subscription `plan_id`, current terms `plan_revision_id`, entitlement queries and synchronized credential/access configuration use the amended plan. Historical terms, accepted node usage and old grants retain their original source and half-open interval.

This operation is an administrator assignment of entitlement. It creates no order, payment, wallet entry, commission or refund. Original purchased order snapshots remain byte-identical. Offer checkout prices, visibility, sale flags and per-offer capacity are not inputs to this operation; the target is a Plan revision, rather than a purchasable Offer. Paid purchase/upgrade/renewal retains the existing checkout owners and restrictions.

Current expiry is preserved unless the request explicitly changes it. The purchased reset policy, timezone, calendar anchor and current period counters remain in effect; selecting a new plan does not grant a traffic reset or a new period. The interface explains these semantics before confirmation.

The new `core-admin-subscription-amendment/v2` evidence binds the selected publication while retaining the original purchased anchor. Existing v1 amendments retain their original verifier. Later non-plan amendments preserve the same publication binding, including after its catalog revision has been superseded. Replay verifies the original immutable evidence and current authorization without requiring the publication to remain the current head.

Administrative terms do not create another paid source for upgrade credit. Financial credit continues to reference the original paid terms and their original coverage; an administrative plan or expiry grant does not create cash credit.

Test sources cover owner/source integrity, effective entitlement and signed node grants, original purchase preservation, permanent replay, stale catalog observations, old repeatable-read snapshots, real Core gateway schema/replay, exact frontend selection and unknown-result recovery. They are not runtime acceptance evidence until the defined Actions checks pass.
