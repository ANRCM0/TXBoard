<?php

namespace Tests\Support;

use App\Domains\CredentialProvider\ProviderError;
use App\Domains\CredentialProvider\ProviderTransport;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Network\NativeNodeAuthorization;
use App\Domains\Network\NativeProviderNodeAuthorization;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Services\Auth\NativeIdentityService;
use App\Domains\Subscription\Services\NativeCredentialProviderService;
use App\Domains\Subscription\Services\PlanPublicationService;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Domains\Subscription\Services\SubscriptionFulfillmentService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/** Production schema and real persisted admission. Only the external Provider wire is replaced here. */
abstract class NativeRuntimeTestCase extends TestCase
{
    protected const INSTALLATION = '33333333-3333-4333-8333-333333333333';
    protected Server $node;
    protected array $offers;
    protected bool $loseProviderResponse = false;
    protected array $providerCapabilities = ['node_delivery' => true, 'subscription_render' => true];
    protected ?\Closure $duringCapabilityProbe = null;
    protected ?\Closure $duringMetadata = null;
    protected int $capabilityProbes = 0;
    protected array $planPrices = ['monthly' => '0.00', 'onetime' => '0.00'];
    protected string $publicationCurrency = 'USD';
    private array $files = [];
    private array $receipts = [];
    private int $sequence = 0;
    private bool $mysqlFixture = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mysqlFixture = getenv('CORE_NATIVE_TEST_MYSQL') === '1';
        $connection = $this->mysqlFixture ? config('database.connections.mysql')
            : array_merge(config('database.connections.sqlite'), ['database' => ':memory:', 'foreign_key_constraints' => true]);
        config(['database.default' => 'native_ready_testing',
            'database.connections.native_ready_testing' => $connection,
            'core_installation.mode' => 'legacy', 'core_installation.worker_epoch' => '1',
            'cache.default' => 'array', 'cache.setting_store' => 'array', 'cache.stores.redis' => ['driver' => 'array']]);
        DB::purge('native_ready_testing');
        if ($this->mysqlFixture) {
            $this->assertDedicatedMysql();
            Schema::dropAllTables();
        }
        Artisan::call('migrate', ['--force' => true]);
        Bus::fake();
        app(InstallationAuthority::class)->initialize(self::INSTALLATION, 'native', 'runtime-test');
        config(['core_installation.mode' => 'native']);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'runtime-test'], $key);
        $certificate = openssl_csr_sign($csr, null, $key, 1);
        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $privatePem);
        $certificateFile = $this->file($certificatePem);
        $privateFile = $this->file($privatePem);
        config([
            'credential_provider.providers.runtime-provider' => ['endpoint' => 'https://provider.invalid/operations',
                'instance_epoch' => '1', 'profiles' => ['vless-uuid/v1'], 'ca_file' => $certificateFile,
                'client_cert' => $certificateFile, 'client_key' => $privateFile],
            'native_provider_node.provider_id' => 'runtime-provider',
            'native_subscription_delivery.providers.runtime-provider' => ['endpoint' => 'https://provider.invalid/subscription-render'],
            'native_node_authorization.enabled' => true,
            'native_node_authorization.policy_version' => NativeNodeAuthorization::PROVIDER_USAGE_POLICY_VERSION,
            'native_node_authorization.core_instance_id' => self::INSTALLATION,
            'native_node_authorization.key_id' => 'runtime-key', 'native_node_authorization.key_epoch' => '1',
            'native_node_authorization.private_seed' => rtrim(strtr(base64_encode(str_repeat('r', 32)), '+/', '-_'), '='),
            'core_usage_v2.core_instance_id' => self::INSTALLATION, 'core_usage_v2.receipt_generation' => '1',
        ]);
        DB::table('tx_settings')->updateOrInsert(['name' => 'server_token'], ['value' => 'runtime-node-token-for-tests']);
        $group = DB::table('tx_server_group')->insertGetId(['name' => 'Runtime', 'created_at' => time(), 'updated_at' => time()]);
        $this->node = Server::withoutEvents(fn () => Server::query()->create([
            'type' => 'vless', 'name' => 'Runtime', 'host' => '127.0.0.1', 'port' => '443', 'server_port' => 443,
            'group_ids' => [$group], 'enabled' => true, 'rate' => '1.50', 'protocol_settings' => ['network' => 'tcp', 'tls' => 0],
        ]));
        config(['native_provider_node.recipients' => [$this->node->id => [
            'machine_id' => null, 'recipient_sha256' => str_repeat('d', 64)]]]);
        $plan = Plan::withoutEvents(fn () => Plan::query()->create(['name' => 'Runtime plan', 'group_id' => $group,
            'transfer_enable' => 2, 'speed_limit' => 30, 'device_limit' => 2, 'reset_traffic_method' => 2,
            'capacity_limit' => null,
            'prices' => $this->planPrices, 'show' => true, 'sell' => true, 'renew' => true]));
        $this->offers = app(PlanPublicationService::class)->publish($plan->id, 'runtime-offers', $this->publicationCurrency, 'UTC')['offers'];
        $transport = Mockery::mock(ProviderTransport::class)->makePartial();
        $transport->shouldReceive('capabilities')->andReturnUsing(function (array $registration): array {
            $this->assertSame(0, DB::transactionLevel());
            $this->capabilityProbes++;
            if ($this->duringCapabilityProbe !== null) { ($this->duringCapabilityProbe)($registration); }
            return ['contract_version' => 'credential-provider-capabilities/1',
                'request_id' => 'capability_'.bin2hex(random_bytes(16)), 'provider_id' => $registration['provider_id'],
                'provider_instance_epoch' => $registration['provider_instance_epoch'], 'store_version' => 2,
                'operations' => ['issue', 'query', 'revoke', 'rotate'],
                'profiles' => ['vless-uuid/v1', 'vmess-uuid/v1']] + $this->providerCapabilities;
        });
        $transport->shouldReceive('call')->andReturnUsing(function (array $registration, array $wire): array {
            $this->assertSame(0, DB::transactionLevel());
            if ($wire['operation'] === 'query') {
                return isset($this->receipts[$wire['operation_id']])
                    ? ['status' => 'succeeded', 'result' => $this->receipts[$wire['operation_id']], 'error' => null]
                    : ['status' => 'unknown', 'result' => null, 'error' => ['code' => 'OPERATION_UNKNOWN', 'retryable' => true]];
            }
            $input = $wire['input'];
            $result = ['access_subject_id' => $input['access_subject_id'], 'provider_subject_ref' => $input['provider_subject_ref']];
            if ($wire['operation'] === 'revoke') {
                $result += ['accepted_revocation_fact_version' => $input['revocation_fact_version'], 'provider_revoked_at' => time()];
            } else {
                $result += ['credential_profile_id' => $input['credential_profile_id'],
                    'credential_ref' => 'credential_'.bin2hex(random_bytes(16)), 'credential_generation' => '1',
                    'issued_at' => time(), 'not_before_at' => $input['not_before_at'], 'credential_expires_at' => $input['credential_expires_at']];
                if ($wire['operation'] === 'rotate') {
                    $result['credential_generation'] = (string) ((int) $input['expected_credential_generation'] + 1);
                    $result += ['previous_credential_ref' => $input['credential_ref'], 'previous_generation' => $input['expected_credential_generation'],
                        'previous_valid_until' => $result['issued_at'], 'rotation_mode' => 'immediate'];
                }
            }
            $this->receipts[$wire['operation_id']] = ['operation' => $wire['operation'], 'input_hash' => $wire['input_hash'], 'result' => $result];
            if ($this->loseProviderResponse) { throw new ProviderError('PROVIDER_TRANSPORT_FAILED', true); }
            return ['status' => 'succeeded', 'result' => $result, 'error' => null];
        });
        $transport->shouldReceive('metadata')->andReturnUsing(function (array $registration, array $request): array {
            $this->assertSame(0, DB::transactionLevel());
            if ($this->duringMetadata !== null) { ($this->duringMetadata)($registration, $request); }
            $credentials = [];
            foreach ($request['subjects'] as $subject) {
                $credentials[] = ['access_subject_id' => $subject['access_subject_id'], 'credential_ref' => $subject['credential_ref'],
                    'credential_generation' => $subject['credential_generation'], 'credential_sha256' => hash('sha256', $subject['credential_ref']),
                    'credential_expires_at' => (int) DB::table('tx_access_subject')->where('id', $subject['access_subject_id'])->value('credential_expires_at')];
            }
            unset($request['subjects']);
            return $request + ['credentials' => $credentials];
        });
        $this->app->instance(ProviderTransport::class, $transport);
        app(NativeCredentialProviderService::class)->register('runtime-provider', 'runtime-registration');
    }

    protected function tearDown(): void
    {
        if ($this->mysqlFixture) {
            $this->assertDedicatedMysql();
            Schema::dropAllTables();
        }
        DB::purge('native_ready_testing');
        foreach ($this->files as $file) { @unlink($file); }
        parent::tearDown();
    }

    protected function activate(): array
    {
        $authority = app(InstallationAuthority::class);
        $report = $authority->readiness();
        $result = $authority->activate('activate-runtime', '1', $report['configuration_digest']);
        config(['core_installation.worker_epoch' => '2']);
        return $result;
    }

    protected function subscribe(string $period = 'monthly'): array
    {
        [, $user] = app(NativeIdentityService::class)->create('runtime-'.(++$this->sequence).'@example.test', 'runtime-password');
        $offer = collect($this->offers)->firstWhere('offer_code', $period);
        $order = app(PublishedOrderService::class)->create($user->id, (int) $offer['offer_id'], 'runtime-order');
        app(PublishedOrderService::class)->transitionOrder($order->id,
            static fn (Order $locked) => $locked->update(['status' => Order::STATUS_PROCESSING]));
        $subscription = app(SubscriptionFulfillmentService::class)->fulfill($order->id, time() - 5);
        return [$user, $subscription];
    }

    protected function snapshot(): array
    {
        $response = app(NativeProviderNodeAuthorization::class)->snapshot($this->node, [
            'kind' => 'node', 'machine_id' => null, 'credential_sha256' => hash('sha256', 'runtime-node-token-for-tests')]);
        $trust = app(NativeProviderNodeAuthorization::class)->publicTrust();
        return NativeNodeAuthorization::verify($response['authorization'], $trust['public_key'], [
            'policy_version' => NativeNodeAuthorization::PROVIDER_USAGE_POLICY_VERSION, 'core_instance_id' => self::INSTALLATION,
            'node_id' => (int) $this->node->id, 'machine_id' => null, 'key_id' => 'runtime-key', 'key_epoch' => '1'], time());
    }

    protected function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'native-runtime-');
        file_put_contents($path, $contents);
        $this->files[] = $path;
        return $path;
    }

    private function assertDedicatedMysql(): void
    {
        if (DB::getDriverName() !== 'mysql'
            || !preg_match('/\Atxboard_c2_native_test(?:_[a-z0-9_]+)?\z/D', DB::connection()->getDatabaseName())) {
            throw new \RuntimeException('Native runtime tests require a dedicated txboard_c2_native_test database.');
        }
    }
}
