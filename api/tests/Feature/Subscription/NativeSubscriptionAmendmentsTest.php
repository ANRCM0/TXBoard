<?php

namespace Tests\Feature\Subscription;

use App\Domains\Subscription\Services\NativeSubscriptionAmendments;
use App\Domains\Subscription\Services\PlanPublicationService;
use App\Domains\Subscription\Services\SubscriptionEntitlementResolver;
use App\Domains\Subscription\Contracts\EntitlementQuery;
use App\Models\Plan;
use App\Domains\Subscription\Services\SubscriptionLifecycleService;
use App\Domains\Subscription\Services\SubscriptionTermsTimeline;
use App\Domains\UsageV2\UsageReceipts;
use App\Domains\UsageV2\UsagePayload;
use App\Domains\UsageV2\UsageSettlement;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeRuntimeTestCase;
use Tests\Support\NativeWorkflowActors;

final class NativeSubscriptionAmendmentsTest extends NativeRuntimeTestCase
{
    use NativeWorkflowActors;

    public function test_changed_expiry_preserves_the_purchased_calendar_anchor_after_a_later_reset(): void
    {
        $this->activate();
        $plan = \App\Models\Plan::query()->firstOrFail();
        \App\Models\Plan::withoutEvents(fn () => $plan->forceFill(['reset_traffic_method' => 1])->saveOrFail());
        $this->offers = app(\App\Domains\Subscription\Services\PlanPublicationService::class)->publish($plan->id, 'amendment-calendar', $this->publicationCurrency, 'UTC')['offers'];
        [$user, $initial] = $this->subscribe(); $admin = $this->workflowActor(true, ['subscription.lifecycle.manage']); $this->authenticateWorkflowActor($admin);
        $owner = app(NativeSubscriptionAmendments::class); $snapshot = $owner->snapshot($admin->id, $user->id)['amendment'];
        $result = $owner->apply($admin->id, $user->id, $this->input($snapshot, ['expires_at' => (string) ($initial['expires_at'] + 86400)]), $this->workflowKey());
        $reset = app(SubscriptionLifecycleService::class)->reset($user->id, (int) $initial['subscription_id'], 'amendment-later-reset', $result['subscription_version']);
        $period = DB::table('tx_usage_period')->where('id', $reset['usage_period_id'])->first();
        $terms = DB::table('tx_subscription_terms')->where('id', $result['terms_id'])->first();
        $expected = app(\App\Domains\Subscription\Services\NativeSubscriptionCalendar::class)->nextReset(json_decode($terms->reset_policy, true), $initial['expires_at'], (int) $period->starts_at);
        $this->assertSame($expected, (int) $period->next_reset_at);
        $this->assertSame($initial['expires_at'], $expected);
        $this->assertSame((string) $initial['expires_at'], (string) $period->ends_at);
        $this->assertSame($result['terms_id'], (string) $period->terms_id);
    }

