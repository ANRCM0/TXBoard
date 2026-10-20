<?php

namespace Tests\Feature\Modules;

use App\Core\Host\Modules\ModuleDeployment;
use App\Domains\Platform\NativeModuleAdministration;
use App\Models\Plugin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ModuleRuntimeTestCase;

final class NativeModuleAdministrationTest extends ModuleRuntimeTestCase
{
    public function test_current_card_revision_fences_lifecycle_and_original_key_replay_keeps_one_owner_intent(): void
    {
        $this->activate();
        $actor = $this->administrator();
        $owner = app(NativeModuleAdministration::class);
        $card = $owner->detail($actor, 'module_portal')['module'];
        $this->assertSame('feature', $card['subtype']);
        $this->assertSame(['install'], $card['actions']);
        $input = ['module_id' => $card['id'], 'fingerprint' => $card['fingerprint'], 'expected_revision' => $card['revision'], 'action' => 'install'];
        $key = (string) Str::uuid();
        $result = $owner->lifecycle($actor, $input, $key);
        $this->assertSame('completed', $result['phase']);
        $this->assertSame($result, $owner->lifecycle($actor, $input, $key));
        $this->assertSame(1, DB::table('tx_module_deployment')->where('module_code', 'module_portal')->count());
        $this->assertFalse((bool) Plugin::query()->where('code', 'module_portal')->value('is_enabled'));
        try { $owner->lifecycle($actor, $input, (string) Str::uuid()); $this->fail('Expected stale card'); }
        catch (\App\Extensions\Contracts\ExtensionFailure $error) { $this->assertSame('MODULE_STATE_CHANGED', $error->errorCode); }
        $this->assertSame(1, DB::table('tx_module_deployment')->where('module_code', 'module_portal')->count());
    }

