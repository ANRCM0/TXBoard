<?php

namespace Tests\Feature\Modules;

use App\Core\Host\Modules\ModuleDeployment;
use App\Core\Host\Modules\ModuleReadiness;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\InstallationAuthorityException;
use App\Extensions\Contracts\ExtensionFailure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\ModuleRuntimeTestCase;

final class ModuleDeploymentTest extends ModuleRuntimeTestCase
{
    public function test_partial_extension_ddl_keeps_original_fence_and_resumes_the_same_deployment(): void
    {
        $key = (string) Str::uuid();
        $crash = true;
        DB::listen(static function (QueryExecuted $event) use (&$crash): void {
            if ($crash && str_contains(strtolower($event->sql), 'create table') && str_contains($event->sql, 'tx_ext_offer_account')) {
                $crash = false;
                throw new \RuntimeException('SIMULATED_DDL_PROCESS_LOSS');
            }
        });
        try { $this->deploy('offer_reservation', 'install', key: $key); $this->fail('Expected process loss'); }
        catch (\RuntimeException $error) { $this->assertSame('SIMULATED_DDL_PROCESS_LOSS', $error->getMessage()); }
        $this->assertTrue(Schema::hasTable('tx_ext_offer_account'));
        $this->assertFalse(Schema::hasTable('tx_ext_offer_reservation'));
        $this->assertSame('schema_applying', app(ModuleDeployment::class)->inspect($key)['phase']);
        $this->assertNotNull(DB::table('tx_plugin_package_fence')->where('plugin_code', 'offer_reservation')->value('mutation_token'));
        try { app(InstallationAuthority::class)->modulePreflight(); $this->fail('Expected incomplete deployment'); }
        catch (InstallationAuthorityException $error) { $this->assertSame('INSTALLATION_AUTHORITY_MODULE_DEPLOYMENT_INCOMPLETE', $error->getMessage()); }
        $result = app(ModuleDeployment::class)->resume('offer_reservation', $key);
        $this->assertSame('completed', $result['phase']);
        $this->assertSame($result, $this->deploy('offer_reservation', 'install', key: $key));
        $this->assertTrue(Schema::hasTable('tx_ext_offer_reservation'));
        $this->assertNull(DB::table('tx_plugin_package_fence')->where('plugin_code', 'offer_reservation')->value('mutation_token'));
        $this->assertSame(1, DB::table('tx_module_deployment')->count());
        $this->expectException(ExtensionFailure::class);
        $this->expectExceptionMessage('MODULE_DEPLOYMENT_CONFLICT');
        $this->deploy('offer_reservation', 'enable', key: $key);
    }

    public function test_explicit_composition_adoption_keeps_original_activation_and_worker_epoch(): void
    {
        $this->activate();
        $authority = app(InstallationAuthority::class);
        $originalActivation = (array) DB::table('tx_native_runtime_activation')->first();
        $originalState = $authority->inspect();
        $old = $authority->modulePreflight()['active_fingerprint'];
        config(['core_modules.release_id' => 'ci-next-fixed-image']);
        try { $authority->assertNativeRuntime(); $this->fail('Expected unapproved image composition'); }
        catch (InstallationAuthorityException $error) { $this->assertStringContainsString('MODULE_', $error->getMessage()); }
        $report = $authority->modulePreflight();
        $this->assertNotSame($old, $report['candidate_fingerprint']);
        $key = (string) Str::uuid();
        $receipt = $authority->adoptModuleRelease($key, $old, $report['candidate_fingerprint']);
        $this->assertSame($receipt, $authority->adoptModuleRelease($key, $old, $report['candidate_fingerprint']));
        $this->assertSame('2', $authority->assertNativeRuntime()['authority_epoch']);
        $this->assertSame($originalActivation, (array) DB::table('tx_native_runtime_activation')->first());
        $this->assertSame($originalState, $authority->inspect());
        $this->assertSame(2, DB::table('tx_module_release')->count());
        $this->assertTrue($authority->runtimeReport()['ready']);
        $this->assertSame($report['candidate_fingerprint'], $authority->runtimeReport()['composition_fingerprint']);
    }

    public function test_optional_extension_disable_is_diagnostic_while_real_runtime_stays_admitted(): void
    {
        $this->enableModule('module_portal', ['label' => 'Module portal']);
        $this->activate();
        $this->deploy('module_portal', 'disable');
        $report = DB::transaction(fn (): array => app(ModuleReadiness::class)->inspectLocked(true));
        $this->assertSame('disabled', $report['diagnostics']['plugin.module_portal']);
        $this->assertTrue(app(InstallationAuthority::class)->runtimeReport()['ready']);
        $this->deploy('module_portal', 'enable');
        $report = DB::transaction(fn (): array => app(ModuleReadiness::class)->inspectLocked(true));
        $this->assertArrayNotHasKey('plugin.module_portal', $report['diagnostics']);
    }
}
