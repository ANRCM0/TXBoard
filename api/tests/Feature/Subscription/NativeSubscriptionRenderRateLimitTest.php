<?php

namespace Tests\Feature\Subscription;

use App\Domains\CredentialProvider\RenderGrantCodec;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\Auth;
use Tests\Support\NativeRuntimeTestCase;

final class NativeSubscriptionRenderRateLimitTest extends NativeRuntimeTestCase
{
    public function test_render_budget_is_ten_requests_independent_of_other_routes_and_other_accounts(): void
    {
        $this->activate();
        [, $owner] = app(NativeIdentityService::class)->create('render-limit-owner@example.test', 'runtime-password');
        [, $other] = app(NativeIdentityService::class)->create('render-limit-other@example.test', 'runtime-password');
        $headers = ['Authorization' => 'Bearer '.$owner->createToken('render-limit', ['*'])->plainTextToken];
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on']);
        for ($index = 0; $index < 3; $index++) {
            Auth::forgetGuards();
            $this->getJson('https://localhost/txapi/me', $headers)->assertOk();
        }
        $input = ['access_subject_id' => '9223372036854775807',
            'recipient_public_key' => RenderGrantCodec::base64url(str_repeat('r', 32))];
        for ($index = 0; $index < 10; $index++) {
            Auth::forgetGuards();
            $this->postJson('https://localhost/txapi/me/subscription-deliveries', $input, $headers)->assertNotFound();
        }
        Auth::forgetGuards();
        $limited = $this->postJson('https://localhost/txapi/me/subscription-deliveries', $input, $headers)
            ->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED')->assertHeader('Retry-After');
        $this->assertGreaterThan(0, (int) $limited->headers->get('Retry-After'));
        Auth::forgetGuards();
        $this->postJson('https://localhost/txapi/me/subscription-deliveries', $input,
            ['Authorization' => 'Bearer '.$other->createToken('render-limit', ['*'])->plainTextToken])->assertNotFound();
    }
}
