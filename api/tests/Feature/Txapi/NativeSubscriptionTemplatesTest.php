<?php

namespace Tests\Feature\Txapi;

use App\Domains\CredentialProvider\Contracts\TemplateValidationClient;
use App\Domains\CredentialProvider\ProviderError;
use App\Domains\InternalApi\ServiceCredentials;
use App\Domains\Subscription\Services\NativeSubscriptionTemplates;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\NativeRuntimeTestCase;

final class NativeSubscriptionTemplatesTest extends NativeRuntimeTestCase
{
    public function test_real_core_operations_validate_then_publish_and_recover_the_owner_commit_without_the_transport_receipt(): void
    {
        [$invoke] = $this->client();
        $validator = Mockery::mock(TemplateValidationClient::class);
        $validator->shouldReceive('validateTemplate')->once()->withArgs(function (array $registration, string $format, string $source): bool {
            $this->assertSame(0, DB::transactionLevel());
            return $registration['provider_id'] === 'runtime-provider' && $format === 'sing-box/v1' && $source === '{{ .Default }}';
        });
        app()->instance(TemplateValidationClient::class, $validator);
        $rows = $invoke('list', [])->assertOk()->json('data.templates');
        $this->assertCount(8, $rows);
        $original = collect($rows)->firstWhere('format_id', 'sing-box/v1');
        $input = ['format_id' => 'sing-box/v1', 'provider_id' => 'runtime-provider', 'expected_version' => $original['version'], 'source' => '{{ .Default }}'];
        $key = (string) Str::uuid();
        $invoke('save', $input, $key)->assertOk()->assertJsonPath('data.ok', true);
        $published = DB::transaction(fn () => app(NativeSubscriptionTemplates::class)->snapshot('sing-box/v1'));
        $this->assertNotSame($original['version'], $published['version']);
        $this->assertSame($input['source'], $published['source']);
        $receipt = DB::table('tx_native_subscription_template_receipt')->sole();
        $this->assertSame(['ok' => true], json_decode(Crypt::decryptString($receipt->result_ciphertext), true));
        $this->assertStringNotContainsString($input['source'], $receipt->result_ciphertext);
        // Simulate loss after the owner's commit and before the generic acknowledgement.
        DB::table('tx_internal_request')->where('operation', 'subscription.admin-templates.save')->delete();
        $invoke('save', $input, $key)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame($published, DB::transaction(fn () => app(NativeSubscriptionTemplates::class)->snapshot('sing-box/v1')));
        $invoke('save', array_replace($input, ['source' => '{"outbounds":[]}']), $key)->assertStatus(409);
        $this->assertSame(1, DB::table('tx_native_subscription_template_receipt')->count());
    }

    public function test_failed_real_parser_result_never_publishes_and_legacy_engine_has_an_explicit_failure(): void
    {
        [$invoke] = $this->client();
        $version = collect($invoke('list', [])->assertOk()->json('data.templates'))->firstWhere('format_id', 'clash-meta/v1')['version'];
        $validator = Mockery::mock(TemplateValidationClient::class);
        $validator->shouldReceive('validateTemplate')->once()->andThrow(new ProviderError('PROVIDER_TEMPLATE_SOURCE_INVALID'));
        $validator->shouldReceive('validateTemplate')->once()->andThrow(new ProviderError('PROVIDER_TEMPLATE_ENGINE_UNSUPPORTED'));
        app()->instance(TemplateValidationClient::class, $validator);
        $input = ['format_id' => 'clash-meta/v1', 'provider_id' => 'runtime-provider', 'expected_version' => $version, 'source' => '{{ range .Connections }}'];
        $invoke('save', $input)->assertStatus(422);
        $invoke('save', array_replace($input, ['source' => '{{ .Default }}']))->assertStatus(409);
        $this->assertSame($version, DB::transaction(fn () => app(NativeSubscriptionTemplates::class)->snapshot('clash-meta/v1')['version']));
        $this->assertSame(0, DB::table('tx_native_subscription_template_receipt')->count());
    }