    public function test_pending_accepted_usage_cannot_be_reassigned_and_late_settlement_retains_original_terms(): void
    {
        [$admin, $user] = $this->opened(); $owner = app(NativeSubscriptionAmendments::class);
        $nodeSnapshot = $this->snapshot(); $grant = $nodeSnapshot['usage_v2']['grants'][0];
        $payload = ['protocol_version' => 2, 'node_id' => (string) $this->node->id, 'source_epoch' => $nodeSnapshot['usage_v2']['source_epoch'],
            'batch_id' => 'amendment-pending-usage', 'started_at' => $nodeSnapshot['issued_at'], 'ended_at' => $nodeSnapshot['issued_at'] + 1,
            'entries' => [array_intersect_key($grant, array_flip(['access_subject_id', 'auth_snapshot_id', 'tariff_revision_id', 'terms_id', 'usage_period_id']))
                + ['raw_up_bytes' => '3', 'raw_down_bytes' => '5']]];
        $submission = app(UsagePayload::class)->submission(json_encode(['payload' => $payload,
            'payload_digest' => 'sha256:'.hash('sha256', \App\Domains\CredentialProvider\ProviderCodec::canonical($payload))], JSON_THROW_ON_ERROR));
        app(UsageReceipts::class)->submit((int) $this->node->id, $submission, $nodeSnapshot['issued_at'] + 2);
        $snapshot = $owner->snapshot($admin->id, $user->id)['amendment'];
        $this->assertSame('11', $snapshot['consumed_bytes']);
        if (time() < $payload['ended_at']) {
            $this->reject(fn () => $owner->apply($admin->id, $user->id, $this->input($snapshot, ['device_limit' => '7']), $this->workflowKey()), 'NATIVE_ACCEPTED_USAGE_WOULD_BE_REASSIGNED');
        }
        // Advance past the accepted interval before appending a new half-open segment.
        while (time() <= $payload['ended_at']) usleep(100000);
        $result = $owner->apply($admin->id, $user->id, $this->input($snapshot, ['device_limit' => '7']), $this->workflowKey());
        $this->assertSame('11', (string) DB::table('tx_native_usage_reservation')->value('reserved_bytes'));
        $this->assertSame(['settled'], array_values(app(UsageSettlement::class)->scan(10, time() + 2)));
        $this->assertSame('11', (string) DB::table('tx_usage_period')->value('billable_bytes'));
        $this->assertSame($grant['terms_id'], (string) DB::table('tx_usage_entry')->value('terms_id'));
        $this->assertNotSame($grant['terms_id'], $result['terms_id']);
    }

    public function test_amendment_preserves_purchase_usage_and_reset_and_replays_permanent_evidence(): void
    {
        [$admin, $user, $initial] = $this->opened();
        $owner = app(NativeSubscriptionAmendments::class);
        DB::table('tx_usage_period')->where('id', $initial['usage_period_id'])->update(['upload_bytes' => 20, 'download_bytes' => 30, 'billable_bytes' => 50]);
        $previous = DB::table('tx_subscription_terms')->first(); $purchase = DB::table('tx_order_purchase_terms')->first();
        $oldPeriod = DB::table('tx_usage_period')->first(); $account = $user->fresh()->getAttributes();
        $snapshot = $owner->snapshot($admin->id, $user->id)['amendment'];
        $this->assertSame('50', $snapshot['consumed_bytes']);
        $input = $this->input($snapshot, ['quota_bytes' => '9007199254740993', 'speed_limit_mbps' => '123', 'device_limit' => '4', 'expires_at' => (string) ($initial['expires_at'] + 86400)]);
        $key = $this->workflowKey(); $first = $owner->apply($admin->id, $user->id, $input, $key);
        $this->assertSame($first, $owner->apply($admin->id, $user->id, $input, $key));
        $this->assertSame(1, DB::table('tx_native_subscription_amendment')->count());
        $this->assertSame(2, DB::table('tx_subscription_terms')->count());
        $this->assertEquals($previous, DB::table('tx_subscription_terms')->where('id', $previous->id)->first());
        $this->assertEquals($purchase, DB::table('tx_order_purchase_terms')->first());
        $this->assertSame($account, $user->fresh()->getAttributes());
        $period = DB::table('tx_usage_period')->first();
        foreach (['id', 'terms_id', 'starts_at', 'last_reset_at', 'next_reset_at', 'upload_bytes', 'download_bytes', 'billable_bytes'] as $field) $this->assertSame((string) $oldPeriod->$field, (string) $period->$field, $field);
        $this->assertSame('9007199254740993', (string) $period->quota_bytes);
        $this->assertSame('core-admin-quota-amendment/v1', DB::table('tx_usage_adjustment')->value('policy_version'));
        $next = $owner->snapshot($admin->id, $user->id)['amendment'];
        $this->assertSame('123', $next['speed_limit_mbps']); $this->assertSame('4', $next['device_limit']);
        $history = $owner->history($admin->id, $user->id);
        $this->assertCount(1, $history['items']); $this->assertSame('support_case', $history['items'][0]['reason']);
        $this->assertSame($first, $history['items'][0]['result']);
        $this->assertGreaterThan(0, DB::table('tx_native_runtime_outbox')->where('subscription_version', $first['subscription_version'])->count());
        $this->reject(fn () => $owner->apply($admin->id, $user->id, $input, $this->workflowKey()), 'NATIVE_AMENDMENT_VERSION_CONFLICT');
        $input['changes']['device_limit'] = '5';
        $this->reject(fn () => $owner->apply($admin->id, $user->id, $input, $key), 'NATIVE_AMENDMENT_IDEMPOTENCY_CONFLICT');
    }

