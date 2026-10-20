<?php

namespace Tests\Feature\Modules;

use App\Models\Plugin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ModuleRuntimeTestCase;

final class NativeModuleBrowserTest extends ModuleRuntimeTestCase
{
    public function test_browser_cards_configuration_runtime_and_lifecycle_use_the_real_fixed_module_owners(): void
    {
        $this->activate();
        $this->enableModule('module_portal', ['label' => 'Native browser']);
        $invoke = $this->browser();
        $invoke('platform.module-cards.list', [])->assertOk()->assertJsonStructure(['data' => ['modules', 'discovery_errors']]);
        $card = $invoke('platform.module-cards.get', ['module_id' => 'module_portal'])->assertOk()->json('data.module');
        $this->assertTrue($card['presentation_available']);
        $configuration = $invoke('platform.module-configuration.get', ['module_id' => 'module_portal'])->assertOk()->json('data');
        $input = ['module_id' => 'module_portal', 'fingerprint' => $configuration['fingerprint'], 'expected_revision' => $configuration['revision'],
            'configuration_json' => '{"label":"Changed in browser"}', 'credential_references' => []];
        $key = (string) Str::uuid();
        $invoke('platform.module-configuration.save', $input, $key)->assertOk()->assertJsonPath('data.phase', 'completed');
        $invoke('platform.module-configuration.save', $input, $key)->assertOk();
        $this->assertSame('Changed in browser', json_decode(Plugin::where('code', 'module_portal')->value('config'), true)['label']);
        $invoke('platform.module-configuration.save', $input, (string) Str::uuid())->assertConflict()->assertJsonPath('error.code', 'BUSINESS_REJECTED');
        $invoke('platform.module-presentation.get', ['module_id' => 'module_portal', 'fingerprint' => $card['fingerprint']])->assertOk()
            ->assertJsonPath('data.operations.0.id', 'status.get')->assertJsonPath('data.operations.0.mode', 'read');
        $operation = ['module_id' => 'module_portal', 'fingerprint' => $card['fingerprint'], 'operation_id' => 'status.get', 'input_json' => '{}'];
        $response = $invoke('platform.module-presentation.query', $operation)->assertOk();
        $this->assertSame('Changed in browser', json_decode($response->json('data.result_json'), true)['label']);
        $invoke('platform.module-presentation.execute', $operation, (string) Str::uuid())->assertUnprocessable();
        $invoke('platform.module-presentation.query', array_replace($operation, ['operation_id' => 'not.declared']))->assertNotFound();
        $fresh = $invoke('platform.module-cards.get', ['module_id' => 'module_portal'])->assertOk()->json('data.module');
        $disable = ['module_id' => 'module_portal', 'fingerprint' => $fresh['fingerprint'], 'expected_revision' => $fresh['revision'], 'action' => 'disable'];
        $disableKey = (string) Str::uuid();
        $invoke('platform.module-lifecycle.execute', $disable, $disableKey)->assertOk();
        $invoke('platform.module-lifecycle.execute', $disable, $disableKey)->assertOk();
        $this->assertFalse((bool) Plugin::where('code', 'module_portal')->value('is_enabled'));
        $invoke('platform.module-presentation.query', $operation)->assertConflict();
        DB::table('tx_internal_actor_grant')->where('permission', 'platform.modules.manage')->delete();
        $invoke('platform.module-lifecycle.execute', $disable, $disableKey)->assertForbidden();
        $audit = json_encode(DB::table('tx_internal_audit')->get()->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Changed in browser', $audit);
    }

    public function test_browser_configuration_replies_and_write_receipts_do_not_expose_saved_secret_references_or_values(): void
    {
        $this->activate();
        $this->enableModule('delivery_receipt', $this->receiverConfiguration());
        $invoke = $this->browser();
        $response = $invoke('platform.module-configuration.get', ['module_id' => 'delivery_receipt'])->assertOk();
        $this->assertStringNotContainsString('secret:receiver', $response->getContent());
        $this->assertStringNotContainsString('original-receiver-secret', $response->getContent());
        $response->assertJsonPath('data.credentials.0.configured', true);
        $snapshot = $response->json('data');
        $input = ['module_id' => 'delivery_receipt', 'fingerprint' => $snapshot['fingerprint'], 'expected_revision' => $snapshot['revision'],
            'configuration_json' => '{"endpoint":"https://changed.example.test/events"}', 'credential_references' => []];
        $key = (string) Str::uuid();
        $invoke('platform.module-configuration.save', $input, $key)->assertOk();
        $replay = $invoke('platform.module-configuration.save', $input, $key)->assertOk();
        $this->assertStringNotContainsString('secret:receiver', $replay->getContent());
        $this->assertStringNotContainsString('original-receiver-secret', $replay->getContent());
        $this->assertSame('secret:receiver', json_decode(Plugin::where('code', 'delivery_receipt')->value('config'), true)['token']);
        DB::table('tx_internal_actor_grant')->where('permission', 'platform.modules.configure')->delete();
        $invoke('platform.module-configuration.get', ['module_id' => 'delivery_receipt'])->assertForbidden();
        $invoke('platform.module-configuration.save', $input, $key)->assertForbidden();
    }

    private function browser(): \Closure
    {
        config(['browser.enabled' => true, 'browser.admin_enabled' => true, 'browser.origin' => 'https://core.example.test', 'browser.rate_store' => 'array']);
        admin_setting(['secure_path' => 'native_modules']);
        [, $headers] = $this->actor(true, ['platform.modules.read', 'platform.modules.configure', 'platform.modules.manage',
            'extensions.module_portal.configure', 'extensions.module_portal.view']);
        $headers += ['X-Core-Admin-Path' => 'native_modules', 'Origin' => 'https://core.example.test'];
        return fn (string $operation, array $input, ?string $key = null) => $this->postJson(
            'https://core.example.test/gateway/v1/core/admin/operations/'.$operation,
            ['input' => $input === [] ? (object) [] : $input], $headers + ($key ? ['Idempotency-Key' => $key] : []));
    }
}
