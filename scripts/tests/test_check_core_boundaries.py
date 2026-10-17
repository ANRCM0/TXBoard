"""Synthetic static-guard fixtures; no PHP application or database is started."""

import importlib.util
from pathlib import Path
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parents[1] / "check-core-boundaries.py"
SPEC = importlib.util.spec_from_file_location("check_core_boundaries", SCRIPT)
GUARD = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(GUARD)


class CoreCapabilityBoundaryTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        for relative, namespace in GUARD.KERNEL_FILES.items():
            self.write(relative, f"<?php\nnamespace {namespace};\n")
        self.write(
            "api/app/Core/Kernel/Clock.php",
            "<?php\nnamespace App\\Core\\Kernel;\ninterface Clock { public function now(): int; }\n",
        )
        self.write(
            "api/app/Domains/Subscription/Contracts/EntitlementQueries.php",
            "<?php\nnamespace App\\Domains\\Subscription\\Contracts;\n"
            "interface EntitlementQueries {\n"
            "    public function forAccount(EntitlementQuery $query): EntitlementView;\n}\n",
        )

    def write(self, relative, content):
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content, encoding="utf-8")

    def assert_violation(self, relative, content, message):
        self.write(relative, content)
        with self.assertRaisesRegex(ValueError, message):
            GUARD.validate_kernel_boundaries(self.root)

    def test_pure_contracts_allow_arrays_and_standard_php_types(self):
        self.write(
            "api/app/Domains/Subscription/Contracts/EntitlementView.php",
            "<?php\nnamespace App\\Domains\\Subscription\\Contracts;\n"
            "use JsonSerializable;\nfinal readonly class EntitlementView implements JsonSerializable {\n"
            "    public function __construct(private array $value) {}\n"
            "    public function jsonSerialize(): array { return $this->value; }\n}\n",
        )
        self.assertEqual(5, GUARD.validate_kernel_boundaries(self.root))

    def test_missing_contract_fails_instead_of_skipping_the_entire_area(self):
        (self.root / "api/app/Core/Kernel/Clock.php").unlink()
        with self.assertRaisesRegex(ValueError, "Missing Core capability file"):
            GUARD.validate_kernel_boundaries(self.root)

    def test_namespace_must_match_the_declared_boundary(self):
        self.assert_violation(
            "api/app/Core/Kernel/Clock.php",
            "<?php\nnamespace App\\Core\\Infrastructure;\ninterface Clock {}\n",
            "Wrong Core capability namespace",
        )

    def test_kernel_rejects_framework_domain_and_host_dependencies(self):
        for expression in (
            "use Illuminate\\Support\\Facades\\DB;",
            "use App\\Domains\\Billing\\MoneyValue;",
            "use App\\Core\\Infrastructure\\SystemClock;",
            "$client = new \\PDO('dsn');",
            "$value = \\curl_init();",
            "$value = app('clock');",
            "$value = \\file_get_contents('/tmp/file');",
        ):
            with self.subTest(expression=expression):
                self.assert_violation(
                    "api/app/Core/Kernel/Forbidden.php",
                    "<?php\nnamespace App\\Core\\Kernel;\n" + expression,
                    "Host or implementation dependency",
                )

    def test_public_contract_rejects_framework_models_connections_and_callables(self):
        for expression in (
            "use Illuminate\\Http\\Request;",
            "use App\\Models\\User;",
            "use App\\Domains\\Subscription\\Services\\NativeSubscriptionStore;",
            "use App\\Domains\\Subscription\\Internal\\LockedEntitlementQueries;",
            "public \\Closure $callback;",
            "public function run(callable $callback): void;",
            "public ConnectionInterface $connection;",
            "public function query(): \\Illuminate\\Database\\Query\\Builder;",
        ):
            with self.subTest(expression=expression):
                self.assert_violation(
                    "api/app/Domains/Subscription/Contracts/Forbidden.php",
                    "<?php\nnamespace App\\Domains\\Subscription\\Contracts;\n" + expression,
                    "Host or implementation dependency",
                )

    def test_concrete_resolver_is_limited_to_exact_owner_and_composition_paths(self):
        for relative in GUARD.RESOLVER_OWNERS:
            namespace = GUARD.KERNEL_FILES.get(relative, "App\\Domains\\Subscription\\Services")
            self.write(relative, f"<?php\nnamespace {namespace};\n"
                       "use App\\Domains\\Subscription\\Services\\SubscriptionEntitlementResolver;\n")
        self.assertEqual(5, GUARD.validate_kernel_boundaries(self.root))
        self.assert_violation(
            "api/app/Providers/AnotherServiceProvider.php",
            "<?php\nuse App\\Domains\\Subscription\\Services\\SubscriptionEntitlementResolver;\n",
            "Concrete entitlement dependency",
        )

    def test_concrete_fully_qualified_and_string_references_are_rejected(self):
        for expression in (
            "$value = app(\\App\\Domains\\Subscription\\Services\\SubscriptionEntitlementResolver::class);",
            "$value = app('App\\\\Domains\\\\Subscription\\\\Services\\\\SubscriptionEntitlementResolver');",
        ):
            with self.subTest(expression=expression):
                self.assert_violation(
                    "api/app/Http/Controllers/Caller.php",
                    "<?php\n" + expression,
                    "Concrete entitlement dependency",
                )

    def test_config_routes_and_plugin_sources_cannot_bypass_the_port(self):
        for relative in ("api/config/forbidden.php", "api/routes/forbidden.php",
                         "api/plugins-core/Example/Plugin.php"):
            with self.subTest(relative=relative):
                self.assert_violation(
                    relative,
                    "<?php\nuse App\\Domains\\Subscription\\Services\\SubscriptionEntitlementResolver;\n",
                    "Concrete entitlement dependency",
                )
                (self.root / relative).unlink()

    def test_comments_are_not_dependencies_but_quoted_class_names_are(self):
        self.write(
            "api/app/Core/Kernel/Notes.php",
            "<?php\nnamespace App\\Core\\Kernel;\n"
            "// Never use Illuminate\\Http\\Request or app() here.\n"
            "/* App\\Domains\\Billing\\MoneyValue is domain owned. */\n"
            "# No PDO or curl_init() in this contract.\n",
        )
        self.write(
            "api/app/Http/Controllers/Caller.php",
            "<?php\n// SubscriptionEntitlementResolver is not this consumer's dependency.\n",
        )
        self.assertEqual(6, GUARD.validate_kernel_boundaries(self.root))
        self.assert_violation(
            "api/app/Core/Kernel/Notes.php",
            "<?php\nnamespace App\\Core\\Kernel;\n$value = 'Illuminate\\\\Http\\\\Request';\n",
            "Host or implementation dependency",
        )

    def test_host_code_and_locked_arrays_are_not_subject_to_public_dto_rules(self):
        self.write(
            "api/app/Domains/Subscription/Internal/LockedEntitlementQueries.php",
            "<?php\nnamespace App\\Domains\\Subscription\\Internal;\n"
            "interface LockedEntitlementQueries {\n"
            "    public function resolveLocked(int $userId, int $at): array;\n}\n",
        )
        self.write(
            "api/app/Http/Controllers/Caller.php",
            "<?php\nuse Illuminate\\Http\\Request;\n"
            "use App\\Domains\\Subscription\\Contracts\\EntitlementQueries;\n"
            "$queries = app(EntitlementQueries::class);\n",
        )
        self.write(
            "api/tests/Feature/ExistingResolverTest.php",
            "<?php\nuse App\\Domains\\Subscription\\Services\\SubscriptionEntitlementResolver;\n",
        )
        self.assertEqual(5, GUARD.validate_kernel_boundaries(self.root))

    def test_provider_contract_allows_sensitive_parameters_and_owned_contracts(self):
        self.write(
            "api/app/Domains/CredentialProvider/Contracts/ProviderClient.php",
            "<?php\nnamespace App\\Domains\\CredentialProvider\\Contracts;\n"
            "use App\\Domains\\CredentialProvider\\Contracts\\ProviderClient as Client;\n"
            "interface ProviderClient {\n"
            "    public function call(#[\\SensitiveParameter] array $registration, "
            "#[\\SensitiveParameter] array $request): array;\n}\n",
        )
        self.assertEqual(5, GUARD.validate_kernel_boundaries(self.root))

    def test_missing_provider_contract_is_rejected(self):
        (self.root / "api/app/Domains/CredentialProvider/Contracts/ProviderClient.php").unlink()
        with self.assertRaisesRegex(ValueError, "Missing Core capability file"):
            GUARD.validate_kernel_boundaries(self.root)

    def test_provider_contract_rejects_implementation_framework_and_network_dependencies(self):
        for expression in (
            "use App\\Domains\\CredentialProvider\\ProviderTransport;",
            "use App\\Domains\\CredentialProvider\\ProviderCodec;",
            "use App\\Models\\User;",
            "use Illuminate\\Http\\Client\\Response;",
            "public function call(): \\GuzzleHttp\\Client;",
            "public function call(callable $callback): array;",
            "$value = \\stream_socket_client('tcp://example');",
            "$value = app('client');",
        ):
            with self.subTest(expression=expression):
                self.assert_violation(
                    "api/app/Domains/CredentialProvider/Contracts/Forbidden.php",
                    "<?php\nnamespace App\\Domains\\CredentialProvider\\Contracts;\n" + expression,
                    "Host or implementation dependency",
                )

    def test_concrete_provider_transport_is_limited_to_exact_owner_and_composition_paths(self):
        for relative in GUARD.PROVIDER_TRANSPORT_OWNERS:
            namespace = GUARD.KERNEL_FILES.get(relative, "App\\Domains\\CredentialProvider")
            self.write(relative, f"<?php\nnamespace {namespace};\n"
                       "use App\\Domains\\CredentialProvider\\ProviderTransport;\n")
        self.assertEqual(5, GUARD.validate_kernel_boundaries(self.root))
        self.assert_violation(
            "api/app/Domains/CredentialProvider/AnotherTransport.php",
            "<?php\nuse App\\Domains\\CredentialProvider\\ProviderTransport;\n",
            "Concrete provider transport dependency",
        )

    def test_provider_transport_import_fully_qualified_and_string_consumers_are_rejected(self):
        for relative in (
            "api/app/Domains/Network/Consumer.php", "api/config/forbidden.php",
            "api/routes/forbidden.php", "api/plugins-core/Example/Plugin.php",
        ):
            for expression in (
                "use App\\Domains\\CredentialProvider\\ProviderTransport as Client;",
                "$client = new \\App\\Domains\\CredentialProvider\\ProviderTransport();",
                "$client = app('App\\\\Domains\\\\CredentialProvider\\\\ProviderTransport');",
            ):
                with self.subTest(relative=relative, expression=expression):
                    self.assert_violation(relative, "<?php\n" + expression,
                                          "Concrete provider transport dependency")
                    (self.root / relative).unlink()

    def test_provider_contract_consumers_and_transport_tests_remain_allowed(self):
        self.write(
            "api/app/Domains/Network/Consumer.php",
            "<?php\nuse App\\Domains\\CredentialProvider\\Contracts\\ProviderClient;\n"
            "// ProviderTransport is only the host implementation.\n",
        )
        self.write(
            "api/tests/Feature/ProviderTransportTest.php",
            "<?php\nuse App\\Domains\\CredentialProvider\\ProviderTransport;\n",
        )
        self.assertEqual(5, GUARD.validate_kernel_boundaries(self.root))


if __name__ == "__main__":
    unittest.main()
