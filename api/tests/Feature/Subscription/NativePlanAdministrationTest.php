<?php

namespace Tests\Feature\Subscription;

use App\Domains\Subscription\Services\NativePlanAdministration;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Domains\CredentialProvider\ProviderCodec;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeRuntimeTestCase;

final class NativePlanAdministrationTest extends NativeRuntimeTestCase
{
    public function test_publication_replay_and_later_edits_do_not_rewrite_immutable_offers(): void
    {
        $this->activate();
        $actor = $this->administrator();
        $service = app(NativePlanAdministration::class);
        $input = $this->input();
        $first = $service->publish($actor->id, $input, 'admin-publication');
        $this->assertSame('99', $first['publication']['offers'][0]['price_minor']);
        $original = (array) DB::table('tx_plan_offer')->where('id', $first['publication']['offers'][0]['offer_id'])->first();
        $second = $service->publish($actor->id,
            array_replace($input, ['plan_id' => $first['plan_id'], 'expected_revision_id' => $first['publication']['revision_id'], 'prices_minor' => ['monthly' => '123']]), 'admin-edit');
        $this->assertSame('123', $second['publication']['offers'][0]['price_minor']);
        $this->assertSame($original, (array) DB::table('tx_plan_offer')->where('id', $original['id'])->first());
        $this->assertSame(ProviderCodec::canonical($first),
            ProviderCodec::canonical($service->publish($actor->id, $input, 'admin-publication')));
        $this->assertSame(2, DB::table('tx_native_plan_request')->count());
        $this->expectExceptionMessage('NATIVE_PLAN_IDEMPOTENCY_CONFLICT');
        $service->publish($actor->id, array_replace($input, ['name' => 'Changed']), 'admin-publication');
    }

    public function test_domain_rechecks_demoted_administrator_even_when_replaying_a_publication(): void
    {
        $this->activate();
        $actor = $this->administrator();
        $service = app(NativePlanAdministration::class);
        $service->publish($actor->id, $this->input(), 'admin-publication');
        DB::table('tx_user')->where('id', $actor->id)->update(['is_admin' => false]);
        $this->expectExceptionMessage('NATIVE_PLAN_FORBIDDEN');
        $service->publish($actor->id, $this->input(), 'admin-publication');
    }

    public function test_existing_plan_requires_observed_revision_and_a_stale_revision_cannot_overwrite_the_winner(): void
    {
        $this->activate();
        $actor = $this->administrator();
        $service = app(NativePlanAdministration::class);
        $first = $service->publish($actor->id, $this->input(), 'cas-initial');
        $input = array_replace($this->input(), ['plan_id' => $first['plan_id'], 'name' => 'Winner',
            'expected_revision_id' => $first['publication']['revision_id']]);
        $second = $service->publish($actor->id, $input, 'cas-winner');
        $before = DB::table('tx_plan')->where('id', $first['plan_id'])->first();
        foreach ([array_replace($input, ['name' => 'Stale']), array_diff_key($input, ['expected_revision_id' => true])] as $index => $bad) {
            try {
                $service->publish($actor->id, $bad, 'cas-rejected-'.$index);
                $this->fail('An unobserved or stale publication must not overwrite current terms.');
            } catch (\DomainException $failure) {
                $this->assertSame($index === 0 ? 'NATIVE_PLAN_REVISION_CONFLICT' : 'NATIVE_PLAN_INPUT_INVALID', $failure->getMessage());
            }
        }
        $this->assertEquals($before, DB::table('tx_plan')->where('id', $first['plan_id'])->first());
        $this->assertSame(2, DB::table('tx_plan_revision')->where('plan_id', $first['plan_id'])->count());
        $this->assertSame(2, DB::table('tx_native_plan_request')->count());
        $this->assertSame(ProviderCodec::canonical($second), ProviderCodec::canonical($service->publish($actor->id, $input, 'cas-winner')),
            'Original receipt wins over a now-stale expected revision.');
    }

    public function test_pre_cas_receipt_for_an_existing_plan_remains_recoverable(): void
    {
        $this->activate();
        $actor = $this->administrator();
        $service = app(NativePlanAdministration::class);
        $first = $service->publish($actor->id, $this->input(), 'legacy-initial');
        $oldInput = array_replace($this->input(), ['plan_id' => $first['plan_id'], 'name' => 'Pre-CAS terms']);
        $currentInput = $oldInput + ['expected_revision_id' => $first['publication']['revision_id']];
        $second = $service->publish($actor->id, $currentInput, 'legacy-existing-key');
        // A persisted pre-upgrade receipt has the old request hash, without the later CAS field.
        DB::table('tx_native_plan_request')->where('business_key', 'legacy-existing-key')
            ->update(['request_hash' => hash('sha256', ProviderCodec::canonical($oldInput))]);
        $replayed = $service->publish($actor->id, $oldInput, 'legacy-existing-key');
        $this->assertSame(ProviderCodec::canonical($second), ProviderCodec::canonical($replayed));
        $this->assertSame(2, DB::table('tx_plan_revision')->where('plan_id', $first['plan_id'])->count());
    }

    public function test_free_source_can_upgrade_to_a_paid_offer_without_inventing_credit(): void
    {
        $this->activate();
        [$user] = $this->subscribe();
        $actor = $this->administrator();
        $publication = app(NativePlanAdministration::class)->publish($actor->id, $this->input(), 'paid-plan');
        $before = DB::table('tx_order')->count();
        $order = app(PublishedOrderService::class)->create($user->id,
            (int) $publication['publication']['offers'][0]['offer_id'], 'paid-upgrade');
        $this->assertSame('99', (string) $order->total_amount);
        $this->assertSame($before + 1, DB::table('tx_order')->count());
        $this->assertSame(1, DB::table('tx_order_upgrade_credit')->count());
        $this->assertSame('0', (string) DB::table('tx_order_upgrade_credit')->value('credit_minor'));
        $this->assertSame(0, DB::table('tx_upgrade_credit_source')->count());
        $this->assertSame(0, DB::table('tx_money_journal')->count());
    }

    private function administrator(): object
    {
        [, $actor] = app(NativeIdentityService::class)->create('publisher@example.test', 'publisher-password');
        DB::table('tx_user')->where('id', $actor->id)->update(['is_admin' => true]);
        return $actor;
    }

    private function input(): array
    {
        return ['name' => 'Native paid plan', 'quota_gib' => 2, 'group_id' => $this->node->group_ids[0],
            'reset_method' => 2, 'currency' => 'CNY', 'prices_minor' => ['monthly' => '99']];
    }
}
