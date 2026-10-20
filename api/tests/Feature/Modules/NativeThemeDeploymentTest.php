<?php

namespace Tests\Feature\Modules;

use App\Domains\Platform\NativeModuleAdministration;
use App\Domains\Platform\NativeThemeDeployment;
use App\Extensions\Contracts\ExtensionFailure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\ModuleRuntimeTestCase;
use ZipArchive;

final class NativeThemeDeploymentTest extends ModuleRuntimeTestCase
{
    private const NAME = 'native-theme-deployment-test';
    private array $archives = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['theme_v2.txboard_version' => '3.6.0', 'theme_v2.browser_version' => '1.0.0', 'theme_v2.sdk_version' => '0.2.0']);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        foreach ($this->archives as $path) if (is_file($path)) unlink($path);
        parent::tearDown();
    }

    public function test_controlled_candidates_install_activate_upgrade_and_delete_via_existing_owners_with_original_result_recovery(): void
    {
        $this->activate();
        $actor = $this->administrator();
        $owner = app(NativeThemeDeployment::class);
        $this->candidate('1.0.0');
        $catalog = $owner->catalog($actor)['candidates'];
        $this->assertCount(1, $catalog);
        $this->assertSame('install', $catalog[0]['action']);
        $this->assertTrue($catalog[0]['eligible']);
        $this->assertArrayNotHasKey('path', $catalog[0]);
        $input = ['candidate_id' => 'release', 'sha256' => $catalog[0]['sha256'], 'expected_revision' => $catalog[0]['expected_revision'], 'action' => 'install'];
        $key = (string) Str::uuid();
        $receipt = $owner->deploy($actor, $input, $key);
        $this->assertSame('install', $receipt['action']);
        $this->assertSame('TXBoard', app(\App\Services\ThemeService::class)->getActiveTheme());
        $this->assertFileExists(base_path('storage/theme/'.self::NAME.'/dist/index.js'));
        $this->assertFileDoesNotExist(public_path('theme/'.self::NAME.'/dist/index.js'));
        $this->assertSame($receipt, $owner->deploy($actor, $input, $key));
        DB::table('tx_native_theme_deployment')->update(['phase' => 'prepared']);
        $this->assertSame($receipt, $owner->deploy($actor, $input, $key));
        $this->assertSame(1, DB::table('tx_native_theme_deployment')->count());
        $moduleOwner = app(NativeModuleAdministration::class);
        $card = $moduleOwner->detail($actor, 'theme.'.self::NAME)['module'];
        $this->assertContains('enable', $card['actions']);
        $enable = ['module_id' => $card['id'], 'fingerprint' => $card['fingerprint'], 'expected_revision' => $card['revision'], 'action' => 'enable'];
        $enableKey = (string) Str::uuid();
        $moduleOwner->lifecycle($actor, $enable, $enableKey);
        $this->assertSame(self::NAME, app(\App\Services\ThemeService::class)->getActiveTheme());
        $this->assertFileExists(public_path('theme/'.self::NAME.'/dist/index.js'));
        $this->assertSame([], $moduleOwner->detail($actor, $card['id'])['module']['actions']);
        $this->candidate('1.1.0', 'window.theme="new"');
        $upgrade = $owner->catalog($actor)['candidates'][0];
        $this->assertSame('upgrade', $upgrade['action']);
        $this->assertSame('1.0.0', $upgrade['installed_version']);
        $upgradeInput = ['candidate_id' => 'release', 'sha256' => $upgrade['sha256'], 'expected_revision' => $upgrade['expected_revision'], 'action' => 'upgrade'];
        $owner->deploy($actor, $upgradeInput, (string) Str::uuid());
        $this->assertSame('window.theme="new"', file_get_contents(public_path('theme/'.self::NAME.'/dist/index.js')));
        $this->assertFileExists(base_path('storage/theme/.rollback/'.self::NAME.'/dist/index.js'));
        $this->assertSame($receipt, $owner->deploy($actor, $input, $key));
        $builtin = $moduleOwner->detail($actor, 'theme.txboard')['module'];
        $moduleOwner->lifecycle($actor, ['module_id' => $builtin['id'], 'fingerprint' => $builtin['fingerprint'], 'expected_revision' => $builtin['revision'], 'action' => 'enable'], (string) Str::uuid());
        $card = $moduleOwner->detail($actor, $card['id'])['module'];
        $delete = ['module_id' => $card['id'], 'fingerprint' => $card['fingerprint'], 'expected_revision' => $card['revision'], 'action' => 'uninstall'];
        $deleteKey = (string) Str::uuid();
        $deleted = $moduleOwner->lifecycle($actor, $delete, $deleteKey);
        $this->assertDirectoryDoesNotExist(base_path('storage/theme/'.self::NAME));
        $this->assertDirectoryDoesNotExist(base_path('storage/theme/.rollback/'.self::NAME));
        DB::table('tx_native_theme_deployment')->where('action', 'uninstall')->update(['phase' => 'prepared']);
        $this->assertSame($deleted, $moduleOwner->lifecycle($actor, $delete, $deleteKey));
        DB::table('tx_internal_actor_grant')->where('permission', 'platform.modules.manage')->delete();
        try { $owner->deploy($actor, $input, $key); $this->fail('Current grant required for completed replay'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
    }

    public function test_candidate_digest_cas_protected_theme_and_interrupted_effect_are_fail_closed(): void
    {
        $this->activate();
        $actor = $this->administrator(); $owner = app(NativeThemeDeployment::class);
        $this->candidate('1.0.0');
        $catalog = $owner->catalog($actor)['candidates'][0];
        $input = ['candidate_id' => 'release', 'sha256' => $catalog['sha256'], 'expected_revision' => $catalog['expected_revision'], 'action' => 'install'];
        try { $owner->deploy($actor, array_replace($input, ['sha256' => str_repeat('0', 64)]), (string) Str::uuid()); $this->fail('Digest mismatch'); }
        catch (ExtensionFailure $error) { $this->assertSame('MODULE_STATE_CHANGED', $error->errorCode); }
        $this->assertSame(0, DB::table('tx_native_theme_deployment')->count());
        $key = (string) Str::uuid(); $owner->deploy($actor, $input, $key);
        try { $owner->deploy($actor, array_replace($input, ['action' => 'upgrade']), $key); $this->fail('Retained input changed'); }
        catch (ExtensionFailure $error) { $this->assertSame('MODULE_DEPLOYMENT_CONFLICT', $error->errorCode); }
        DB::table('tx_native_theme_deployment')->update(['phase' => 'prepared']);
        file_put_contents(base_path('storage/theme/'.self::NAME.'/dist/index.js'), 'tampered');
        try { $owner->deploy($actor, $input, $key); $this->fail('Tampered installed files'); }
        catch (ExtensionFailure $error) { $this->assertSame('MODULE_PACKAGE_INVALID', $error->errorCode); }
        $this->assertSame('prepared', DB::table('tx_native_theme_deployment')->value('phase'));
        file_put_contents(base_path('storage/theme/'.self::NAME.'/dist/index.js'), 'window.theme="first"');
        $owner->deploy($actor, $input, $key);
        $builtin = app(NativeModuleAdministration::class)->detail($actor, 'theme.txboard')['module'];
        try { $owner->lifecycle($actor, ['module_id' => 'theme.txboard', 'fingerprint' => $builtin['fingerprint'], 'expected_revision' => $builtin['revision'], 'action' => 'uninstall'], (string) Str::uuid()); $this->fail('Protected built-in theme'); }
        catch (ExtensionFailure $error) { $this->assertSame('MODULE_PROTECTED', $error->errorCode); }
    }

    public function test_browser_theme_package_and_lifecycle_receipts_reach_real_owners_and_recheck_current_grants(): void
    {
        $this->activate(); $this->candidate('1.0.0');
        config(['browser.enabled' => true, 'browser.admin_enabled' => true, 'browser.origin' => 'https://core.example.test', 'browser.rate_store' => 'array']);
        admin_setting(['secure_path' => 'native_themes']);
        [$actor, $headers] = $this->actor(true, ['platform.modules.read', 'platform.modules.configure', 'platform.modules.manage']);
        $headers += ['X-Core-Admin-Path' => 'native_themes', 'Origin' => 'https://core.example.test'];
        $invoke = fn (string $operation, array $input, ?string $key = null) => $this->postJson(
            'https://core.example.test/gateway/v1/core/admin/operations/'.$operation,
            ['input' => $input === [] ? (object) [] : $input], $headers + ($key ? ['Idempotency-Key' => $key] : []));
        $catalog = $invoke('platform.theme-packages.list', [])->assertOk()->json('data.candidates.0');
        $this->assertArrayNotHasKey('path', $catalog);
        $input = ['candidate_id' => 'release', 'sha256' => $catalog['sha256'], 'expected_revision' => $catalog['expected_revision'], 'action' => 'install'];
        $key = (string) Str::uuid();
        $invoke('platform.theme-packages.deploy', $input, $key)->assertOk()->assertJsonPath('data.action', 'install');
        $invoke('platform.theme-packages.deploy', $input, $key)->assertOk();
        $this->assertSame(1, DB::table('tx_native_theme_deployment')->count());
        $invoke('platform.theme-packages.deploy', $input, (string) Str::uuid())->assertConflict()->assertJsonPath('error.code', 'BUSINESS_REJECTED');
        $card = $invoke('platform.module-cards.get', ['module_id' => 'theme.'.self::NAME])->assertOk()->json('data.module');
        $enable = ['module_id' => $card['id'], 'fingerprint' => $card['fingerprint'], 'expected_revision' => $card['revision'], 'action' => 'enable'];
        $invoke('platform.module-lifecycle.execute', $enable, (string) Str::uuid())->assertOk();
        $this->assertSame(self::NAME, app(\App\Services\ThemeService::class)->getActiveTheme());
        $builtin = $invoke('platform.module-cards.get', ['module_id' => 'theme.txboard'])->assertOk()->json('data.module');
        $invoke('platform.module-lifecycle.execute', ['module_id' => $builtin['id'], 'fingerprint' => $builtin['fingerprint'], 'expected_revision' => $builtin['revision'], 'action' => 'enable'], (string) Str::uuid())->assertOk();
        $card = $invoke('platform.module-cards.get', ['module_id' => $card['id']])->assertOk()->json('data.module');
        $delete = ['module_id' => $card['id'], 'fingerprint' => $card['fingerprint'], 'expected_revision' => $card['revision'], 'action' => 'uninstall'];
        $deleteKey = (string) Str::uuid();
        $invoke('platform.module-lifecycle.execute', $delete, $deleteKey)->assertOk();
        $invoke('platform.module-lifecycle.execute', $delete, $deleteKey)->assertOk();
        $invoke('platform.module-cards.get', ['module_id' => $card['id']])->assertNotFound();
        DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)->where('permission', 'platform.modules.manage')->delete();
        $invoke('platform.theme-packages.list', [])->assertForbidden();
        $invoke('platform.theme-packages.deploy', $input, $key)->assertForbidden();
        $invoke('platform.module-lifecycle.execute', $delete, $deleteKey)->assertForbidden();
        $audit = json_encode(DB::table('tx_internal_audit')->get()->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(config('theme_v2.deployment_candidates.release.path'), $audit);
    }

    private function candidate(string $version, string $js = 'window.theme="first"'): void
    {
        $path = tempnam(sys_get_temp_dir(), 'native-theme-test-'); $this->archives[] = $path;
        $manifest = ['format' => 2, 'name' => self::NAME, 'title' => 'Native Theme', 'version' => $version,
            'entry' => ['type' => 'spa', 'module' => 'dist/index.js'], 'engines' => ['txboard' => '>=3.6', 'browser' => '>=1 <2', 'contract' => 'core-browser/v1'],
            'browser' => ['contract' => 'core-browser/v1', 'sdk' => ['package' => '@txboard/theme-sdk', 'version' => '>=0.2.0 <1.0.0'], 'capabilities' => ['identity.session.create']],
            'assets' => [['path' => 'dist/index.js', 'integrity' => 'sha256-'.base64_encode(hash('sha256', $js, true))]],
            'config' => ['defaults' => ['theme_color' => 'blue'], 'public' => ['theme_color']], 'dependencies' => ['runtime' => []], 'security' => ['csp' => 'strict', 'remoteCode' => false]];
        $zip = new ZipArchive; $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('txboard-theme.json', json_encode($manifest, JSON_THROW_ON_ERROR)); $zip->addFromString('dist/index.js', $js); $zip->close();
        config(['theme_v2.deployment_candidates' => ['release' => ['path' => $path, 'sha256' => hash_file('sha256', $path)]]]);
    }

    private function administrator(): \App\Models\User
    {
        [$actor, $headers] = $this->actor(true, ['platform.modules.read', 'platform.modules.configure', 'platform.modules.manage']);
        return $actor->withAccessToken(PersonalAccessToken::findToken(substr($headers['Authorization'], 7)));
    }

    private function cleanup(): void
    {
        foreach ([base_path('storage/theme/'.self::NAME), base_path('storage/theme/.rollback/'.self::NAME), public_path('theme/'.self::NAME)] as $path) {
            $this->assertStringContainsString(self::NAME, $path); File::deleteDirectory($path);
        }
    }
}
