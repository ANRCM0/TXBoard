<?php

namespace Tests\Feature\Subscription;

use Illuminate\Support\Facades\DB;
use Tests\Support\NativeRuntimeTestCase;
use Tests\Support\NativeWorkflowActors;

final class NativeSubscriptionAmendmentBrowserTest extends NativeRuntimeTestCase
{
    use NativeWorkflowActors;

    public function test_real_admin_core_gateway_roundtrips_amendments_and_rechecks_permissions_before_replay(): void
    {
        $this->activate(); [$user, $subscription] = $this->subscribe();
        $plan = \App\Models\Plan::query()->firstOrFail()->replicate();
        \App\Models\Plan::withoutEvents(fn () => $plan->forceFill(['name' => 'Gateway administrative rights', 'transfer_enable' => 4])->saveOrFail());
        app(\App\Domains\Subscription\Services\PlanPublicationService::class)->publish($plan->id, 'gateway-plan-amendment', $this->publicationCurrency, 'UTC');
        $revision = DB::table('tx_plan_revision')->where('plan_id', $plan->id)->orderByDesc('id')->first();
        $selection = ['plan_id' => (string) $plan->id, 'revision_id' => (string) $revision->id, 'content_hash' => $revision->content_hash];
        $admin = $this->workflowActor(true, ['subscription.lifecycle.manage']);
        config(['browser.enabled' => true, 'browser.admin_enabled' => true, 'browser.origin' => 'https://core.example.test', 'browser.rate_store' => 'array']);
        admin_setting(['secure_path' => 'subscription_amendments']);
        $headers = ['Authorization' => 'Bearer '.$admin->createToken('browser', ['*'])->plainTextToken,
            'X-Core-Admin-Path' => 'subscription_amendments', 'Origin' => 'https://core.example.test'];
        $invoke = fn (string $operation, array $input, ?string $key = null) => $this->postJson(
            'https://core.example.test/gateway/v1/core/admin/operations/'.$operation,
            ['input' => $input], $headers + ($key ? ['Idempotency-Key' => $key] : []));
        $read = $invoke('subscription.admin.amendment.get', ['user_id' => (string) $user->id]);
        $this->assertSame(200, $read->status(), $read->getContent());
        $snapshot = $read->assertOk()
            ->assertJsonPath('data.amendment.editable', true)->json('data.amendment');
        $input = ['user_id' => (string) $user->id, 'subscription_id' => $snapshot['subscription_id'],
            'expected_subscription_version' => $snapshot['subscription_version'], 'expected_terms_id' => $snapshot['terms_id'],
            'expected_period_version' => $snapshot['period_version'], 'changes' => ['plan_revision' => $selection, 'quota_bytes' => '9007199254740993', 'device_limit' => '6'], 'reason' => 'browser_support'];
        $key = $this->workflowKey();
        $result = $invoke('subscription.admin.amendment.apply', $input, $key)->assertOk()->assertJsonPath('data.previous_terms_id', $snapshot['terms_id'])->json('data');
        DB::table('tx_internal_request')->where('operation', 'subscription.admin.amendment.apply')->delete();
        $invoke('subscription.admin.amendment.apply', $input, $key)->assertOk()->assertJsonPath('data', $result);
        $invoke('subscription.admin.amendment.apply', $input, $this->workflowKey())->assertStatus(409);
        $invoke('subscription.admin.amendment.get', ['user_id' => (string) $user->id])->assertOk()
            ->assertJsonPath('data.amendment.plan_id', $selection['plan_id'])->assertJsonPath('data.amendment.plan_revision_id', $selection['revision_id'])
            ->assertJsonPath('data.amendment.expires_at', (string) $subscription['expires_at'])
            ->assertJsonPath('data.amendment.quota_bytes', '9007199254740993')->assertJsonPath('data.amendment.device_limit', '6');
        $history = $invoke('subscription.admin.amendment.history', ['user_id' => (string) $user->id, 'limit' => 1])->assertOk()
            ->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.reason', 'browser_support')->assertJsonPath('data.next_cursor', null);
        $invoke('subscription.admin.amendment.apply', array_replace($input, ['changes' => ['u' => '0']]), $this->workflowKey())->assertStatus(422);
        $this->assertSame(1, DB::table('tx_native_subscription_amendment')->count());
        DB::table('tx_internal_actor_grant')->where('user_id', $admin->id)->delete();
        $invoke('subscription.admin.amendment.apply', $input, $key)->assertForbidden();
        $invoke('subscription.admin.amendment.get', ['user_id' => (string) $user->id])->assertForbidden();
        $invoke('subscription.admin.amendment.history', ['user_id' => (string) $user->id])->assertForbidden();
    }
}
