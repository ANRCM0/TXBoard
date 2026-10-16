#!/usr/bin/env python3
"""Check current Core ownership and retired runtime references without booting PHP."""

from pathlib import Path
import re
import sys


ROOT = Path(__file__).resolve().parent.parent
SUBSCRIPTION_SERVICES = (
    "NativeCredentialProviderService",
    "NativeCredentialSynchronization",
    "NativePlanCatalog",
    "NativeSubscriptionCalendar",
    "NativeSubscriptionDelivery",
    "NativeSubscriptionStore",
    "PlanPublicationService",
    "PlanAccountPropagation",
    "PublishedOrderService",
    "SubscriptionEntitlementResolver",
    "SubscriptionFulfillmentService",
    "SubscriptionLifecycleService",
    "SubscriptionTermsTimeline",
)
RETIRED_CLASSES = (
    "SubscriptionShadowBackfill",
    "SubscriptionShadowReconcile",
    "SubscriptionShadowReader",
    "SubscriptionSlotService",
    "PlanCatalogShadowService",
    "PlanCatalogShadowReconciler",
    "CommerceShadowService",
    "CommerceShadowLedger",
    "CommerceShadowCodec",
    "LegacyMoneyShadow",
    "LegacyLifecycleShadowReconcile",
    "LegacySubscriptionIdentityReader",
    "SubscriptionIdentityShadow",
    "LegacySubscriptionLinkShadow",
    "SubscriptionShadowModel",
    "ShadowSchemaV1",
    "AuthorityIdentitySqlPhase",
    "LegacyAdminCreationShadow",
    "LegacyAdminDeletionShadow",
    "LegacyBootstrapCreationShadow",
    "LegacyBulkBanCapture",
    "LegacyCaptureShadowException",
    "LegacyPlanPropagationCapture",
    "LegacyRegistrationCreationShadow",
    "LegacySelfCreationPersistence",
    "LegacySubscriptionSnapshot",
    "LegacyUserCreationEvidence",
    "LegacyUserMutationCapture",
    "LegacyCaptureShadowEvent",
    "LegacyUserCreationShadow",
    "LegacyUserDeletionShadow",
    "SubscriptionTermsShadowService",
)
RETIRED_PATHS = (
    "api/app/Domains/CredentialProviderFixture",
    "api/app/Domains/LegacyLinks",
    "api/app/Http/Middleware/SubscriptionIdentityShadow.php",
    "api/app/Http/Middleware/LegacySubscriptionLinkShadow.php",
    "api/app/Console/Commands/CoreCatalogShadow.php",
    "api/app/Console/Commands/CoreCommerceShadow.php",
    "api/app/Console/Commands/CoreLegacyLinksBackfill.php",
    "api/app/Console/Commands/CoreLegacyLinksReconcile.php",
    "api/app/Console/Commands/CoreLegacyMoneyShadow.php",
    "api/app/Console/Commands/CoreLifecycleShadowReconcile.php",
    "api/app/Console/Commands/CoreSubscriptionsBackfill.php",
    "api/app/Console/Commands/CoreSubscriptionsReconcile.php",
    "api/config/legacy_subscription_links.php",
    "api/config/credential_provider_fixture.php",
    "api/config/credential_provider_lease_fixture.php",
    "api/config/credential_provider_authorization_fixture.php",
    "api/app/Domains/AuthorityMigration/AuthorityIdentitySqlPhase.php",
    "api/app/Console/Commands/CoreAdminCreationShadowVerify.php",
    "api/app/Console/Commands/CoreAdminDeletionShadowVerify.php",
    "api/app/Console/Commands/CoreBootstrapCreationShadowVerify.php",
    "api/app/Console/Commands/CoreRegistrationCreationShadowVerify.php",
    "api/app/Console/Commands/CoreLegacyCaptureShadow.php",
    "api/app/Services/Subscription",
    "api/tests/Feature/Subscription/SubscriptionTermsShadowTest.php",
    "api/tests/Feature/UsageV2/UsageTermsBoundaryTest.php",
)
RETIRED_CONFIGS = (
    "legacy_capture_shadow",
    "legacy_observer_capture",
    "legacy_registration_creation_shadow",
    "legacy_bootstrap_creation_shadow",
    "legacy_admin_creation_shadow",
    "legacy_admin_deletion_shadow",
    "legacy_standalone_reset_capture",
    "legacy_plan_propagation_capture",
    "legacy_bulk_ban_capture",
    "legacy_money_shadow",
    "legacy_lifecycle_reconcile",
)


def require(condition, message):
    if not condition:
        raise ValueError(message)


def validate():
    namespace = "App\\Domains\\Subscription\\Services"
    for name in SUBSCRIPTION_SERVICES:
        path = ROOT / "api/app/Domains/Subscription/Services" / f"{name}.php"
        require(path.is_file(), f"Missing subscription domain service: {name}")
        require(f"namespace {namespace};" in path.read_text(encoding="utf-8"),
                f"Wrong subscription namespace: {name}")
        require(not (ROOT / "api/app/Services/Subscription" / f"{name}.php").exists(),
                f"Obsolete subscription forwarding entry: {name}")
    for relative in (*RETIRED_PATHS, *(f"api/config/{name}.php" for name in RETIRED_CONFIGS)):
        # Git does not preserve empty directories.
        path = ROOT / relative
        require(not path.exists() or (path.is_dir() and not any(path.rglob("*"))),
                f"Retired runtime path restored: {relative}")
    retired = re.compile(r"\b(?:" + "|".join(RETIRED_CLASSES) + r")\b")
    old_namespace = re.compile(r"App\\Services\\Subscription\\")
    retired_namespace = re.compile(r"App\\Domains\\(?:CredentialProviderFixture|LegacyLinks)\\")
    retired_config = re.compile(
        r"""['"](?:""" + "|".join(RETIRED_CONFIGS) + r""")(?:\.|['"])"""
    )
    files = []
    for area in ("api/app", "api/config", "api/routes", "api/database", "api/tests",
                 "api/plugins-core", "scripts/tests", "gateway/tests"):
        files.extend((ROOT / area).rglob("*.php"))
    for path in files:
        text = path.read_text(encoding="utf-8-sig")
        for pattern in (retired, old_namespace, retired_namespace, retired_config):
            match = pattern.search(text)
            require(match is None,
                    f"Obsolete runtime reference in {path.relative_to(ROOT)}: "
                    f"{match.group(0) if match else ''}")
    require((ROOT / "api/app/Models/SubscriptionRecord.php").is_file(),
            "Missing canonical subscription record model")
    require((ROOT / "api/app/Domains/Installation/SchemaReplayValidator.php").is_file(),
            "Missing shared installation schema validator")
    for name in ("AccountSqlPhase", "AccountCreationService", "AdminAccountModeration"):
        path = ROOT / f"api/app/Domains/Identity/{name}.php"
        require(path.is_file() and "namespace App\\Domains\\Identity;" in path.read_text(encoding="utf-8"),
                f"Missing identity domain owner: {name}")
    return len(files)


if __name__ == "__main__":
    try:
        count = validate()
        print(f"Core boundaries: {len(SUBSCRIPTION_SERVICES)} domain services; "
              f"{count} PHP source files checked; retired runtime references absent.")
    except ValueError as error:
        print(f"Core boundary check failed: {error}", file=sys.stderr)
        sys.exit(1)
