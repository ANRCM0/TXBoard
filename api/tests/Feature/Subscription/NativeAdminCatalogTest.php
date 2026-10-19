<?php

namespace Tests\Feature\Subscription;

use App\Domains\Subscription\Contracts\AdminCatalogQueries;
use App\Domains\CredentialProvider\ProviderCodec;
use App\Domains\Subscription\Services\NativePlanAdministration;
use App\Domains\Subscription\Services\PlanPublicationService;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeRuntimeTestCase;

final class NativeAdminCatalogTest extends NativeRuntimeTestCase
{
    public function test_full_current_and_historical_terms_are_verified_publications_not_mutable_plan_fields(): void
    {
        $this->activate();
        $actor = $this->administrator();
        $plans = app(NativePlanAdministration::class);
        $queries = app(AdminCatalogQueries::class);
        $input = $this->input();
        $first = $plans->publish($actor->id, $input, 'catalog-original');
        $secondInput = array_replace($input, ['plan_id' => $first['plan_id'],
            'expected_revision_id' => $first['publication']['revision_id'], 'name' => 'Revised', 'prices_minor' => ['yearly' => '9007199254741000']]);
        $second = $plans->publish($actor->id, $secondInput, 'catalog-revised');
        DB::table('tx_plan')->where('id', $first['plan_id'])->update(['name' => 'Unpublished mutable draft',
            'content' => 'Wrong draft', 'transfer_enable' => 999, 'prices' => '{"monthly":"999.00"}']);

        $current = $queries->publication((int) $first['plan_id']);
        $this->assertSame($second['publication']['revision_id'], $current['current_revision_id']);
        $this->assertSame('Revised', $current['revision']['terms']['name']);
        $this->assertSame(['yearly' => '9007199254741000'], $current['revision']['terms']['prices_minor']);
        $this->assertSame(3, $current['revision']['terms']['quota_gib']);
        $this->assertSame(123, $current['revision']['terms']['speed_limit_mbps']);
        $this->assertSame(4, $current['revision']['terms']['device_limit']);
        $this->assertSame('Original publication content', $current['revision']['terms']['content']);
        $this->assertSame(['one', 'two'], $current['revision']['terms']['tags']);
        $this->assertSame($input['group_id'], $current['revision']['terms']['group_id']);
        $this->assertSame(2, $current['revision']['terms']['reset_method']);
        $this->assertArrayNotHasKey('capacity_limit', $current['revision']['terms']);
        $this->assertSame('published', $current['offers'][0]['status']);
        $this->assertSame('17', $current['offers'][0]['capacity_limit']);
        $this->assertSame('9007199254741000', $current['offers'][0]['price_minor']);

        $original = $queries->publication((int) $first['plan_id'], (int) $first['publication']['revision_id']);
        $this->assertSame('Original', $original['revision']['terms']['name']);
        $this->assertSame(['monthly' => '99', 'yearly' => '1000'], $original['revision']['terms']['prices_minor']);
        $this->assertCount(2, $original['offers']);
        $this->assertSame(['superseded'], array_values(array_unique(array_column($original['offers'], 'status'))));
        $this->assertSame($second['publication']['revision_id'], $original['current_revision_id']);
        $this->assertNull($queries->publication((int) $first['plan_id'], (int) DB::table('tx_plan_revision')
            ->where('plan_id', '!=', $first['plan_id'])->value('id')));

        $history = $queries->history((int) $first['plan_id'], 1, 1);
        $this->assertSame(2, $history['meta']['total']);
        $this->assertSame(2, $history['meta']['last_page']);
        $this->assertSame($second['publication']['revision_id'], $history['items'][0]['revision_id']);
        $this->assertSame($first['publication']['revision_id'], $queries->history((int) $first['plan_id'], 2, 1)['items'][0]['revision_id']);
        $listed = $queries->plans(1, 1);
        $this->assertSame($first['plan_id'], $listed['items'][0]['plan_id']);
        $this->assertSame('Revised', $listed['items'][0]['name']);
        $this->assertSame($second['publication']['revision_id'], $listed['items'][0]['current_revision_id']);
        $this->assertSame(2, $listed['meta']['total']);
        $this->assertSame(2, $queries->offers(1, 1, (int) $first['plan_id'], 'superseded')['meta']['total']);
        $this->assertSame(1, $queries->offers(1, 1, (int) $first['plan_id'], 'published')['meta']['total']);
        $this->assertNull($queries->publication(PHP_INT_MAX));
        $this->assertNull($queries->history(PHP_INT_MAX, 1, 20));
        $this->assertSame('Unpublished mutable draft', DB::table('tx_plan')->where('id', $first['plan_id'])->value('name'));
        $this->assertSame(2, DB::table('tx_plan_revision')->where('plan_id', $first['plan_id'])->count());
        $this->assertSame(2, DB::table('tx_native_plan_request')->where('plan_id', $first['plan_id'])->count());
    }

