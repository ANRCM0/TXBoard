<?php

namespace Tests\Feature\Installation;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\InstallationAuthorityException;
use App\Domains\Installation\NativeRuntimeAdmission;
use App\Jobs\OrderHandleJob;
use App\Listeners\EnforceInstallationAuthority;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\NativeRuntimeTestCase;

final class NativeRuntimeReadinessTest extends NativeRuntimeTestCase
{
    public function test_activation_requires_the_native_payment_schema(): void
    {
        Schema::drop('tx_payment_receipt');
        $this->expectExceptionMessage('INSTALLATION_AUTHORITY_SCHEMA_REQUIRED');
        app(InstallationAuthority::class)->readiness();
    }

    public function test_activation_refuses_existing_financial_accounts(): void
    {
        DB::table('tx_money_account')->insert([
            'installation_id' => self::INSTALLATION, 'owner_id' => 0, 'user_id' => null,
            'purpose' => 'provider_clearing', 'currency' => 'USD', 'normal_side' => 'debit',
            'balance_minor' => '0', 'created_at' => time(),
        ]);
        $this->expectExceptionMessage('INSTALLATION_AUTHORITY_FRESH_NATIVE_REQUIRED');
        app(InstallationAuthority::class)->readiness();
    }

    public function test_activation_is_persistent_cas_idempotent_and_requires_the_new_worker_epoch(): void
    {
        $authority = app(InstallationAuthority::class);
        $report = $authority->readiness();
        $this->assertTrue($report['ready_to_activate']);
        $this->assertSame('initializing', $authority->inspect()['phase']);
        $result = $authority->activate('ready-once', '1', $report['configuration_digest']);
        $this->assertSame('open', $result['phase']);
        $this->assertSame(NativeRuntimeAdmission::VERSION, $result['runtime_version']);
        $this->assertSame($result, $authority->activate('ready-once', '1', $report['configuration_digest']));
        $this->assertSame(2, $this->capabilityProbes);
        $this->getJson('/txapi/readiness')->assertStatus(503)->assertJsonPath('error.code', 'INSTALLATION_AUTHORITY_WORKER_MISMATCH');
        config(['core_installation.worker_epoch' => '2']);
        $this->getJson('/txapi/readiness')->assertOk()->assertJsonPath('data.ready', true);
        $this->assertSame(2, $this->capabilityProbes);
        $this->assertSame(1, DB::table('tx_native_runtime_activation')->count());
        $this->expectExceptionMessage('INSTALLATION_AUTHORITY_ACTIVATION_CONFLICT');
        $authority->activate('different-key', '1', $report['configuration_digest']);
    }

    public function test_ready_native_does_not_reopen_legacy_http_writers_or_updater_on_restart(): void
    {
        $this->activate();
        $this->get('/')->assertStatus(503)->assertJsonPath('error.code', 'INSTALLATION_AUTHORITY_NATIVE_CAPABILITY_UNAVAILABLE');
        $this->getJson('/txapi/billing/wallet')->assertStatus(503);
        $this->assertSame(0, Artisan::call('core:installation', ['operation' => 'native-managed']));
        $this->assertSame(1, Artisan::call('core:installation', ['operation' => 'native-preparing']));
        $entrypoint = file_get_contents(base_path('.docker/entrypoint.sh'));
        $this->assertStringContainsString('core:installation native-managed', $entrypoint);
        $this->assertStringNotContainsString('core:installation native-preparing', $entrypoint);
        try {
            app(InstallationAuthority::class)->assertRuntime();
            $this->fail('Legacy read guard reopened');
        } catch (InstallationAuthorityException $error) {
            $this->assertSame('INSTALLATION_AUTHORITY_NATIVE_NOT_READY', $error->getMessage());
        }
        config(['core_installation.mode' => 'legacy']);
        $this->getJson('/txapi/readiness')->assertStatus(503)->assertJsonPath('error.code', 'INSTALLATION_AUTHORITY_WORKER_MISMATCH');
    }

    public function test_preflight_and_postactivation_refuse_configuration_drift(): void
    {
        $authority = app(InstallationAuthority::class);
        $report = $authority->readiness();
        config(['native_provider_node.recipients.'.$this->node->id.'.recipient_sha256' => str_repeat('e', 64)]);
        try {
            $authority->activate('stale-config', '1', $report['configuration_digest']);
            $this->fail('Stale configuration accepted');
        } catch (InstallationAuthorityException $error) {
            $this->assertSame('INSTALLATION_AUTHORITY_ACTIVATION_CONFIGURATION_CHANGED', $error->getMessage());
        }
        $this->assertSame(0, DB::table('tx_native_runtime_activation')->count());
        $this->activate();
        config(['native_node_authorization.key_epoch' => '2']);
        $this->getJson('/txapi/readiness')->assertStatus(503)->assertJsonPath('error.code', 'INSTALLATION_AUTHORITY_NATIVE_CONFIGURATION_CHANGED');
    }

