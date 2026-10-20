<?php

namespace Tests\Feature\Subscription;

use App\Domains\Subscription\Services\NativeSubscriptionAmendments;
use App\Domains\Subscription\Services\PlanPublicationService;
use App\Models\Plan;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeMysqlWorkflowRace;
use Tests\Support\NativeRuntimeTestCase;
use Tests\Support\NativeWorkflowActors;

final class NativeSubscriptionPlanAmendmentMysqlConcurrencyTest extends NativeRuntimeTestCase
{
    use NativeWorkflowActors, NativeMysqlWorkflowRace;

    public function test_plan_publication_and_entitlement_amendment_observe_current_head_in_both_commit_orders(): void
    {
        $this->requireMysqlProcesses(); $this->activate();
        $admin = $this->workflowActor(true, ['subscription.lifecycle.manage']); $token = $this->authenticateWorkflowActor($admin);
        foreach ([true, false] as $amendmentFirst) {
            [$user] = $this->subscribe(); $this->authenticateWorkflowActor($admin);
            $plan = Plan::query()->firstOrFail()->replicate();
            Plan::withoutEvents(fn () => $plan->forceFill(['name' => 'Concurrent administrative rights', 'transfer_enable' => 4, 'device_limit' => 5])->saveOrFail());
            app(PlanPublicationService::class)->publish($plan->id, 'plan-race-initial', $this->publicationCurrency, 'UTC');
            $revision = DB::table('tx_plan_revision')->where('plan_id', $plan->id)->orderByDesc('id')->first();
            $base = app(NativeSubscriptionAmendments::class)->snapshot($admin->id, $user->id)['amendment'];
            $input = ['subscription_id' => $base['subscription_id'], 'expected_subscription_version' => $base['subscription_version'],
                'expected_terms_id' => $base['terms_id'], 'expected_period_version' => $base['period_version'],
                'changes' => ['plan_revision' => ['plan_id' => (string) $plan->id, 'revision_id' => (string) $revision->id, 'content_hash' => $revision->content_hash]], 'reason' => 'publication_race'];
            Plan::withoutEvents(fn () => $plan->forceFill(['device_limit' => 9])->saveOrFail());
            $amend = function () use ($admin, $token, $user, $input): array {
                Auth::forgetGuards(); Auth::guard('sanctum')->setUser($admin->withAccessToken($token));
                try { return app(NativeSubscriptionAmendments::class)->apply($admin->id, $user->id, $input, $this->workflowKey()); }
                catch (DomainException $error) { return ['error' => $error->getMessage()]; }
            };
            $publish = fn (): array => app(PlanPublicationService::class)->publish($plan->id, 'plan-race-next', $this->publicationCurrency, 'UTC');
            $results = $this->race($amendmentFirst ? [$amend, $publish] : [$publish, $amend], true);
            $actual = $results[$amendmentFirst ? 0 : 1];
            if ($amendmentFirst) {
                $this->assertArrayHasKey('terms_id', $actual);
                $this->assertSame((string) $revision->id, (string) DB::table('tx_subscription_terms')->where('id', $actual['terms_id'])->value('plan_revision_id'));
                $this->assertSame('5', (string) DB::table('tx_subscription_terms')->where('id', $actual['terms_id'])->value('device_limit'));
            } else $this->assertSame('NATIVE_AMENDMENT_PLAN_VERSION_CONFLICT', $actual['error']);
            $this->assertSame($amendmentFirst ? (string) $plan->id : $base['plan_id'],
                (string) DB::table('tx_subscription')->where('id', $base['subscription_id'])->value('plan_id'));
        }
        $this->assertSame(1, DB::table('tx_native_subscription_amendment')->count());
    }
}
