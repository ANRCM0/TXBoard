<?php

namespace Tests\Feature\Txapi;

use App\Services\NativeAdminAppearance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\NativeRuntimeTestCase;

final class NativeAdminAppearanceTest extends NativeRuntimeTestCase
{
    public function test_public_projection_contains_only_supported_shell_defaults_and_rejects_unsafe_backgrounds(): void
    {
        admin_setting(['admin_sidebar_style' => 'contrast', 'admin_header_style' => 'accent', 'admin_color_scheme' => 'green',
            'admin_login_background_url' => 'https://images.example.test/login.jpg', 'email_password' => 'appearance-private-secret']);
        $appearance = app(NativeAdminAppearance::class)->publicSnapshot();
        $this->assertSame(['admin_sidebar_style' => 'contrast', 'admin_header_style' => 'accent', 'admin_color_scheme' => 'green',
            'admin_login_background_url' => 'https://images.example.test/login.jpg'], $appearance);
        $this->assertStringNotContainsString('appearance-private-secret', json_encode($appearance, JSON_THROW_ON_ERROR));
        foreach (['', '/images/login.jpg', 'https://images.example.test/login.jpg?size=large'] as $url) $this->assertTrue(NativeAdminAppearance::validBackground($url), $url);
        foreach (['javascript:alert(1)', 'data:image/png;base64,a', '//images.example.test/login.jpg', 'https://user:pass@images.example.test/login.jpg',
            'http://images.example.test/login.jpg', '/%5cevil.test/image.jpg', "/images/login\n.jpg", null, false] as $url) $this->assertFalse(NativeAdminAppearance::validBackground($url));
        admin_setting(['admin_sidebar_style' => 'unsupported', 'admin_header_style' => 'topbar-not-implemented', 'admin_color_scheme' => 'arbitrary', 'admin_login_background_url' => 'javascript:bad']);
        $this->assertSame(NativeAdminAppearance::DEFAULTS, app(NativeAdminAppearance::class)->publicSnapshot());
    }

    public function test_native_settings_cas_persists_appearance_and_public_bootstrap_has_only_safe_values(): void
    {
        $this->activate();
        config(['browser.enabled' => true, 'browser.admin_enabled' => true, 'browser.origin' => 'https://core.example.test', 'browser.rate_store' => 'array']);
        admin_setting(['secure_path' => 'native_appearance']);
        [, $actor] = app(\App\Services\Auth\NativeIdentityService::class)->create('appearance-admin@example.test', 'admin-password');
        $actor->forceFill(['is_admin' => true])->saveOrFail();
        foreach (['settings.read', 'settings.write'] as $permission) DB::table('tx_internal_actor_grant')->insert(['user_id' => $actor->id, 'permission' => $permission, 'created_at' => time()]);
        $headers = ['Authorization' => 'Bearer '.$actor->createToken('browser', ['*'])->plainTextToken, 'X-Core-Admin-Path' => 'native_appearance', 'Origin' => 'https://core.example.test'];
        $invoke = fn (string $operation, array $input, ?string $key = null) => $this->postJson('https://core.example.test/gateway/v1/core/admin/operations/'.$operation,
            ['input' => $input === [] ? (object) [] : $input], $headers + ($key ? ['Idempotency-Key' => $key] : []));
        $snapshot = $invoke('settings.configuration.get', [])->assertOk()->json('data');
        $values = ['admin_sidebar_style' => 'contrast', 'admin_header_style' => 'accent', 'admin_color_scheme' => 'green', 'admin_login_background_url' => '/images/native-login.jpg'];
        $input = ['expected_version' => $snapshot['version'], 'values' => $values]; $key = (string) Str::uuid();
        $invoke('settings.configuration.update', $input, $key)->assertOk();
        $invoke('settings.configuration.update', $input, $key)->assertOk();
        $this->assertSame($values, app(NativeAdminAppearance::class)->publicSnapshot());
        $invoke('settings.configuration.update', $input, (string) Str::uuid())->assertConflict();
        $fresh = $invoke('settings.configuration.get', [])->assertOk()->json('data');
        $invoke('settings.configuration.update', ['expected_version' => $fresh['version'], 'values' => ['admin_login_background_url' => 'https://user:pass@example.test/login.jpg']], (string) Str::uuid())->assertUnprocessable();
        $bootstrap = $this->call('GET', 'https://core.example.test/gateway/v1/bootstrap', [], [], [], ['HTTP_ACCEPT' => 'application/json'], '')->assertOk();
        $this->assertSame($values, $bootstrap->json('data.admin_appearance'));
        $this->assertStringNotContainsString('email_password', $bootstrap->getContent());
        DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)->delete();
        $invoke('settings.configuration.get', [])->assertForbidden();
        $invoke('settings.configuration.update', $input, $key)->assertForbidden();
        $this->call('GET', 'https://core.example.test/gateway/v1/bootstrap', [], [], [], ['HTTP_ACCEPT' => 'application/json'], '')->assertOk()->assertJsonPath('data.admin_appearance.admin_color_scheme', 'green');
    }
}