    public function test_quota_reduction_cannot_consume_accepted_usage_and_invalid_state_rolls_back(): void
    {
        [$admin, $user, $initial] = $this->opened(); $owner = app(NativeSubscriptionAmendments::class);
        DB::table('tx_usage_period')->where('id', $initial['usage_period_id'])->update(['billable_bytes' => 51]);
        $snapshot = $owner->snapshot($admin->id, $user->id)['amendment'];
        $this->reject(fn () => $owner->apply($admin->id, $user->id, $this->input($snapshot, ['quota_bytes' => '50']), $this->workflowKey()), 'NATIVE_AMENDMENT_QUOTA_BELOW_CONSUMED');
        $this->reject(fn () => $owner->apply($admin->id, $user->id, $this->input($snapshot, ['expires_at' => (string) (time() - 1)]), $this->workflowKey()), 'NATIVE_AMENDMENT_INPUT_INVALID');
        $this->assertSame(0, DB::table('tx_native_subscription_amendment')->count());
        $this->assertSame(1, DB::table('tx_subscription_terms')->count());
        $result = $owner->apply($admin->id, $user->id, $this->input($snapshot, ['quota_bytes' => '51']), $this->workflowKey());
        $this->assertSame('51', (string) DB::table('tx_usage_period')->value('quota_bytes'));
        $this->assertSame('51', (string) DB::table('tx_usage_period')->value('billable_bytes'));
        $this->assertSame($initial['usage_period_id'], $result['usage_period_id']);
    }

    public function test_expiry_shortening_and_suspended_amendment_preserve_half_open_history(): void
    {
        [$admin, $user, $initial] = $this->opened(); $owner = app(NativeSubscriptionAmendments::class);
        $suspended = app(SubscriptionLifecycleService::class)->suspend($user->id, (int) $initial['subscription_id'], 'amend-test-suspend', $initial['subscription_version']);
        $snapshot = $owner->snapshot($admin->id, $user->id)['amendment'];
        $expiry = (string) (time() + 3600);
        $result = $owner->apply($admin->id, $user->id, $this->input($snapshot, ['expires_at' => $expiry, 'speed_limit_mbps' => null]), $this->workflowKey());
        $this->assertSame('suspended', $result['status']); $this->assertSame((int) $expiry, $result['expires_at']);
        $this->assertSame($expiry, (string) DB::table('tx_usage_period')->value('ends_at'));
        $this->assertSame(2, count(app(SubscriptionTermsTimeline::class)->resolve($initial['subscription_id'])));
        $this->assertSame(0, DB::table('tx_auth_snapshot')->whereNull('revoked_at')->count());
    }

    public function test_source_evidence_and_terms_mirror_tampering_fail_closed(): void
    {
        [$admin, $user] = $this->opened(); $owner = app(NativeSubscriptionAmendments::class);
        $snapshot = $owner->snapshot($admin->id, $user->id)['amendment'];
        $owner->apply($admin->id, $user->id, $this->input($snapshot, ['device_limit' => '9']), $this->workflowKey());
        $row = DB::table('tx_native_subscription_amendment')->first();
        $damaged = json_decode($row->result_snapshot, true); $damaged['period_version'] = '999';
        DB::table('tx_native_subscription_amendment')->where('id', $row->id)->update(['result_snapshot' => json_encode($damaged)]);
        $this->reject(fn () => $owner->snapshot($admin->id, $user->id), 'NATIVE_AMENDMENT_EVIDENCE_INVALID');
        DB::table('tx_native_subscription_amendment')->where('id', $row->id)->update(['result_snapshot' => $row->result_snapshot]);
        DB::table('tx_subscription_terms')->where('id', $row->terms_id)->update(['device_limit' => 10]);
        $this->reject(fn () => $owner->snapshot($admin->id, $user->id), 'NATIVE_TERMS_MIRROR_INVALID');
    }

