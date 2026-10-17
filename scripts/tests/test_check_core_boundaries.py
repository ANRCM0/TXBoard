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
        self.assertEqual(16, GUARD.validate_kernel_boundaries(self.root))

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
        self.assertEqual(16, GUARD.validate_kernel_boundaries(self.root))
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
        self.assertEqual(17, GUARD.validate_kernel_boundaries(self.root))
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
        self.assertEqual(16, GUARD.validate_kernel_boundaries(self.root))

    def test_provider_contract_allows_sensitive_parameters_and_owned_contracts(self):
        self.write(
            "api/app/Domains/CredentialProvider/Contracts/ProviderClient.php",
            "<?php\nnamespace App\\Domains\\CredentialProvider\\Contracts;\n"
            "use App\\Domains\\CredentialProvider\\Contracts\\ProviderClient as Client;\n"
            "interface ProviderClient {\n"
            "    public function call(#[\\SensitiveParameter] array $registration, "
            "#[\\SensitiveParameter] array $request): array;\n}\n",
        )
        self.assertEqual(16, GUARD.validate_kernel_boundaries(self.root))

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
        self.assertEqual(16, GUARD.validate_kernel_boundaries(self.root))
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
        self.assertEqual(16, GUARD.validate_kernel_boundaries(self.root))

    def test_public_order_fulfillment_accepts_scalar_input_and_array_result(self):
        self.write(
            "api/app/Domains/Subscription/Contracts/OrderFulfillment.php",
            "<?php\nnamespace App\\Domains\\Subscription\\Contracts;\n"
            "interface OrderFulfillment {\n"
            "    public function fulfill(int $orderId, ?int $at = null): array;\n}\n",
        )
        self.assertEqual(16, GUARD.validate_kernel_boundaries(self.root))

    def test_each_transaction_port_is_required_with_its_exact_namespace(self):
        for relative in (
            "api/app/Domains/Subscription/Contracts/OrderFulfillment.php",
            "api/app/Domains/Subscription/Internal/LockedOrderFulfillment.php",
            "api/app/Domains/Billing/Internal/WinningPaymentProofs.php",
        ):
            with self.subTest(relative=relative):
                path = self.root / relative
                original = path.read_text(encoding="utf-8")
                path.unlink()
                with self.assertRaisesRegex(ValueError, "Missing Core capability file"):
                    GUARD.validate_kernel_boundaries(self.root)
                self.write(relative, "<?php\nnamespace App\\Wrong;\n")
                with self.assertRaisesRegex(ValueError, "Wrong Core capability namespace"):
                    GUARD.validate_kernel_boundaries(self.root)
                self.write(relative, original)

    def test_locked_participants_allow_owned_models_and_eloquent_collections(self):
        for relative, namespace, name in (
            ("api/app/Domains/Subscription/Internal/LockedOrderFulfillment.php",
             "App\\Domains\\Subscription\\Internal", "LockedOrderFulfillment"),
            ("api/app/Domains/Billing/Internal/WinningPaymentProofs.php",
             "App\\Domains\\Billing\\Internal", "WinningPaymentProofs"),
        ):
            self.write(
                relative,
                f"<?php\nnamespace {namespace};\n"
                "use App\\Models\\Order;\nuse App\\Models\\Purchase;\n"
                "use Illuminate\\Database\\Eloquent\\Collection;\n"
                f"interface {name} {{\n"
                "    public function inspect(Order $order, Purchase $purchase, Collection $rows): array;\n}\n",
            )
        self.assertEqual(16, GUARD.validate_kernel_boundaries(self.root))

    def test_locked_participants_reject_direct_host_operations_and_facades(self):
        for area, namespace in (
            ("api/app/Domains/Subscription/Internal", "App\\Domains\\Subscription\\Internal"),
            ("api/app/Domains/Billing/Internal", "App\\Domains\\Billing\\Internal"),
        ):
            relative = area + "/Forbidden.php"
            for expression in (
                "use Illuminate\\Support\\Facades\\DB as Database;",
                "use Illuminate\\Support\\Facades\\Http;",
                "use Illuminate\\Http\\Client\\Factory;",
                "use GuzzleHttp\\Client;",
                "$value = DB::transaction(fn () => []);",
                "$value = app('db');",
                "$value = \\curl_init();",
                "$value = \\file_get_contents('https://example.invalid');",
            ):
                with self.subTest(area=area, expression=expression):
                    self.assert_violation(relative, f"<?php\nnamespace {namespace};\n" + expression,
                                          "Host operation in locked participant contract")
                    (self.root / relative).unlink()

    def test_fulfillment_implementation_is_limited_to_exact_owner_and_composition(self):
        for relative in GUARD.FULFILLMENT_OWNERS:
            namespace = GUARD.KERNEL_FILES.get(relative, "App\\Domains\\Subscription\\Services")
            self.write(relative, f"<?php\nnamespace {namespace};\n"
                       "use App\\Domains\\Subscription\\Services\\SubscriptionFulfillmentService;\n")
        self.assertEqual(16, GUARD.validate_kernel_boundaries(self.root))
        for relative in (
            "api/app/Domains/Billing/AnotherService.php", "api/app/Providers/AnotherServiceProvider.php",
            "api/config/forbidden.php", "api/routes/forbidden.php",
            "api/plugins-core/Example/Plugin.php",
        ):
            for expression in (
                "use App\\Domains\\Subscription\\Services\\SubscriptionFulfillmentService as Fulfillment;",
                "$service = new \\App\\Domains\\Subscription\\Services\\SubscriptionFulfillmentService();",
                "$service = app('App\\\\Domains\\\\Subscription\\\\Services\\\\SubscriptionFulfillmentService');",
            ):
                with self.subTest(relative=relative, expression=expression):
                    self.assert_violation(relative, "<?php\n" + expression,
                                          "Concrete order fulfillment dependency")
                    (self.root / relative).unlink()

    def test_identity_contracts_allow_immutable_scalar_identity_and_array_results(self):
        self.write(
            "api/app/Domains/Identity/Contracts/AccountIdentity.php",
            "<?php\nnamespace App\\Domains\\Identity\\Contracts;\n"
            "use InvalidArgumentException;\nfinal readonly class AccountIdentity {\n"
            "    public function __construct(public int $userId, public string $email, "
            "public ?int $telegramId) {\n"
            "        if ($userId <= 0) { throw new InvalidArgumentException('INVALID_ID'); }\n"
            "    }\n}\n",
        )
        self.write(
            "api/app/Domains/Identity/Contracts/AccountQueries.php",
            "<?php\nnamespace App\\Domains\\Identity\\Contracts;\n"
            "interface AccountQueries {\n"
            "    public function account(AccountIdentity $identity): array;\n"
            "    public function subscription(int $userId): array;\n}\n",
        )
        self.assertEqual(16, GUARD.validate_kernel_boundaries(self.root))

    def test_identity_contracts_and_fixed_read_handler_are_required_with_exact_namespaces(self):
        for relative in (
            "api/app/Domains/Identity/Contracts/AccountIdentity.php",
            "api/app/Domains/Identity/Contracts/AccountQueries.php",
            "api/app/Domains/InternalApi/AccountReadOperations.php",
        ):
            with self.subTest(relative=relative):
                path = self.root / relative
                original = path.read_text(encoding="utf-8")
                path.unlink()
                with self.assertRaisesRegex(ValueError, "Missing Core capability file"):
                    GUARD.validate_kernel_boundaries(self.root)
                self.write(relative, "<?php\nnamespace App\\Wrong;\n")
                with self.assertRaisesRegex(ValueError, "Wrong Core capability namespace"):
                    GUARD.validate_kernel_boundaries(self.root)
                self.write(relative, original)

    def test_identity_contracts_reject_models_framework_callbacks_and_implementations(self):
        for expression in (
            "use App\\Models\\User;", "use Illuminate\\Http\\Request;",
            "use App\\Domains\\Identity\\NativeAccountQueries;",
            "use App\\Domains\\Subscription\\Contracts\\EntitlementQueries;",
            "public function account(callable $actor): array;",
            "public function account(): \\Illuminate\\Database\\ConnectionInterface;",
            "$value = app('account');", "$value = auth()->user();",
        ):
            with self.subTest(expression=expression):
                self.assert_violation(
                    "api/app/Domains/Identity/Contracts/Forbidden.php",
                    "<?php\nnamespace App\\Domains\\Identity\\Contracts;\n" + expression,
                    "Host or implementation dependency",
                )

    def test_account_query_implementation_is_limited_to_exact_owner_and_composition(self):
        for relative in GUARD.ACCOUNT_QUERY_OWNERS:
            namespace = GUARD.KERNEL_FILES.get(relative, "App\\Domains\\Identity")
            self.write(relative, f"<?php\nnamespace {namespace};\n"
                       "use App\\Domains\\Identity\\NativeAccountQueries;\n")
        self.assertEqual(16, GUARD.validate_kernel_boundaries(self.root))
        for relative in (
            "api/app/Domains/Identity/AnotherQuery.php",
            "api/app/Http/Controllers/AccountController.php",
            "api/app/Domains/InternalApi/AnotherHandler.php",
            "api/app/Providers/AnotherServiceProvider.php",
            "api/config/forbidden.php", "api/routes/forbidden.php",
            "api/plugins-core/Example/Plugin.php",
        ):
            for expression in (
                "use App\\Domains\\Identity\\NativeAccountQueries as Queries;",
                "$queries = new \\App\\Domains\\Identity\\NativeAccountQueries();",
                "$queries = app('App\\\\Domains\\\\Identity\\\\NativeAccountQueries');",
            ):
                with self.subTest(relative=relative, expression=expression):
                    self.assert_violation(relative, "<?php\n" + expression,
                                          "Concrete account query dependency")
                    (self.root / relative).unlink()

    def test_fixed_read_handler_allows_actor_projection_and_query_port(self):
        self.write(
            "api/app/Domains/InternalApi/AccountReadOperations.php",
            "<?php\nnamespace App\\Domains\\InternalApi;\n"
            "use App\\Models\\User;\n"
            "use App\\Domains\\Identity\\Contracts\\AccountIdentity;\n"
            "use App\\Domains\\Identity\\Contracts\\AccountQueries;\n"
            "final class AccountReadOperations {\n"
            "    public function __construct(private readonly AccountQueries $queries) {}\n"
            "    public function account(User $actor): array {\n"
            "        return $this->queries->account(new AccountIdentity(\n"
            "            (int) $actor->id, (string) $actor->email, null));\n"
            "    }\n}\n",
        )
        self.write(
            "api/tests/Feature/Identity/AccountQueryTest.php",
            "<?php\nuse App\\Domains\\Identity\\NativeAccountQueries;\n",
        )
        self.assertEqual(16, GUARD.validate_kernel_boundaries(self.root))

    def test_fixed_read_handler_rejects_http_auth_sql_and_host_dependencies(self):
        for expression in (
            "use App\\Http\\Controllers\\Txapi\\AccountController;",
            "use Illuminate\\Http\\Request as Input;",
            "use Illuminate\\Support\\Facades\\Auth as Actor;",
            "use Illuminate\\Support\\Facades\\DB as Database;",
            "$actor = auth()->user();", "$actor = \\request()->user();",
            "$actor = Auth::user();", "$request = Request::create('/');",
            "$rows = DB::table('users')->get();",
            "$value = app('queries');", "$value = \\curl_init();",
        ):
            with self.subTest(expression=expression):
                self.assert_violation(
                    "api/app/Domains/InternalApi/AccountReadOperations.php",
                    "<?php\nnamespace App\\Domains\\InternalApi;\n" + expression,
                    "Host or HTTP dependency in account read handler",
                )

    def test_account_read_handler_guard_does_not_restrict_unmigrated_internal_services(self):
        self.write(
            "api/app/Domains/InternalApi/OperationExecutor.php",
            "<?php\nnamespace App\\Domains\\InternalApi;\n"
            "use Illuminate\\Http\\Request;\nuse Illuminate\\Support\\Facades\\DB;\n"
            "use App\\Http\\Controllers\\Txapi\\AccountController;\n",
        )
        self.assertEqual(16, GUARD.validate_kernel_boundaries(self.root))

    def test_order_contracts_allow_owned_dtos_and_scalar_page_data(self):
        self.write(
            "api/app/Domains/Billing/Contracts/OrderQueries.php",
            "<?php\nnamespace App\\Domains\\Billing\\Contracts;\n"
            "interface OrderQueries {\n"
            "    public function listing(int $userId, OrderListQuery $query): OrderPage;\n"
            "    public function find(int $userId, string $tradeNo): ?array;\n}\n",
        )
        self.write(
            "api/app/Domains/Billing/Contracts/OrderListQuery.php",
            "<?php\nnamespace App\\Domains\\Billing\\Contracts;\n"
            "use InvalidArgumentException;\nfinal readonly class OrderListQuery {\n"
            "    public function __construct(public int $page = 1, public int $perPage = 20, "
            "public ?int $status = null) {}\n}\n",
        )
        self.write(
            "api/app/Domains/Billing/Contracts/OrderPage.php",
            "<?php\nnamespace App\\Domains\\Billing\\Contracts;\n"
            "final readonly class OrderPage {\n"
            "    public function __construct(public array $items, public array $meta) {}\n}\n",
        )
        self.assertEqual(16, GUARD.validate_kernel_boundaries(self.root))

    def test_order_contracts_and_read_adapters_are_required_with_exact_namespaces(self):
        for relative in (
            "api/app/Domains/Billing/Contracts/OrderQueries.php",
            "api/app/Domains/Billing/Contracts/OrderListQuery.php",
            "api/app/Domains/Billing/Contracts/OrderPage.php",
            "api/app/Domains/InternalApi/OrderReadOperations.php",
            "api/app/Domains/InternalApi/ReadOperationResult.php",
        ):
            with self.subTest(relative=relative):
                path = self.root / relative
                original = path.read_text(encoding="utf-8")
                path.unlink()
                with self.assertRaisesRegex(ValueError, "Missing Core capability file"):
                    GUARD.validate_kernel_boundaries(self.root)
                self.write(relative, "<?php\nnamespace App\\Wrong;\n")
                with self.assertRaisesRegex(ValueError, "Wrong Core capability namespace"):
                    GUARD.validate_kernel_boundaries(self.root)
                self.write(relative, original)

    def test_order_contracts_reject_implementation_models_framework_and_callbacks(self):
        for expression in (
            "use App\\Domains\\Billing\\NativeOrderQueries;",
            "use App\\Domains\\Billing\\Internal\\WinningPaymentProofs;",
            "use App\\Domains\\Identity\\Contracts\\AccountIdentity;",
            "use App\\Models\\Order;", "use Illuminate\\Support\\Collection;",
            "public function listing(callable $filter): array;",
            "public function find(): \\Illuminate\\Database\\ConnectionInterface;",
            "$orders = DB::table('orders')->get();", "$query = app('query');",
        ):
            with self.subTest(expression=expression):
                self.assert_violation(
                    "api/app/Domains/Billing/Contracts/Forbidden.php",
                    "<?php\nnamespace App\\Domains\\Billing\\Contracts;\n" + expression,
                    "Host or implementation dependency",
                )

    def test_order_query_implementation_is_limited_to_exact_owner_and_composition(self):
        for relative in GUARD.ORDER_QUERY_OWNERS:
            namespace = GUARD.KERNEL_FILES.get(relative, "App\\Domains\\Billing")
            self.write(relative, f"<?php\nnamespace {namespace};\n"
                       "use App\\Domains\\Billing\\NativeOrderQueries;\n")
        self.assertEqual(16, GUARD.validate_kernel_boundaries(self.root))
        for relative in (
            "api/app/Domains/Billing/AnotherQuery.php",
            "api/app/Http/Controllers/AccountController.php",
            "api/app/Providers/AnotherServiceProvider.php",
            "api/config/forbidden.php", "api/routes/forbidden.php",
            "api/plugins-core/Example/Plugin.php",
        ):
            for expression in (
                "use App\\Domains\\Billing\\NativeOrderQueries as Queries;",
                "$queries = new \\App\\Domains\\Billing\\NativeOrderQueries();",
                "$queries = app('App\\\\Domains\\\\Billing\\\\NativeOrderQueries');",
            ):
                with self.subTest(relative=relative, expression=expression):
                    self.assert_violation(relative, "<?php\n" + expression,
                                          "Concrete order query dependency")
                    (self.root / relative).unlink()

    def test_order_handler_allows_actor_query_port_and_adapter_local_result(self):
        self.write(
            "api/app/Domains/InternalApi/OrderReadOperations.php",
            "<?php\nnamespace App\\Domains\\InternalApi;\n"
            "use App\\Models\\User;\n"
            "use App\\Domains\\Billing\\Contracts\\OrderListQuery;\n"
            "use App\\Domains\\Billing\\Contracts\\OrderQueries;\n"
            "final class OrderReadOperations {\n"
            "    public function __construct(private readonly OrderQueries $queries) {}\n"
            "    public function listing(User $actor, array $input): ReadOperationResult {\n"
            "        $page = $this->queries->listing((int) $actor->id, new OrderListQuery());\n"
            "        return new ReadOperationResult($page->items, $page->meta);\n"
            "    }\n}\n",
        )
        self.write(
            "api/app/Domains/InternalApi/ReadOperationResult.php",
            "<?php\nnamespace App\\Domains\\InternalApi;\n"
            "final readonly class ReadOperationResult {\n"
            "    public function __construct(public array $data, public array $meta = []) {}\n}\n",
        )
        self.write(
            "api/tests/Feature/Billing/OrderQueryTest.php",
            "<?php\nuse App\\Domains\\Billing\\NativeOrderQueries;\n",
        )
        self.assertEqual(16, GUARD.validate_kernel_boundaries(self.root))

    def test_order_handler_and_result_reject_http_auth_sql_and_framework_dependencies(self):
        for relative, label in (
            ("api/app/Domains/InternalApi/OrderReadOperations.php", "order read handler"),
            ("api/app/Domains/InternalApi/ReadOperationResult.php", "read operation result"),
        ):
            for expression in (
                "use App\\Http\\Controllers\\Txapi\\AccountController;",
                "use Illuminate\\Http\\Request as Input;",
                "use Illuminate\\Support\\Facades\\DB as Database;",
                "use Illuminate\\Pagination\\LengthAwarePaginator;",
                "use Symfony\\Component\\HttpFoundation\\Response;",
                "$actor = Auth::user();", "$actor = auth()->user();",
                "$request = Request::create('/');", "$actor = request()->user();",
                "$orders = DB::table('orders')->get();", "$query = app('query');",
                "$rows = \\file_get_contents('https://example.invalid');",
            ):
                with self.subTest(relative=relative, expression=expression):
                    self.assert_violation(
                        relative, "<?php\nnamespace App\\Domains\\InternalApi;\n" + expression,
                        "Host or HTTP dependency in " + label,
                    )
            self.write(relative, "<?php\nnamespace App\\Domains\\InternalApi;\n")

    def test_session_capability_files_are_required_with_exact_namespaces(self):
        for relative in (
            "api/app/Domains/Identity/Contracts/AccountSessions.php",
            "api/app/Domains/Identity/NativeAccountSessions.php",
            "api/app/Domains/InternalApi/SessionReadOperations.php",
            "api/app/Domains/InternalApi/SessionMutationOperations.php",
            "api/app/Domains/InternalApi/MutationOperationResult.php",
        ):
            with self.subTest(relative=relative):
                original = (self.root / relative).read_text(encoding="utf-8")
                (self.root / relative).unlink()
                with self.assertRaisesRegex(ValueError, "Missing Core capability file"):
                    GUARD.validate_kernel_boundaries(self.root)
                self.assert_violation(relative, "<?php\nnamespace App\\Wrong;\n",
                                      "Wrong Core capability namespace")
                self.write(relative, original)

    def test_account_sessions_contract_is_pure_scalar_and_array_data(self):
        relative = "api/app/Domains/Identity/Contracts/AccountSessions.php"
        prefix = "<?php\nnamespace App\\Domains\\Identity\\Contracts;\n"
        self.write(relative, prefix + "interface AccountSessions {\n"
                   "public function listing(int $userId, ?int $limit = null): array;\n"
                   "public function revoke(int $userId, int $sessionId): bool;\n"
                   "public function end(int $userId, ?int $currentTokenId): void;\n}\n")
        self.assertEqual(16, GUARD.validate_kernel_boundaries(self.root))
        for expression in (
            "use App\\Domains\\Identity\\NativeAccountSessions;",
            "use App\\Models\\User;", "use Laravel\\Sanctum\\PersonalAccessToken;",
            "use Illuminate\\Http\\Request;", "public function run(callable $callback): void;",
            "$actor = auth()->user();", "$tokens = DB::table('tokens')->get();",
        ):
            with self.subTest(expression=expression):
                self.assert_violation(relative, prefix + expression, "Host or implementation dependency")

    def test_native_sessions_only_owner_and_exact_composition_root_can_reference_it(self):
        for relative in GUARD.ACCOUNT_SESSION_OWNERS:
            namespace = GUARD.KERNEL_FILES[relative]
            self.write(relative, f"<?php\nnamespace {namespace};\n"
                       "use App\\Domains\\Identity\\NativeAccountSessions;\n")
        self.assertEqual(16, GUARD.validate_kernel_boundaries(self.root))
        for relative in (
            "api/app/Domains/Identity/AnotherSessions.php", "api/app/Http/Controllers/Caller.php",
            "api/app/Domains/InternalApi/AnotherCommand.php", "api/app/Providers/AnotherProvider.php",
            "api/config/forbidden.php", "api/routes/forbidden.php", "api/plugins-core/Example/Plugin.php",
        ):
            for expression in (
                "use App\\Domains\\Identity\\NativeAccountSessions as Sessions;",
                "$value = new \\App\\Domains\\Identity\\NativeAccountSessions();",
                "$value = app('App\\\\Domains\\\\Identity\\\\NativeAccountSessions');",
            ):
                with self.subTest(relative=relative, expression=expression):
                    self.assert_violation(relative, "<?php\n" + expression,
                                          "Concrete account sessions dependency")
                    (self.root / relative).unlink()

    def test_fixed_session_adapters_and_result_reject_host_and_http_dependencies(self):
        for name, label in (
            ("SessionReadOperations", "session read handler"),
            ("SessionMutationOperations", "session mutation command"),
            ("MutationOperationResult", "mutation operation result"),
        ):
            relative = f"api/app/Domains/InternalApi/{name}.php"
            for expression in (
                "use App\\Http\\Controllers\\Txapi\\AuthController;",
                "use Illuminate\\Http\\Request;", "use Symfony\\Component\\HttpFoundation\\Response;",
                "use Illuminate\\Support\\Facades\\DB;", "use GuzzleHttp\\Client;",
                "use Laravel\\Sanctum\\PersonalAccessToken;",
                "$actor = auth()->user();", "$actor = request()->user();", "$value = Auth::user();",
                "$value = app('sessions');", "$value = DB::transaction(fn () => []);",
                "$value = \\file_get_contents('https://example.invalid');",
            ):
                with self.subTest(name=name, expression=expression):
                    self.assert_violation(relative, "<?php\nnamespace App\\Domains\\InternalApi;\n" + expression,
                                          "Host or HTTP dependency in " + label)
            self.write(relative, "<?php\nnamespace App\\Domains\\InternalApi;\n"
                       "use App\\Models\\User;\n"
                       "use App\\Domains\\Identity\\Contracts\\AccountSessions;\n")
        self.assertEqual(16, GUARD.validate_kernel_boundaries(self.root))

    def test_usage_contracts_owner_and_handler_are_required_with_exact_namespaces(self):
        for relative in (
            *(f"api/app/Domains/UsageV2/Contracts/{name}.php" for name in
              ("UsageQueries", "UsageQuery", "UsagePage", "UsageTargetMissing")),
            "api/app/Domains/UsageV2/NativeUsageQueries.php",
            "api/app/Domains/InternalApi/UsageReadOperations.php",
        ):
            with self.subTest(relative=relative):
                original = (self.root / relative).read_text(encoding="utf-8")
                (self.root / relative).unlink()
                with self.assertRaisesRegex(ValueError, "Missing Core capability file"):
                    GUARD.validate_kernel_boundaries(self.root)
                self.assert_violation(relative, "<?php\nnamespace App\\Wrong;\n",
                                      "Wrong Core capability namespace")
                self.write(relative, original)

    def test_usage_contracts_allow_owned_dtos_arrays_and_standard_exceptions(self):
        prefix = "<?php\nnamespace App\\Domains\\UsageV2\\Contracts;\n"
        self.write("api/app/Domains/UsageV2/Contracts/UsageQueries.php", prefix +
                   "interface UsageQueries { public function listing(int $userId, UsageQuery $query): UsagePage; }")
        self.write("api/app/Domains/UsageV2/Contracts/UsagePage.php", prefix +
                   "final readonly class UsagePage { public function __construct("
                   "public array $items, public ?string $nextAfterId) {} }")
        self.write("api/app/Domains/UsageV2/Contracts/UsageQuery.php", prefix +
                   "use InvalidArgumentException; final readonly class UsageQuery { "
                   "public function __construct(public ?int $subscriptionId = null, "
                   "public int $afterId = 0, public int $limit = 100) {} }")
        self.write("api/app/Domains/UsageV2/Contracts/UsageTargetMissing.php", prefix +
                   "use RuntimeException; final class UsageTargetMissing extends RuntimeException {}")
        self.assertEqual(16, GUARD.validate_kernel_boundaries(self.root))

    def test_usage_contracts_reject_host_models_implementations_and_callbacks(self):
        for expression in (
            "use App\\Domains\\UsageV2\\NativeUsageQueries;",
            "use App\\Domains\\UsageV2\\NativeUsageReservations;",
            "use App\\Domains\\Subscription\\Services\\NativeSubscriptionStore;",
            "use App\\Domains\\Billing\\Contracts\\OrderPage;",
            "use App\\Models\\User;", "use Illuminate\\Database\\ConnectionInterface;",
            "use Laravel\\Sanctum\\PersonalAccessToken;",
            "public function listing(callable $callback): array;", "public \\Closure $callback;",
            "public function query(): Builder;", "$value = DB::transaction(fn () => []);",
            "$value = app('usage');", "$value = \\curl_init();",
        ):
            with self.subTest(expression=expression):
                self.assert_violation("api/app/Domains/UsageV2/Contracts/Forbidden.php",
                                      "<?php\nnamespace App\\Domains\\UsageV2\\Contracts;\n" + expression,
                                      "Host or implementation dependency")

    def test_native_usage_queries_is_limited_to_exact_owner_and_composition(self):
        for relative in GUARD.USAGE_QUERY_OWNERS:
            namespace = GUARD.KERNEL_FILES[relative]
            self.write(relative, f"<?php\nnamespace {namespace};\n"
                       "use App\\Domains\\UsageV2\\NativeUsageQueries;\n")
        self.assertEqual(16, GUARD.validate_kernel_boundaries(self.root))
        for relative in (
            "api/app/Domains/UsageV2/AnotherQuery.php", "api/app/Http/Controllers/Caller.php",
            "api/app/Domains/InternalApi/AnotherHandler.php", "api/app/Providers/AnotherProvider.php",
            "api/config/forbidden.php", "api/routes/forbidden.php", "api/plugins-core/Example/Plugin.php",
        ):
            for expression in (
                "use App\\Domains\\UsageV2\\NativeUsageQueries as Queries;",
                "$value = new \\App\\Domains\\UsageV2\\NativeUsageQueries();",
                "$value = app('App\\\\Domains\\\\UsageV2\\\\NativeUsageQueries');",
            ):
                with self.subTest(relative=relative, expression=expression):
                    self.assert_violation(relative, "<?php\n" + expression, "Concrete usage query dependency")
                    (self.root / relative).unlink()

    def test_usage_fixed_read_handler_allows_actor_contracts_and_local_result(self):
        self.write("api/app/Domains/InternalApi/UsageReadOperations.php",
                   "<?php\nnamespace App\\Domains\\InternalApi;\nuse App\\Models\\User;\n"
                   "use App\\Domains\\UsageV2\\Contracts\\UsageQueries;\n"
                   "use App\\Domains\\UsageV2\\Contracts\\UsageQuery;\n"
                   "use App\\Domains\\UsageV2\\Contracts\\UsageTargetMissing;\n"
                   "final class UsageReadOperations { public function __construct(private UsageQueries $queries) {}\n"
                   "public function listing(User $actor, array $input): ReadOperationResult {\n"
                   "$page = $this->queries->listing((int) $actor->id, new UsageQuery());\n"
                   "return new ReadOperationResult($page->items, ['next_after_id' => $page->nextAfterId]); } }")
        self.assertEqual(16, GUARD.validate_kernel_boundaries(self.root))

    def test_usage_fixed_read_handler_rejects_http_auth_sql_and_framework_dependencies(self):
        for expression in (
            "use App\\Http\\Controllers\\Txapi\\NativeUsageController;",
            "use Illuminate\\Http\\Request as Input;", "use Illuminate\\Support\\Facades\\DB;",
            "use Laravel\\Sanctum\\PersonalAccessToken;", "use GuzzleHttp\\Client;",
            "use Symfony\\Component\\HttpFoundation\\Response;",
            "$actor = auth()->user();", "$actor = request()->user();", "$actor = Auth::user();",
            "$value = Request::create('/');", "$value = DB::transaction(fn () => []);",
            "$value = app('usage');", "$value = \\file_get_contents('https://example.invalid');",
        ):
            with self.subTest(expression=expression):
                self.assert_violation("api/app/Domains/InternalApi/UsageReadOperations.php",
                                      "<?php\nnamespace App\\Domains\\InternalApi;\n" + expression,
                                      "Host or HTTP dependency in usage read handler")

    def test_subscription_uses_payment_proof_port_without_restricting_financial_owner(self):
        self.write(
            "api/app/Domains/Subscription/Services/Consumer.php",
            "<?php\nuse App\\Domains\\Billing\\Internal\\WinningPaymentProofs;\n"
            "use App\\Domains\\Subscription\\Internal\\LockedOrderFulfillment;\n"
            "// NativePaymentSettlement owns financial acceptance elsewhere.\n",
        )
        for relative in (
            "api/app/Domains/Billing/NativePaymentSettlement.php",
            "api/app/Domains/Billing/ReceiptConsumer.php",
            "api/app/Http/Controllers/PaymentController.php",
        ):
            self.write(relative, "<?php\nuse App\\Domains\\Billing\\NativePaymentSettlement;\n")
        self.write(
            "api/tests/Feature/FulfillmentTest.php",
            "<?php\nuse App\\Domains\\Subscription\\Services\\SubscriptionFulfillmentService;\n",
        )
        self.assertEqual(16, GUARD.validate_kernel_boundaries(self.root))
        for expression in (
            "use App\\Domains\\Billing\\NativePaymentSettlement as Settlement;",
            "$proof = app(\\App\\Domains\\Billing\\NativePaymentSettlement::class);",
            "$proof = app('App\\\\Domains\\\\Billing\\\\NativePaymentSettlement');",
        ):
            with self.subTest(expression=expression):
                self.assert_violation(
                    "api/app/Domains/Subscription/Services/Consumer.php", "<?php\n" + expression,
                    "Subscription service depends on concrete payment settlement",
                )


if __name__ == "__main__":
    unittest.main()
