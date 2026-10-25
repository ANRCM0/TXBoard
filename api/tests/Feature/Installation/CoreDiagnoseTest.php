<?php

namespace Tests\Feature\Installation;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\InstallationAuthorityException;
use App\Domains\Installation\NativeRuntimeAdmission;
use App\Domains\Installation\NativeRuntimeDiagnostics;
use App\Listeners\EnforceInstallationAuthority;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\NativeRuntimeTestCase;

final class CoreDiagnoseTest extends NativeRuntimeTestCase
{
    // Admission digest of main ae4857a: existing activation receipts must remain valid.
    private const ACTIVATED_ADMISSION_DIGEST = '20be0df531f050026bd6c707e3cdc84f500c2aee93d7d0a39e9401d9c89219ca';

    public function test_active_installation_can_be_diagnosed_without_writes_or_activation_changes(): void
    {
        $this->activate();
        $this->assertSame(self::ACTIVATED_ADMISSION_DIGEST, NativeRuntimeAdmission::digest());
        $before = DB::table('tx_native_runtime_activation')->first();
        $fence = DB::table('tx_authority_writer_fence')->first();
        $probes = $this->capabilityProbes;
        $writes = [];
        DB::listen(static function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/\A\s*(?:insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $report = $this->diagnose(0);
        $this->assertTrue($report['read_only']);
        $this->assertFalse($report['external_services_probed']);
        $this->assertTrue($report['healthy']);
        $this->assertSame('open', $report['sections']['installation']['data']['phase']);
        $this->assertTrue($report['sections']['runtime']['data']['ready']);
        $this->assertTrue($report['sections']['providers']['data']['providers'][0]['registration_matches_configuration']);
        $this->assertSame($probes, $this->capabilityProbes);
        $this->assertSame([], $writes);
        $this->assertEquals($before, DB::table('tx_native_runtime_activation')->first());
        $this->assertEquals($fence, DB::table('tx_authority_writer_fence')->first());
        $this->assertSame(self::ACTIVATED_ADMISSION_DIGEST, NativeRuntimeAdmission::digest());
    }

    public function test_default_report_hides_private_configuration_and_admin_path(): void
    {
        $this->activate();
        DB::table('tx_settings')->updateOrInsert(['name' => 'secure_path'], ['value' => 'private-admin-entry']);
        DB::table('tx_settings')->updateOrInsert(['name' => 'diagnostic_never_export'], ['value' => 'NEVER_EXPORT_SETTING']);
        config(['mail.password' => 'NEVER_EXPORT_SMTP_PASSWORD',
            'diagnostic_never_export' => ['secret' => 'NEVER_EXPORT_EXTRA_CONFIG']]);
        $report = $this->diagnose(0);
        $output = Artisan::output();
        foreach (['private-admin-entry', 'NEVER_EXPORT', 'BEGIN PRIVATE KEY', 'client_key', 'business_key'] as $private) {
            $this->assertStringNotContainsString($private, $output);
        }
        $this->assertNotEmpty(config('app.key'));
        $this->assertStringNotContainsString((string) config('app.key'), $output);
        $this->assertSame('[redacted]', $report['sections']['administrator_entry']['data']['path']);
        $this->assertFalse($report['sections']['administrator_entry']['data']['path_disclosed']);
        $this->assertSame(0, Artisan::call('core:diagnose', ['--json' => true, '--show-admin-path' => true]));
        $explicit = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('/private-admin-entry', $explicit['sections']['administrator_entry']['data']['path']);
        $this->assertTrue($explicit['sections']['administrator_entry']['data']['path_disclosed']);
    }

    public function test_preparing_native_state_reports_not_ready_without_probing_or_activating_it(): void
    {
        $probes = $this->capabilityProbes;
        $report = $this->diagnose(1);
        $this->assertSame('initializing', $report['sections']['installation']['data']['phase']);
        $this->assertSame('error', $report['sections']['runtime']['status']);
        $this->assertSame(0, DB::table('tx_native_runtime_activation')->count());
        $this->assertSame($probes, $this->capabilityProbes);
        $this->assertSame('initializing', app(InstallationAuthority::class)->inspect()['phase']);
    }

    public function test_wrong_worker_epoch_is_diagnosable_while_runtime_work_is_still_rejected(): void
    {
        $this->activate();
        config(['core_installation.worker_epoch' => '1']);
        $report = $this->diagnose(1);
        $this->assertSame('INSTALLATION_AUTHORITY_WORKER_MISMATCH', $report['sections']['runtime']['error_code']);
        $this->assertSame('ok', $report['sections']['modules']['status']);
        $this->expectExceptionMessage('INSTALLATION_AUTHORITY_WORKER_MISMATCH');
        app(InstallationAuthority::class)->assertNativeRuntime();
    }

    public function test_unavailable_dependency_does_not_hide_other_sections_or_leak_exception_text(): void
    {
        $this->app->instance(InstallationAuthority::class, new class {
            public function inspect(): array { throw new RuntimeException('mysql://user:NEVER_EXPORT_PASSWORD@private-host/database'); }
        });
        $report = $this->diagnose(1);
        $this->assertSame('INSTALLATION_UNAVAILABLE', $report['sections']['installation']['error_code']);
        $this->assertSame('ok', $report['sections']['configuration']['status']);
        $this->assertSame('ok', $report['sections']['modules']['status']);
        $this->assertStringNotContainsString('NEVER_EXPORT_PASSWORD', Artisan::output());
        $this->assertStringNotContainsString('private-host', Artisan::output());
    }

    public function test_invalid_origins_never_export_url_credentials_or_query_values(): void
    {
        $this->activate();
        config(['browser.origin' => 'https://user:NEVER_EXPORT_URL_PASSWORD@panel.example/?token=NEVER_EXPORT_QUERY']);
        $report = $this->diagnose(1);
        $this->assertSame('DIAGNOSTIC_ORIGIN_INVALID', $report['sections']['configuration']['error_code']);
        $this->assertStringNotContainsString('NEVER_EXPORT', Artisan::output());
    }

    public function test_registration_corruption_is_reported_without_repairing_it(): void
    {
        $this->activate();
        DB::table('tx_credential_provider_registration')->update(['content_hash' => str_repeat('0', 64)]);
        $report = $this->diagnose(1);
        $this->assertSame('NATIVE_CREDENTIAL_REGISTRATION_INVALID', $report['sections']['providers']['error_code']);
        $this->assertSame(str_repeat('0', 64), DB::table('tx_credential_provider_registration')->value('content_hash'));
    }

    public function test_diagnostics_do_not_admit_tinker_or_add_a_business_runtime_command(): void
    {
        $this->activate();
        $this->assertNotContains('core:diagnose', NativeRuntimeAdmission::COMMANDS);
        $this->assertNotContains('tinker', NativeRuntimeAdmission::COMMANDS);
        $this->diagnose(0);
        $this->expectException(InstallationAuthorityException::class);
        $this->expectExceptionMessage('INSTALLATION_AUTHORITY_NATIVE_COMMAND_UNAVAILABLE');
        app(EnforceInstallationAuthority::class)->commandStarting(new CommandStarting('tinker', new ArrayInput([]), new NullOutput));
    }

    private function diagnose(int $exit): array
    {
        $this->assertSame($exit, Artisan::call('core:diagnose', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame(NativeRuntimeDiagnostics::SCHEMA, $report['schema']);
        return $report;
    }
}