    public function test_live_grants_and_tokens_are_required_even_when_replaying_an_owner_receipt(): void
    {
        [$admin, $user] = $this->opened(); $owner = app(NativeSubscriptionAmendments::class);
        $snapshot = $owner->snapshot($admin->id, $user->id)['amendment'];
        $input = $this->input($snapshot, ['device_limit' => '8']); $key = $this->workflowKey();
        $owner->apply($admin->id, $user->id, $input, $key);
        DB::table('tx_internal_actor_grant')->where('user_id', $admin->id)->delete();
        $this->workflowHttpFailure(403, fn () => $owner->apply($admin->id, $user->id, $input, $key));
        DB::table('tx_internal_actor_grant')->insert(['user_id' => $admin->id, 'permission' => 'subscription.lifecycle.manage', 'created_at' => time()]);
        $admin->tokens()->delete();
        $this->expectException(\Illuminate\Auth\AuthenticationException::class);
        $owner->apply($admin->id, $user->id, $input, $key);
    }

    public function test_published_plan_amendment_changes_effective_rights_preserving_paid_purchase_expiry_and_usage(): void
    {
        [$admin, $user, $initial] = $this->opened(); $owner = app(NativeSubscriptionAmendments::class);
        $purchase = DB::table('tx_order_purchase_terms')->first(); $order = DB::table('tx_order')->first(); $previous = DB::table('tx_subscription_terms')->first();
        $period = DB::table('tx_usage_period')->first(); $journalCount = DB::table('tx_money_journal')->count();
        $selection = $this->newPlan(); $snapshot = $owner->snapshot($admin->id, $user->id)['amendment'];
        $input = $this->input($snapshot, ['plan_revision' => $selection]); $key = $this->workflowKey();
        $result = $owner->apply($admin->id, $user->id, $input, $key);
        $this->assertSame($result, $owner->apply($admin->id, $user->id, $input, $key));
        $next = $owner->snapshot($admin->id, $user->id)['amendment'];
        $this->assertSame($selection['plan_id'], $next['plan_id']); $this->assertSame($selection['revision_id'], $next['plan_revision_id']);
        $this->assertSame('4294967296', $next['quota_bytes']); $this->assertSame('55', $next['speed_limit_mbps']); $this->assertSame('5', $next['device_limit']);
        $this->assertSame((string) $initial['expires_at'], $next['expires_at']);
        $this->assertEquals($purchase, DB::table('tx_order_purchase_terms')->first()); $this->assertEquals($order, DB::table('tx_order')->first());
        $this->assertEquals($previous, DB::table('tx_subscription_terms')->where('id', $previous->id)->first());
        $this->assertSame($journalCount, DB::table('tx_money_journal')->count()); $this->assertSame(1, DB::table('tx_order')->count());
        $after = DB::table('tx_usage_period')->first();
        foreach (['id', 'terms_id', 'upload_bytes', 'download_bytes', 'billable_bytes', 'last_reset_at', 'next_reset_at'] as $field) $this->assertSame((string) $period->$field, (string) $after->$field);
        $terms = DB::table('tx_subscription_terms')->where('id', $result['terms_id'])->first();
        $this->assertSame($previous->reset_policy, $terms->reset_policy); $this->assertSame($previous->billing_timezone, $terms->billing_timezone);
        $view = app(SubscriptionEntitlementResolver::class)->forAccount(new EntitlementQuery($user->id))->toArray();
        $this->assertTrue($view['authorized']); $this->assertSame($selection['plan_id'], $view['plan_id']); $this->assertSame($selection['revision_id'], $view['plan_revision_id']);
        $node = $this->snapshot(); $this->assertSame($result['terms_id'], $node['usage_v2']['grants'][0]['terms_id']);
        // A later non-plan amendment retains the exact selected publication, even once it is superseded.
        Plan::withoutEvents(fn () => Plan::query()->whereKey($selection['plan_id'])->update(['device_limit' => 9]));
        app(PlanPublicationService::class)->publish((int) $selection['plan_id'], 'amendment-plan-later', $this->publicationCurrency, 'UTC');
        while (time() <= (int) $result['applied_at']) usleep(100000);
        $owner->apply($admin->id, $user->id, $this->input($next, ['device_limit' => '7']), $this->workflowKey());
        $this->assertSame($selection['revision_id'], $owner->snapshot($admin->id, $user->id)['amendment']['plan_revision_id']);
        $this->assertSame($result, $owner->apply($admin->id, $user->id, $input, $key));
    }