    public function test_authority_and_version_are_rechecked_after_external_parser_validation(): void
    {
        [$invoke, $actor] = $this->client();
        $version = collect($invoke('list', [])->assertOk()->json('data.templates'))->firstWhere('format_id', 'sing-box/v1')['version'];
        $validator = Mockery::mock(TemplateValidationClient::class);
        $validator->shouldReceive('validateTemplate')->once()->andReturnUsing(function () use ($actor): void {
            DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)->where('permission', 'settings.write')->delete();
        });
        app()->instance(TemplateValidationClient::class, $validator);
        $input = ['format_id' => 'sing-box/v1', 'provider_id' => 'runtime-provider', 'expected_version' => $version, 'source' => '{{ .Default }}'];
        $invoke('save', $input)->assertForbidden();
        $this->assertFalse(DB::transaction(fn () => app(NativeSubscriptionTemplates::class)->snapshot('sing-box/v1')['custom']));
        $this->assertSame(0, DB::table('tx_native_subscription_template_receipt')->count());
    }

    public function test_reset_keeps_an_aba_resistant_revision_and_permanent_replay(): void
    {
        [$invoke] = $this->client();
        $owner = app(NativeSubscriptionTemplates::class);
        $original = DB::transaction(fn () => $owner->snapshot('stash/v1'));
        $input = ['format_id' => 'stash/v1', 'expected_version' => $original['version']];
        $key = (string) Str::uuid();
        $invoke('reset', $input, $key)->assertOk();
        $reset = DB::transaction(fn () => $owner->snapshot('stash/v1'));
        $this->assertFalse($reset['custom']);
        $this->assertNotSame($original['version'], $reset['version']);
        DB::table('tx_internal_request')->where('operation', 'subscription.admin-templates.reset')->delete();
        $invoke('reset', $input, $key)->assertOk();
        $this->assertSame($reset['version'], DB::transaction(fn () => $owner->snapshot('stash/v1')['version']));
        $invoke('reset', $input)->assertStatus(409);
    }

    private function client(): array
    {
        $this->activate();
        config(['internal_api.enabled' => true, 'internal_api.trusted_tls_proxies' => ['127.0.0.1']]);
        admin_setting(['secure_path' => 'templates_admin']);
        [, $actor] = app(NativeIdentityService::class)->create('templates-admin@example.test', 'admin-password');
        $actor->forceFill(['is_admin' => true])->saveOrFail();
        $bearer = 'Bearer '.$actor->createToken('templates', ['*'])->plainTextToken;
        foreach (['settings.read', 'settings.write'] as $permission) DB::table('tx_internal_actor_grant')->insert([
            'user_id' => $actor->id, 'permission' => $permission, 'created_at' => time()]);
        $fingerprint = str_repeat('e', 64);
        $ops = array_map(fn ($suffix) => 'subscription.admin-templates.'.$suffix, ['list', 'save', 'reset']);
        $service = app(ServiceCredentials::class)->issue('subscription-templates', 'admin', $fingerprint, $ops, time() + 3600);
        $invoke = function (string $suffix, array $input, ?string $key = null) use ($bearer, $service, $fingerprint) {
            return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on'])
                ->postJson('https://core.example.test/txapi/internal/native/v1/admin/operations/subscription.admin-templates.'.$suffix, [
                    'contract_version' => '1', 'request_id' => (string) Str::uuid(), 'deadline_at' => time() + 30,
                    'input' => $input === [] ? (object) [] : $input,
                ], ['Authorization' => $bearer, 'X-Core-Admin-Path' => 'templates_admin',
                    'X-Core-Service-Id' => $service['service_id'], 'X-Core-Service-Credential' => $service['credential'],
                    'X-Core-Service-Epoch' => $service['epoch'], 'X-Core-Tls-Verified' => 'SUCCESS',
                    'X-Core-Tls-Certificate-Sha256' => $fingerprint, 'Idempotency-Key' => $key ?? (string) Str::uuid()]);
        };
        return [$invoke, $actor];
    }
}
