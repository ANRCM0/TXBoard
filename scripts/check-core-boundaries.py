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
    "NativePlanAdministration",
    "NativeSubscriptionCalendar",
    "NativeSubscriptionDelivery",
    "NativeSubscriptionLinks",
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
    "FencedSanctumGuard",
    "InternalReadOnlyController",
    "InternalServiceProof",
    "UsageRegistry",
    "NodeUserSyncJob",
    "ProtocolManager",
    "ProtocolServiceProvider",
    "SubscribeTemplate",
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
    "api/app/Domains/AuthorityMigration",
    "api/app/Console/Commands/CoreAuthorityFence.php",
    "api/app/Console/Commands/CoreAuthorityPostcommit.php",
    "api/tests/Feature/AuthorityMigration",
    "api/tests/Support/AuthorityIdentityFixture.php",
    "api/tests/Support/AuthorityTicketFixture.php",
    "api/tests/Feature/Identity/AccountPostcommitBoundaryTest.php",
    "api/app/Services/TicketService.php",
    "api/app/Domains/InternalReadOnly",
    "api/app/Http/Controllers/Txapi/InternalReadOnlyController.php",
    "api/app/Http/Middleware/InternalServiceProof.php",
    "api/tests/Feature/InternalReadOnly",
    "api/internal-readonly-fixtures",
    "gateway/apps/gateway/src/adapters/internal-user-v2.ts",
    "gateway/apps/gateway/src/adapters/internal-admin-v1.ts",
    "gateway/apps/gateway/src/services/internal-user-client.ts",
    "gateway/apps/gateway/src/services/internal-admin-client.ts",
    "gateway/apps/gateway/src/config/internal-user.ts",
    "gateway/apps/gateway/src/config/internal-admin.ts",
    "api/app/Console/Commands/CoreUsageV2.php",
    "api/app/Console/Commands/PanelSubscribePath.php",
    "api/app/Http/Controllers/SubscriptionController.php",
    "api/app/Http/Controllers/Txapi/ServerController.php",
    "api/app/Http/Middleware/Client.php",
    "api/app/Services/Theme/ThemePackage.php",
    "api/app/Services/Theme/ThemePackageManifest.php",
    "node/internal/txboard/usage_v2.go",
    "node/internal/service/usage_v2_adapter.go",
    "api/phpunit-historical.xml",
    "api/app/Domains/Billing/NativeEpayAdapter.php",
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
    "authority_writer_fence",
    "core_shadow",
    "internal_api_slice",
)
KERNEL_FILES = {
    "api/app/Core/Kernel/Clock.php": "App\\Core\\Kernel",
    "api/app/Core/Infrastructure/SystemClock.php": "App\\Core\\Infrastructure",
    "api/app/Domains/Subscription/Contracts/EntitlementQueries.php":
        "App\\Domains\\Subscription\\Contracts",
    "api/app/Domains/Subscription/Contracts/EntitlementQuery.php":
        "App\\Domains\\Subscription\\Contracts",
    "api/app/Domains/Subscription/Contracts/EntitlementView.php":
        "App\\Domains\\Subscription\\Contracts",
    "api/app/Domains/Subscription/Internal/LockedEntitlementQueries.php":
        "App\\Domains\\Subscription\\Internal",
    "api/app/Providers/CoreCapabilitiesServiceProvider.php": "App\\Providers",
}
RESOLVER_OWNERS = frozenset({
    "api/app/Domains/Subscription/Services/SubscriptionEntitlementResolver.php",
    "api/app/Providers/CoreCapabilitiesServiceProvider.php",
})
PHP_COMMENTS = re.compile(
    r"""'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|//[^\r\n]*|/\*[\s\S]*?\*/|\#(?!\[)[^\r\n]*"""
)
HOST_NAMESPACE = re.compile(r"\b(?:Illuminate|Symfony|GuzzleHttp|Plugin)\\", re.IGNORECASE)
HOST_OPERATION = re.compile(
    r"\b(?:DB|Cache|Redis|Http|PDO|mysqli|SoapClient)\b"
    r"|\b(?:app|resolve|config|env|request|auth|curl_[a-z_]+|file_get_contents|"
    r"file_put_contents|fopen|fsockopen|stream_socket_client|shell_exec|exec|"
    r"system|passthru|proc_open|popen)\s*\(",
    re.IGNORECASE,
)


def require(condition, message):
    if not condition:
        raise ValueError(message)


def without_php_comments(text):
    # Preserve quoted class names: string-based container lookups are dependencies too.
    return PHP_COMMENTS.sub(
        lambda match: " " * len(match.group(0))
        if match.group(0).startswith(("//", "/*", "#")) else match.group(0),
        text,
    )