    public function test_quote_precision_rejection_rolls_back_draft_publication_availability_and_receipt(): void
    {
        $this->activate();
        $actor = $this->administrator();
        $plans = app(NativePlanAdministration::class);
        $queries = app(AdminCatalogQueries::class);
        $first = $plans->publish($actor->id, $this->input(), 'precision-original');
        $snapshot = static fn (): array => [
            'plans' => DB::table('tx_plan')->orderBy('id')->get()->toJson(),
            'revisions' => DB::table('tx_plan_revision')->orderBy('id')->get()->toJson(),
            'offers' => DB::table('tx_plan_offer')->orderBy('id')->get()->toJson(),
            'availability' => DB::table('tx_plan_offer_availability')->orderBy('offer_id')->get()->toJson(),
            'receipts' => DB::table('tx_native_plan_request')->orderBy('actor_id')->orderBy('business_key')->get()->toJson(),
        ];
        $before = $snapshot();
        $current = $queries->publication((int) $first['plan_id']);
        try {
            $plans->publish($actor->id, array_replace($this->input(), [
                'plan_id' => $first['plan_id'], 'expected_revision_id' => $first['publication']['revision_id'],
                'name' => 'Must roll back', 'quota_gib' => 77, 'prices_minor' => ['yearly' => '9007199254740993'],
            ]), 'precision-rejected');
            $this->fail('A quote-parity mismatch must reject publication rather than round the amount.');
        } catch (\RuntimeException $failure) {
            $this->assertSame('CATALOG_LEGACY_QUOTE_PRECISION_MISMATCH', $failure->getMessage());
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($before, $snapshot());
        $this->assertSame($current, $queries->publication((int) $first['plan_id']));
        $this->assertSame($first['publication']['revision_id'], $queries->publication((int) $first['plan_id'])['current_revision_id']);
        $this->assertSame(ProviderCodec::canonical($first), ProviderCodec::canonical(
            $plans->publish($actor->id, $this->input(), 'precision-original')));
        $this->assertSame($before, $snapshot());
    }

    public function test_historical_publication_reads_preserve_supported_non_admin_publication_currency(): void
    {
        $this->activate();
        $planId = (int) DB::table('tx_plan')->value('id');
        $published = app(PlanPublicationService::class)->publish($planId, 'historical-eur', 'EUR');
        $detail = app(AdminCatalogQueries::class)->publication($planId);
        $this->assertSame($published['revision_id'], $detail['current_revision_id']);
        $this->assertSame('EUR', $detail['revision']['terms']['currency']);
        $this->assertSame('EUR', $detail['offers'][0]['currency']);
    }

    public function test_damaged_full_offer_set_fails_even_when_other_offer_would_look_valid(): void
    {
        $this->activate();
        $planId = (int) DB::table('tx_plan')->value('id');
        DB::table('tx_plan_offer')->where('id', $this->offers[1]['offer_id'])->update(['price_minor' => 19]);
        $this->expectExceptionMessage('PUBLICATION_EVIDENCE_DAMAGED');
        app(AdminCatalogQueries::class)->publication($planId);
    }

    public function test_missing_current_availability_is_not_silently_omitted_from_a_complete_detail(): void
    {
        $this->activate();
        $planId = (int) DB::table('tx_plan')->value('id');
        DB::table('tx_plan_offer_availability')->where('offer_id', $this->offers[0]['offer_id'])->delete();
        $this->expectExceptionMessage('ADMIN_CATALOG_EVIDENCE_INVALID');
        app(AdminCatalogQueries::class)->publication($planId);
    }

    private function administrator(): object
    {
        [, $actor] = app(NativeIdentityService::class)->create('catalog-reader@example.test', 'catalog-password');
        DB::table('tx_user')->where('id', $actor->id)->update(['is_admin' => true]);
        return $actor;
    }

    private function input(): array
    {
        return ['name' => 'Original', 'content' => 'Original publication content', 'tags' => ['one', 'two'],
            'quota_gib' => 3, 'group_id' => (string) $this->node->group_ids[0], 'speed_limit_mbps' => 123,
            'device_limit' => 4, 'capacity_limit' => 17, 'reset_method' => 2, 'currency' => 'CNY',
            'prices_minor' => ['monthly' => '99', 'yearly' => '1000']];
    }
}
