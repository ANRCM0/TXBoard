<?php

namespace Tests\Feature\Billing;

use App\Domains\InternalApi\ServiceCredentials;
use App\Models\Payment;
use App\Models\Plugin;
use App\Services\Auth\NativeIdentityService;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\NativePaymentTestCase;

final class NativePaymentConfigurationTest extends NativePaymentTestCase
{
    protected string $publicationCurrency = 'CNY';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://core.example.test']);
        $this->payment->update(['payment' => 'EPay', 'name' => 'Original channel', 'config' => [
            'url' => 'https://merchant-private.example.test', 'pid' => '987654321',
            'key' => 'merchant-secret-preserved', 'type' => 'alipay',
        ]]);
        Http::preventStrayRequests();
        HookManager::reset();
    }

    protected function tearDown(): void
    {
        HookManager::reset();
        parent::tearDown();
    }

    public function test_native_provider_form_exposes_only_configured_flags_and_list_versions_without_merchant_material(): void
    {
        [$invoke] = $this->managementClient();
        $providers = $invoke('billing.admin-payment-providers.list', [])->assertOk()
            ->assertJsonFragment(['provider' => 'EPay', 'plugin_code' => 'epay', 'version' => '1.0.0']);
        $form = $invoke('billing.admin-payment-methods.form', ['provider' => 'EPay', 'payment_id' => (string) $this->payment->id])->assertOk();
        $fields = collect($form->json('data.fields'))->keyBy('name');
        foreach (['url', 'pid', 'key', 'type'] as $name) {
            $this->assertTrue($fields[$name]['configured']);
            $this->assertNull($fields[$name]['value']);
        }
        $this->assertSame('password', $fields['key']['kind']);
        $newForm = $invoke('billing.admin-payment-methods.form', ['provider' => 'EPay'])->assertOk();
        foreach ($newForm->json('data.fields') as $field) {
            $this->assertFalse($field['configured']);
            $this->assertNull($field['value']);
        }
        $list = $invoke('billing.admin-payment-methods.list', ['page' => 1, 'per_page' => 1])->assertOk()
            ->assertJsonPath('data.0.id', (string) $this->payment->id)->assertJsonPath('meta.total', 1)
            ->assertJsonMissingPath('data.0.config')->assertJsonMissingPath('data.0.uuid');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $list->json('data.0.version'));
        foreach ([$providers, $form, $newForm, $list] as $response) {
            foreach (['merchant-private.example.test', '987654321', 'merchant-secret-preserved', $this->payment->uuid] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
        $invoke('billing.admin-payment-methods.form', ['provider' => 'EPay', 'payment_id' => '9223372036854775807'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_payment_dirty_patch_keeps_omitted_credentials_and_replays_original_key_without_reverting_later_edits(): void
    {
        [$invoke, $actor] = $this->managementClient();
        $version = $this->version($invoke);
        $key = (string) Str::uuid();
        $input = $this->updateInput($version, ['name' => 'First channel edit',
            'configuration' => [['name' => 'type', 'value' => 'wxpay']]]);
        $invoke('billing.admin-payment-methods.save', $input, $key)->assertOk()->assertJsonPath('data.id', (string) $this->payment->id);
        $this->payment->refresh();
        $this->assertSame('merchant-secret-preserved', $this->payment->config['key']);
        $this->assertSame('987654321', $this->payment->config['pid']);
        $this->assertSame('https://merchant-private.example.test', $this->payment->config['url']);
        $this->assertSame('wxpay', $this->payment->config['type']);
        $nextVersion = $this->version($invoke);
        $this->assertNotSame($version, $nextVersion);
        $invoke('billing.admin-payment-methods.save', $this->updateInput($version, ['name' => 'Stale overwrite']))->assertStatus(409);
        $invoke('billing.admin-payment-methods.save', $this->updateInput($nextVersion, ['name' => 'Later channel edit']))->assertOk();
        $invoke('billing.admin-payment-methods.save', $input, $key)->assertOk();
        $this->assertSame('Later channel edit', $this->payment->fresh()->name);
        DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)->where('permission', 'billing.methods.manage')->delete();
        $invoke('billing.admin-payment-methods.save', $input, $key)->assertForbidden();
        $invoke('billing.admin-payment-methods.save', $this->updateInput($this->version($invoke), ['name' => 'Forbidden overwrite']))->assertForbidden();
        $this->assertSame('Later channel edit', $this->payment->fresh()->name);
        $this->assertSame(1, Payment::count());
        Http::assertNothingSent();
    }

    public function test_payment_configuration_rejects_unrecognised_or_duplicate_fields_and_adapter_invalid_accounts_atomically(): void
    {
        [$invoke] = $this->managementClient();
        $version = $this->version($invoke);
        foreach ([
            [['name' => 'unknown', 'value' => 'injected']],
            [['name' => 'type', 'value' => 'wxpay'], ['name' => 'type', 'value' => 'alipay']],
            [['name' => 'key', 'value' => '']],
        ] as $configuration) {
            $invoke('billing.admin-payment-methods.save', $this->updateInput($version,
                ['name' => 'Must not save', 'configuration' => $configuration]))->assertStatus(422);
            $this->assertSame('Original channel', $this->payment->fresh()->name);
            $this->assertSame('merchant-secret-preserved', $this->payment->fresh()->config['key']);
        }
        // A valid schema is insufficient: accountRef must reject invalid native provider account configuration.
        foreach ([['name' => 'pid', 'value' => 'not-a-merchant'],
            ['name' => 'url', 'value' => 'http://insecure.example.test'],
            ['name' => 'type', 'value' => 'unsafe type']] as $field) {
            $invoke('billing.admin-payment-methods.save', $this->updateInput($version,
                ['name' => 'Invalid account edit', 'configuration' => [$field]]))->assertStatus(409);
            $this->assertSame('Original channel', $this->payment->fresh()->name);
            $this->assertSame($version, $this->version($invoke));
        }
        $invoke('billing.admin-payment-methods.save', $this->updateInput($version) + ['notify_domain' => 'https://injected.example.test'])->assertStatus(422);
        $withoutVersion = $this->updateInput($version);
        unset($withoutVersion['expected_version']);
        $invoke('billing.admin-payment-methods.save', $withoutVersion)->assertStatus(409);
        $this->assertSame(1, Payment::count());
        Http::assertNothingSent();
    }

    public function test_new_method_requires_verified_native_credentials_and_disabled_provider_cannot_accept_configuration_writes(): void
    {
        [$invoke, $actor] = $this->managementClient();
        $input = ['provider' => 'EPay', 'name' => 'New verified channel', 'icon' => null, 'enabled' => false, 'sort' => 2,
            'configuration' => [
                ['name' => 'url', 'value' => 'https://second-private.example.test'], ['name' => 'pid', 'value' => '456789'],
                ['name' => 'key', 'value' => 'second-merchant-secret'],
            ]];
        $key = (string) Str::uuid();
        $created = $invoke('billing.admin-payment-methods.save', $input, $key)->assertOk()->json('data.id');
        $this->assertIsString($created);
        $invoke('billing.admin-payment-methods.save', $input, $key)->assertOk()->assertJsonPath('data.id', $created);
        $this->assertSame(2, Payment::count());
        $createdRow = Payment::findOrFail((int) $created);
        $this->assertFalse($createdRow->enable);
        $this->assertSame('second-merchant-secret', $createdRow->config['key']);
        Plugin::where('code', 'epay')->update(['is_enabled' => false]);
        $invoke('billing.admin-payment-providers.list', [])->assertOk()->assertJsonPath('data', []);
        $invoke('billing.admin-payment-methods.save', array_replace($input, ['name' => 'Disabled provider attempt']))->assertStatus(409);
        $this->assertSame(2, Payment::count());
        DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)->where('permission', 'billing.methods.read')->delete();
        $invoke('billing.admin-payment-methods.list', [])->assertForbidden();
        $invoke('billing.admin-payment-methods.form', ['provider' => 'EPay', 'payment_id' => $created])->assertForbidden();
        Http::assertNothingSent();
    }

    private function updateInput(string $version, array $overrides = []): array
    {
        return array_replace(['payment_id' => (string) $this->payment->id, 'expected_version' => $version,
            'provider' => 'EPay', 'name' => 'Original channel', 'icon' => null, 'enabled' => true,
            'sort' => 0, 'configuration' => []], $overrides);
    }

    private function version(\Closure $invoke): string
    {
        $rows = $invoke('billing.admin-payment-methods.list', [])->assertOk()->json('data');
        return collect($rows)->firstWhere('id', (string) $this->payment->id)['version'];
    }

    private function managementClient(): array
    {
        // NativePaymentTestCase already activates the installation and installs the bundled EPay fixture.
        config(['internal_api.enabled' => true, 'internal_api.trusted_tls_proxies' => ['127.0.0.1']]);
        admin_setting(['secure_path' => 'payment_configuration_admin']);
        [, $actor] = app(NativeIdentityService::class)->create('payment-configuration-admin@example.test', 'admin-password');
        $actor->forceFill(['is_admin' => true])->saveOrFail();
        $bearer = 'Bearer '.$actor->createToken('payment-configuration', ['*'])->plainTextToken;
        foreach (['billing.methods.read', 'billing.methods.manage'] as $permission) {
            DB::table('tx_internal_actor_grant')->insert(['user_id' => $actor->id, 'permission' => $permission, 'created_at' => time()]);
        }
        $fingerprint = str_repeat('e', 64);
        $service = app(ServiceCredentials::class)->issue('payment-configuration-admin', 'admin', $fingerprint, [
            'billing.admin-payment-methods.list', 'billing.admin-payment-providers.list',
            'billing.admin-payment-methods.form', 'billing.admin-payment-methods.save',
        ], time() + 3600);
        $invoke = function (string $operation, array $input, ?string $key = null) use ($bearer, $service, $fingerprint) {
            return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on'])
                ->postJson('https://core.example.test/txapi/internal/native/v1/admin/operations/'.$operation, [
                    'contract_version' => '1', 'request_id' => (string) Str::uuid(), 'deadline_at' => time() + 15,
                    'input' => $input === [] ? (object) [] : $input,
                ], ['Authorization' => $bearer, 'X-Core-Admin-Path' => 'payment_configuration_admin',
                    'X-Core-Service-Id' => $service['service_id'], 'X-Core-Service-Credential' => $service['credential'],
                    'X-Core-Service-Epoch' => $service['epoch'], 'X-Core-Tls-Verified' => 'SUCCESS',
                    'X-Core-Tls-Certificate-Sha256' => $fingerprint, 'Idempotency-Key' => $key ?? (string) Str::uuid()]);
        };
        return [$invoke, $actor];
    }
}