    public function test_activation_schema_requires_ownership_foreign_keys(): void
    {
        \Illuminate\Support\Facades\Schema::table('tx_native_usage_grant', function ($table): void {
            $table->dropForeign(['grant_id']);
        });
        $this->expectExceptionMessage('INSTALLATION_AUTHORITY_SCHEMA_OWNERSHIP_REQUIRED');
        app(InstallationAuthority::class)->readiness();
    }

    public function test_real_capabilities_are_required_and_probe_request_nonces_do_not_change_readiness_digest(): void
    {
        $authority = app(InstallationAuthority::class);
        $first = $authority->readiness();
        $this->assertSame($first['configuration_digest'], $authority->readiness()['configuration_digest']);
        $this->assertTrue($first['provider_capabilities']['node_delivery']);
        $this->assertArrayNotHasKey('request_id', $first['provider_capabilities']);
        $this->providerCapabilities['subscription_render'] = false;
        try {
            $authority->activate('missing-render', '1', $first['configuration_digest']);
            $this->fail('Disabled Provider handler admitted');
        } catch (InstallationAuthorityException $error) {
            $this->assertSame('INSTALLATION_AUTHORITY_PROVIDER_CAPABILITY_REQUIRED', $error->getMessage());
        }
        $this->assertSame('initializing', $authority->inspect()['phase']);
        $this->assertSame(0, DB::table('tx_native_runtime_activation')->count());
    }

    public function test_activation_rechecks_configuration_after_unlocked_provider_probe(): void
    {
        $authority = app(InstallationAuthority::class);
        $report = $authority->readiness();
        $this->duringCapabilityProbe = function (): void {
            config(['native_provider_node.recipients.'.$this->node->id.'.recipient_sha256' => str_repeat('f', 64)]);
        };
        try {
            $authority->activate('racing-config', '1', $report['configuration_digest']);
            $this->fail('Configuration changed during the Provider probe');
        } catch (InstallationAuthorityException $error) {
            $this->assertSame('INSTALLATION_AUTHORITY_ACTIVATION_CONFIGURATION_CHANGED', $error->getMessage());
        }
        $this->assertSame(0, DB::table('tx_native_runtime_activation')->count());
    }

    public function test_preflight_rechecks_provider_registration_after_probe(): void
    {
        $this->duringCapabilityProbe = static function (): void {
            DB::table('tx_credential_provider_registration')->update(['content_hash' => str_repeat('0', 64)]);
        };
        $this->expectExceptionMessage('INSTALLATION_AUTHORITY_PROVIDER_REGISTRATION_INVALID');
        app(InstallationAuthority::class)->readiness();
    }

    public function test_provider_without_real_capabilities_never_admits_activation(): void
    {
        $this->duringCapabilityProbe = static function (): void {
            throw new \App\Domains\CredentialProvider\ProviderError('PROVIDER_HTTP_FAILED', true);
        };
        try {
            app(InstallationAuthority::class)->readiness();
            $this->fail('Provider without capabilities admitted');
        } catch (InstallationAuthorityException $error) {
            $this->assertSame('INSTALLATION_AUTHORITY_PROVIDER_CAPABILITY_UNAVAILABLE', $error->getMessage());
        }
        $this->assertSame('initializing', app(InstallationAuthority::class)->inspect()['phase']);
        $this->assertSame(0, DB::table('tx_native_runtime_activation')->count());
    }

    public function test_ready_native_commands_and_queue_payloads_use_closed_admission(): void
    {
        $this->activate();
        $listener = new EnforceInstallationAuthority();
        $listener->commandStarting(new CommandStarting('core:native-work', new ArrayInput([]), new NullOutput()));
        try {
            $listener->commandStarting(new CommandStarting('reset:traffic', new ArrayInput([]), new NullOutput()));
            $this->fail('Legacy command admitted');
        } catch (InstallationAuthorityException $error) {
            $this->assertSame('INSTALLATION_AUTHORITY_NATIVE_COMMAND_UNAVAILABLE', $error->getMessage());
        }
        $allowed = ['job' => 'Illuminate\Queue\CallQueuedHandler@call', 'data' => [
            'commandName' => OrderHandleJob::class, 'command' => serialize(new OrderHandleJob('runtime-order'))]];
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->once()->andReturn($allowed);
        $listener->jobProcessing(new JobProcessing('sync', $job));
        $job = Mockery::mock(Job::class);
        $allowed['data']['command'] = serialize(new \stdClass());
        $job->shouldReceive('payload')->once()->andReturn($allowed);
        $this->expectExceptionMessage('INSTALLATION_AUTHORITY_NATIVE_JOB_UNAVAILABLE');
        $listener->jobProcessing(new JobProcessing('sync', $job));
    }
}