def validate_kernel_boundaries(root):
    """Small source guard, not a PHP parser, sandbox or proof of transaction safety."""
    for relative, namespace in KERNEL_FILES.items():
        path = root / relative
        require(path.is_file(), f"Missing Core capability file: {relative}")
        source = without_php_comments(path.read_text(encoding="utf-8-sig"))
        require(re.search(r"\bnamespace\s+" + re.escape(namespace) + r"\s*;", source) is not None,
                f"Wrong Core capability namespace: {relative}")

    areas = {
        "api/app/Core/Kernel": (
            re.compile(r"\bApp\\(?!Core\\Kernel(?:\\|\b))", re.IGNORECASE),
            HOST_NAMESPACE,
            HOST_OPERATION,
        ),
        "api/app/Domains/Subscription/Contracts": (
            re.compile(
                r"\bApp\\(?!(?:Core\\Kernel|Domains\\Subscription\\Contracts)(?:\\|\b))",
                re.IGNORECASE,
            ),
            HOST_NAMESPACE,
            HOST_OPERATION,
            re.compile(r"\b(?:Closure|callable|Request|Model|Builder|Connection|ConnectionInterface)\b",
                       re.IGNORECASE),
        ),
    }
    checked = 0
    for area, patterns in areas.items():
        for path in sorted((root / area).rglob("*.php")):
            source = without_php_comments(path.read_text(encoding="utf-8-sig"))
            for pattern in patterns:
                match = pattern.search(source)
                require(match is None,
                        f"Host or implementation dependency in {path.relative_to(root).as_posix()}: "
                        f"{match.group(0) if match else ''}")
            checked += 1

    concrete = re.compile(r"\bSubscriptionEntitlementResolver\b", re.IGNORECASE)
    for area in ("api/app", "api/config", "api/routes", "api/plugins-core"):
        for path in sorted((root / area).rglob("*.php")):
            relative = path.relative_to(root).as_posix()
            if relative in RESOLVER_OWNERS:
                continue
            source = without_php_comments(path.read_text(encoding="utf-8-sig"))
            require(concrete.search(source) is None,
                    f"Concrete entitlement dependency outside owner/composition root: {relative}")
    return checked


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
    retired_namespace = re.compile(r"App\\Domains\\(?:CredentialProviderFixture|LegacyLinks|AuthorityMigration|InternalReadOnly)\\")
    retired_ticket = re.compile(r"App\\Services\\TicketService\b")
    retired_config = re.compile(
        r"""['"](?:""" + "|".join(RETIRED_CONFIGS) + r""")(?:\.|['"])"""
    )
    files = []
    for area in ("api/app", "api/config", "api/routes", "api/database", "api/tests",
                 "api/plugins-core", "scripts/tests", "gateway/tests"):
        files.extend((ROOT / area).rglob("*.php"))
    for path in files:
        text = path.read_text(encoding="utf-8-sig")
        for pattern in (retired, old_namespace, retired_namespace, retired_ticket, retired_config):
            match = pattern.search(text)
            require(match is None,
                    f"Obsolete runtime reference in {path.relative_to(ROOT)}: "
                    f"{match.group(0) if match else ''}")
    require((ROOT / "api/app/Models/SubscriptionRecord.php").is_file(),
            "Missing canonical subscription record model")
    require((ROOT / "api/app/Domains/Installation/SchemaReplayValidator.php").is_file(),
            "Missing shared installation schema validator")
    billing = ROOT / "api/app/Domains/Billing"
    for path in billing.rglob("*.php"):
        text = path.read_text(encoding="utf-8-sig")
        require(not re.search(r"\bNativeEpayAdapter\b|Plugin\\Epay\\", text),
                f"Core financial domain depends on a concrete payment protocol: {path.relative_to(ROOT)}")
    require((ROOT / "api/app/Contracts/NativePaymentProviderInterface.php").is_file(),
            "Missing native payment plugin contract")
    require((ROOT / "api/app/Domains/Billing/NativePaymentProviderRegistry.php").is_file(),
            "Missing Core-owned payment capability registry")
    for name in ("AccountSqlPhase", "AccountCreationService", "AdminAccountModeration", "AccountSessionGuard"):
        path = ROOT / f"api/app/Domains/Identity/{name}.php"
        require(path.is_file() and "namespace App\\Domains\\Identity;" in path.read_text(encoding="utf-8"),
                f"Missing identity domain owner: {name}")
    ticket = ROOT / "api/app/Domains/Support/TicketService.php"
    require(ticket.is_file() and "namespace App\\Domains\\Support;" in ticket.read_text(encoding="utf-8"),
            "Missing support domain ticket service")
    validate_kernel_boundaries(ROOT)
    return len(files)


if __name__ == "__main__":
    try:
        count = validate()
        print(f"Core boundaries: {len(SUBSCRIPTION_SERVICES)} domain services; "
              f"{count} PHP source files checked; retired runtime references absent; "
              "Kernel, public entitlement contracts and concrete consumer boundaries checked.")
    except ValueError as error:
        print(f"Core boundary check failed: {error}", file=sys.stderr)
        sys.exit(1)