    public function test_plan_amendment_rejects_stale_publication_and_tampered_binding_and_keeps_failed_changes_atomic(): void
    {
        [$admin, $user] = $this->opened(); $owner = app(NativeSubscriptionAmendments::class); $selection = $this->newPlan();
        $snapshot = $owner->snapshot($admin->id, $user->id)['amendment']; $input = $this->input($snapshot, ['plan_revision' => $selection]);
        $bad = $selection; $bad['content_hash'] = str_repeat('0', 64);
        $this->reject(fn () => $owner->apply($admin->id, $user->id, $this->input($snapshot, ['plan_revision' => $bad]), $this->workflowKey()), 'NATIVE_AMENDMENT_PLAN_VERSION_CONFLICT');
        Plan::withoutEvents(fn () => Plan::query()->whereKey($selection['plan_id'])->update(['device_limit' => 8]));
        $published = app(PlanPublicationService::class)->publish((int) $selection['plan_id'], 'amendment-plan-newer', $this->publicationCurrency, 'UTC');
        $this->reject(fn () => $owner->apply($admin->id, $user->id, $input, $this->workflowKey()), 'NATIVE_AMENDMENT_PLAN_VERSION_CONFLICT');
        $this->assertSame(1, DB::table('tx_subscription_terms')->count()); $this->assertSame(0, DB::table('tx_native_subscription_amendment')->count());
        $revision = DB::table('tx_plan_revision')->where('plan_id', $selection['plan_id'])->orderByDesc('id')->first();
        $selection['revision_id'] = (string) $revision->id; $selection['content_hash'] = $revision->content_hash;
        $result = $owner->apply($admin->id, $user->id, $this->input($snapshot, ['plan_revision' => $selection]), $this->workflowKey());
        DB::table('tx_plan_revision')->where('id', $selection['revision_id'])->update(['content_hash' => str_repeat('0', 64)]);
        $this->expectException(\RuntimeException::class); $owner->snapshot($admin->id, $user->id);
    }

    private function newPlan(): array
    {
        $plan = Plan::query()->firstOrFail()->replicate();
        Plan::withoutEvents(fn () => $plan->forceFill(['name' => 'Administrative entitlement target', 'transfer_enable' => 4, 'speed_limit' => 55, 'device_limit' => 5])->saveOrFail());
        app(PlanPublicationService::class)->publish($plan->id, 'amendment-plan-target', $this->publicationCurrency, 'UTC');
        $revision = DB::table('tx_plan_revision')->where('plan_id', $plan->id)->orderByDesc('id')->first();
        return ['plan_id' => (string) $plan->id, 'revision_id' => (string) $revision->id, 'content_hash' => $revision->content_hash];
    }

    private function opened(): array
    {
        $this->activate(); [$user, $initial] = $this->subscribe();
        $admin = $this->workflowActor(true, ['subscription.lifecycle.manage']); $this->authenticateWorkflowActor($admin);
        return [$admin, $user, $initial];
    }

    private function input(array $snapshot, array $changes): array
    {
        return ['subscription_id' => $snapshot['subscription_id'], 'expected_subscription_version' => $snapshot['subscription_version'],
            'expected_terms_id' => $snapshot['terms_id'], 'expected_period_version' => $snapshot['period_version'], 'changes' => $changes, 'reason' => 'support_case'];
    }

    private function reject(\Closure $operation, string $message): void
    {
        try { $operation(); $this->fail('Expected '.$message); }
        catch (DomainException $error) { $this->assertSame($message, $error->getMessage()); }
    }
}