    public function test_configuration_is_versioned_and_original_completed_result_survives_later_configuration(): void
    {
        $this->enableModule('module_portal', ['label' => 'Original label']);
        $this->activate();
        $actor = $this->administrator();
        $owner = app(NativeModuleAdministration::class);
        $before = $owner->configuration($actor, 'module_portal');
        $this->assertSame(['label' => 'Original label'], json_decode($before['configuration_json'], true));
        $input = ['module_id' => 'module_portal', 'fingerprint' => $before['fingerprint'], 'expected_revision' => $before['revision'],
            'configuration_json' => '{"label":"New label"}', 'credential_references' => []];
        $key = (string) Str::uuid();
        $receipt = $owner->saveConfiguration($actor, $input, $key);
        $after = $owner->configuration($actor, 'module_portal');
        $this->assertNotSame($before['revision'], $after['revision']);
        try { $owner->saveConfiguration($actor, $input, (string) Str::uuid()); $this->fail('Expected stale configuration'); }
        catch (\App\Extensions\Contracts\ExtensionFailure $error) { $this->assertSame('MODULE_STATE_CHANGED', $error->errorCode); }
        $this->deploy('module_portal', 'configure', ['label' => 'Later label']);
        $count = DB::table('tx_module_deployment')->count();
        $this->assertSame($receipt, $owner->saveConfiguration($actor, $input, $key));
        $this->assertSame($count, DB::table('tx_module_deployment')->count());
        $this->assertSame('Later label', json_decode(Plugin::query()->where('code', 'module_portal')->value('config'), true)['label']);
        DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)->where('permission', 'extensions.module_portal.configure')->delete();
        try { $owner->saveConfiguration($actor, $input, $key); $this->fail('Expected current grant withdrawal'); }
        catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
    }

    public function test_configuration_withholds_reference_and_value_preserves_omitted_alias_and_rejects_cross_scope_changes(): void
    {
        $this->enableModule('delivery_receipt', $this->receiverConfiguration());
        $this->activate();
        $actor = $this->administrator();
        $owner = app(NativeModuleAdministration::class);
        $before = $owner->configuration($actor, 'delivery_receipt');
        $wire = json_encode($before, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret:receiver', $wire);
        $this->assertStringNotContainsString('original-receiver-secret', $wire);
        $this->assertSame([['name' => 'token', 'configured' => true]], $before['credentials']);
        $input = ['module_id' => 'delivery_receipt', 'fingerprint' => $before['fingerprint'], 'expected_revision' => $before['revision'],
            'configuration_json' => '{"endpoint":"https://changed.example.test/events"}', 'credential_references' => []];
        $key = (string) Str::uuid();
        $owner->saveConfiguration($actor, $input, $key);
        $saved = json_decode(Plugin::query()->where('code', 'delivery_receipt')->value('config'), true);
        $this->assertSame('secret:receiver', $saved['token']);
        $this->assertSame('https://changed.example.test/events', $saved['endpoint']);
        $fresh = $owner->configuration($actor, 'delivery_receipt');
        $input['expected_revision'] = $fresh['revision'];
        $input['credential_references'] = [['name' => 'token', 'reference' => 'secret:other']];
        config(['core_modules.secrets.other' => 'other-module-secret', 'core_modules.configuration_reference_grants' => [
            ['installation_id' => self::INSTALLATION, 'module' => 'module_portal', 'field' => 'token', 'actor_id' => (string) $actor->id, 'reference' => 'secret:other'],
            ['installation_id' => self::INSTALLATION, 'module' => 'delivery_receipt', 'field' => 'token', 'actor_id' => '999999', 'reference' => 'secret:other'],
        ]]);
        try { $owner->saveConfiguration($actor, $input, (string) Str::uuid()); $this->fail('Expected scoped alias rejection'); }
        catch (\App\Extensions\Contracts\ExtensionFailure $error) { $this->assertSame('MODULE_REFERENCE_DENIED', $error->errorCode); }
        config(['core_modules.configuration_reference_grants' => [['installation_id' => self::INSTALLATION, 'module' => 'delivery_receipt',
            'field' => 'token', 'actor_id' => (string) $actor->id, 'reference' => 'secret:other']]]);
        $replacementKey = (string) Str::uuid();
        $owner->saveConfiguration($actor, $input, $replacementKey);
        $this->assertSame('secret:other', json_decode(Plugin::query()->where('code', 'delivery_receipt')->value('config'), true)['token']);
        $count = DB::table('tx_module_deployment')->count();
        $owner->saveConfiguration($actor, array_replace($input, ['expected_revision' => $fresh['revision']]), $replacementKey);
        $this->assertSame($count, DB::table('tx_module_deployment')->count());
        $owner->saveConfiguration($actor, ['module_id' => 'delivery_receipt', 'fingerprint' => $before['fingerprint'], 'expected_revision' => $before['revision'],
            'configuration_json' => '{"endpoint":"https://changed.example.test/events"}', 'credential_references' => []], $key);
        $this->assertSame('secret:other', json_decode(Plugin::query()->where('code', 'delivery_receipt')->value('config'), true)['token']);
    }

    public function test_stop_before_upgrade_preserves_installed_version_until_the_real_schema_update(): void
    {
        $this->enableModule('module_portal', ['label' => 'Portal']);
        $this->activate();
        Plugin::query()->where('code', 'module_portal')->update(['version' => '0.9.0', 'module_schema_version' => 0]);
        $this->deploy('module_portal', 'disable');
        $row = Plugin::query()->where('code', 'module_portal')->firstOrFail();
        $this->assertSame('0.9.0', $row->version);
        $this->assertSame(0, (int) $row->module_schema_version);
        $this->assertFalse((bool) $row->is_enabled);
        $this->deploy('module_portal', 'update');
        $this->assertSame('1.0.0', $row->fresh()->version);
        $this->assertSame(1, (int) $row->fresh()->module_schema_version);
        $this->assertFalse((bool) $row->fresh()->is_enabled);
    }

    public function test_theme_configuration_uses_live_theme_owner_cas_and_atomic_original_result(): void
    {
        $this->activate();
        $actor = $this->administrator();
        $owner = app(NativeModuleAdministration::class);
        $before = $owner->configuration($actor, 'theme.txboard');
        $values = json_decode($before['configuration_json'], true);
        $this->assertSame('default', $values['theme_color']);
        $values['theme_color'] = 'blue';
        $input = ['module_id' => 'theme.txboard', 'fingerprint' => $before['fingerprint'], 'expected_revision' => $before['revision'],
            'configuration_json' => json_encode($values, JSON_THROW_ON_ERROR), 'credential_references' => []];
        $key = (string) Str::uuid();
        $receipt = $owner->saveConfiguration($actor, $input, $key);
        $this->assertSame('blue', app(\App\Services\ThemeService::class)->getConfig('TXBoard')['theme_color']);
        $this->assertSame(1, DB::table('tx_native_theme_admin_request')->count());
        try { $owner->saveConfiguration($actor, $input, (string) Str::uuid()); $this->fail('Expected stale theme revision'); }
        catch (\App\Extensions\Contracts\ExtensionFailure $error) { $this->assertSame('MODULE_STATE_CHANGED', $error->errorCode); }
        app(\App\Services\ThemeService::class)->updateConfig('TXBoard', ['theme_color' => 'black']);
        $this->assertSame($receipt, $owner->saveConfiguration($actor, $input, $key));
        $this->assertSame('black', app(\App\Services\ThemeService::class)->getConfig('TXBoard')['theme_color']);
        $this->assertSame(1, DB::table('tx_native_theme_admin_request')->count());
        $this->assertNotSame($before['revision'], $owner->configuration($actor, 'theme.txboard')['revision']);
    }

    private function administrator(): \App\Models\User
    {
        [$actor, $headers] = $this->actor(true, ['platform.modules.read', 'platform.modules.configure', 'platform.modules.manage',
            'extensions.module_portal.configure', 'extensions.module_portal.view']);
        return $actor->withAccessToken(PersonalAccessToken::findToken(substr($headers['Authorization'], 7)));
    }
}
