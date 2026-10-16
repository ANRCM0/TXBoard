<?php

namespace Tests\Feature\Subscription;

use App\Domains\Installation\InstallationAuthority;
use App\Models\PlanOffer;
use App\Models\PlanRevision;
use App\Domains\Subscription\Services\PlanPublicationService;
use App\Support\Setting as RuntimeSetting;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PlanPublicationTest extends TestCase
{
    private array $settings = [];
    private bool $denyAuthority = false;
    private int $admissions = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'plan_publication_testing',
            'database.connections.plan_publication_testing' => array_merge(config('database.connections.sqlite'), [
                'database' => ':memory:', 'foreign_key_constraints' => true,
            ]),
        ]);
        DB::purge('plan_publication_testing');
        Schema::create('tx_plan', function (Blueprint $table): void {
            $table->integer('id', true);
            $table->string('name');
            $table->integer('group_id')->nullable();
            $table->unsignedBigInteger('transfer_enable')->nullable();
            $table->unsignedInteger('speed_limit')->nullable();
            $table->unsignedInteger('device_limit')->nullable();
            $table->integer('reset_traffic_method')->nullable();
            $table->json('prices')->nullable();
            $table->boolean('show')->default(true);
            $table->boolean('sell')->default(true);
            $table->boolean('renew')->default(true);
            $table->unsignedInteger('capacity_limit')->nullable();
            $table->text('content')->nullable();
            $table->json('tags')->nullable();
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
        });
        (require database_path('migrations/2026_10_12_000002_create_plan_catalog_shadow_tables.php'))->up();
        (require database_path('migrations/2026_10_15_000003_enable_plan_publication.php'))->up();

        $authority = Mockery::mock(InstallationAuthority::class);
        $authority->shouldReceive('inspect')->andReturn(['mode' => 'native', 'phase' => 'initializing']);
        $authority->shouldReceive('assertCatalogMutation')->andReturnUsing(function (): void {
            $this->assertGreaterThan(0, DB::transactionLevel());
            $this->admissions++;
            if ($this->denyAuthority) {
                throw new RuntimeException('INSTALLATION_CATALOG_MUTATION_REFUSED');
            }
        });
        $this->app->instance(InstallationAuthority::class, $authority);
        $setting = Mockery::mock(RuntimeSetting::class);
        $setting->shouldReceive('get')->andReturnUsing(fn (string $key, mixed $default = null): mixed => $this->settings[$key] ?? $default);
        $this->app->instance(RuntimeSetting::class, $setting);
    }

    protected function tearDown(): void
    {
        DB::purge('plan_publication_testing');
        parent::tearDown();
    }

    public function test_publication_freezes_exact_native_revision_and_all_offer_kinds_without_changing_plan(): void
    {
        $id = $this->plan(['prices' => '{"monthly":0.29,"yearly":"120.10","onetime":99,"reset_traffic":1.2}']);
        $before = DB::table('tx_plan')->where('id', $id)->first();
        $this->settings['new_order_event_id'] = 1;
        $result = $this->service()->publish($id, 'publication-first', 'USD', 'Asia/Shanghai');
        $this->assertSame(1, $this->admissions);
        $this->assertSame('plan-publication/v1', $result['schema']);
        $this->assertSame((string) $id, $result['plan_id']);
        $this->assertSame('1', $result['revision_no']);
        $this->assertEquals($before, DB::table('tx_plan')->where('id', $id)->first());
        $revision = PlanRevision::query()->firstOrFail();
        $this->assertSame('2147483648', $revision->quota_bytes);
        $this->assertSame('Asia/Shanghai', $revision->billing_timezone);
        $this->assertSame(['revision' => 'purchased-reset/v1', 'method' => 0, 'timezone' => 'Asia/Shanghai'], $revision->reset_policy);
        $this->assertSame(1, $revision->source_snapshot['open_events']['new_order_event_id']);
        $this->assertSame(Carbon::shouldOverflowMonths(), $revision->source_snapshot['calendar_policy']['month_overflow']);
        $this->assertSame($revision->content_hash, $revision->publication_request_hash);
        $offers = PlanOffer::query()->get()->keyBy('offer_code');
        $this->assertSame('29', $offers['monthly']->price_minor);
        $this->assertSame('12010', $offers['yearly']->price_minor);
        $this->assertSame(12, $offers['yearly']->calendar_months);
        $this->assertSame('perpetual', $offers['onetime']->purchase_kind);
        $this->assertSame('traffic_reset', $offers['reset_traffic']->purchase_kind);
        $this->assertSame('UTC', $offers['monthly']->calendar_policy['timezone']);
        $this->assertSame(4, DB::table('tx_plan_offer_availability')->where('status', 'published')->count());
        $this->assertSame(0, DB::table('tx_plan_catalog_shadow')->count());
    }

    public function test_same_key_replay_preserves_result_and_does_not_reset_managed_availability(): void
    {
        $id = $this->plan();
        $result = $this->service()->publish($id, 'first');
        $offer = (int) $result['offers'][0]['offer_id'];
        $managed = $this->service()->setAvailability($offer, [
            'show' => false, 'sell' => false, 'renew' => true, 'capacity_limit' => 3,
        ], 1);
        DB::table('tx_plan')->where('id', $id)->update(['show' => false, 'sell' => false, 'capacity_limit' => 99]);
        $this->assertSame($result, $this->service()->publish($id, 'first'));
        $this->assertSame('2', $managed['row_version']);
        $stored = DB::table('tx_plan_offer_availability')->where('offer_id', $offer)->first();
        $this->assertSame(0, (int) $stored->sell);
        $this->assertSame(3, (int) $stored->capacity_limit);
        $this->assertSame(2, (int) $stored->row_version);
        $this->assertSame(1, PlanRevision::query()->count());
    }

    public function test_same_key_after_source_change_conflicts_without_partial_publication(): void
    {
        $id = $this->plan();
        $first = $this->service()->publish($id, 'first');
        DB::table('tx_plan')->where('id', $id)->update(['transfer_enable' => 4]);
        $this->reject(fn () => $this->service()->publish($id, 'first'), 'PUBLICATION_IDEMPOTENCY_CONFLICT');
        $this->assertSame(1, PlanRevision::query()->count());
        $this->assertSame('published', DB::table('tx_plan_offer_availability')->value('status'));
        $this->assertSame($first['content_hash'], PlanRevision::query()->value('content_hash'));
    }

    public function test_context_and_open_events_are_part_of_idempotency_source(): void
    {
        $id = $this->plan();
        $this->service()->publish($id, 'first');
        $this->reject(fn () => $this->service()->publish($id, 'first', 'USD'), 'PUBLICATION_IDEMPOTENCY_CONFLICT');
        $this->reject(fn () => $this->service()->publish($id, 'first', 'CNY', 'Asia/Shanghai'), 'PUBLICATION_IDEMPOTENCY_CONFLICT');
        $this->settings['renew_order_event_id'] = 1;
        $this->reject(fn () => $this->service()->publish($id, 'first'), 'PUBLICATION_IDEMPOTENCY_CONFLICT');
        $this->assertSame(1, PlanRevision::query()->count());
    }

    public function test_successor_preserves_frozen_evidence_and_replay_does_not_republish_predecessor(): void
    {
        $id = $this->plan();
        $first = $this->service()->publish($id, 'first');
        $oldRevision = PlanRevision::query()->firstOrFail()->getAttributes();
        $oldOffer = PlanOffer::query()->firstOrFail()->getAttributes();
        $second = $this->service()->publish($id, 'second');
        $this->assertSame('2', $second['revision_no']);
        $this->assertSame('superseded', DB::table('tx_plan_offer_availability')->where('offer_id', $first['offers'][0]['offer_id'])->value('status'));
        $this->assertSame('published', DB::table('tx_plan_offer_availability')->where('offer_id', $second['offers'][0]['offer_id'])->value('status'));
        $this->assertSame($oldRevision, PlanRevision::query()->findOrFail($first['revision_id'])->getAttributes());
        $this->assertSame($oldOffer, PlanOffer::query()->findOrFail($first['offers'][0]['offer_id'])->getAttributes());
        $this->assertSame($first, $this->service()->publish($id, 'first'));
        $this->assertSame('superseded', DB::table('tx_plan_offer_availability')->where('offer_id', $first['offers'][0]['offer_id'])->value('status'));
    }

    public function test_shadow_catalog_is_never_promoted_and_versions_span_both_origins(): void
    {
        $id = $this->plan();
        $first = $this->service()->publish($id, 'first');
        DB::table('tx_plan_revision')->where('id', $first['revision_id'])->update([
            'schema_version' => 'plan-catalog-shadow/v1', 'publication_key' => null,
            'publication_request_hash' => null, 'revision_no' => 20,
        ]);
        DB::table('tx_plan_offer_availability')->where('offer_id', $first['offers'][0]['offer_id'])->update(['status' => 'shadow']);
        $next = $this->service()->publish($id, 'native');
        $this->assertSame('21', $next['revision_no']);
        $this->assertSame('shadow', DB::table('tx_plan_offer_availability')->where('offer_id', $first['offers'][0]['offer_id'])->value('status'));
        $this->reject(fn () => $this->service()->setAvailability((int) $first['offers'][0]['offer_id'], $this->flags(), 1), 'PUBLICATION_OFFER_NOT_PUBLISHED');
    }

    public function test_availability_has_optimistic_versions_and_cannot_reactivate_superseded_offer(): void
    {
        $id = $this->plan();
        $first = $this->service()->publish($id, 'first');
        $offer = (int) $first['offers'][0]['offer_id'];
        $updated = $this->service()->setAvailability($offer, $this->flags(), 1);
        $this->assertSame('2', $updated['row_version']);
        $this->reject(fn () => $this->service()->setAvailability($offer, $this->flags(), 1), 'PUBLICATION_AVAILABILITY_VERSION_CONFLICT');
        $this->service()->publish($id, 'second');
        $this->reject(fn () => $this->service()->setAvailability($offer, $this->flags(), 3), 'PUBLICATION_OFFER_NOT_PUBLISHED');
    }

    public function test_availability_rejects_partial_unknown_or_coerced_fields(): void
    {
        foreach ([
            ['show' => true], $this->flags() + ['status' => 'published'],
            array_replace($this->flags(), ['sell' => 1]),
            array_replace($this->flags(), ['capacity_limit' => -1]),
            array_replace($this->flags(), ['capacity_limit' => '4294967296']),
            array_replace($this->flags(), ['capacity_limit' => 1.5]),
        ] as $input) {
            $this->reject(fn () => $this->service()->setAvailability(1, $input, 1));
        }
        $this->assertSame(0, $this->admissions);
    }

    public function test_invalid_plan_values_leave_no_catalog_rows(): void
    {
        foreach ([
            ['transfer_enable' => null], ['transfer_enable' => -1],
            ['transfer_enable' => (string) (intdiv(PHP_INT_MAX, 1073741824) + 1)],
            ['speed_limit' => '4294967296'], ['group_id' => -1],
            ['reset_traffic_method' => 5], ['prices' => '{"monthly":0.291}'],
            ['prices' => '{"monthly":-1}'], ['prices' => '{"monthly":true}'],
            ['prices' => '{"monthly":1,"monthly":2}'], ['prices' => '{"monthly":1,"unknown":null}'],
            ['prices' => '{"monthly":1e128}'], ['prices' => '{"monthly":null}'],
            ['prices' => '[]'], ['tags' => '{"not":"a-list"}'],
        ] as $index => $overrides) {
            $id = $this->plan($overrides);
            $this->reject(fn () => $this->service()->publish($id, 'invalid-'.$index));
            $this->assertSame(0, PlanRevision::query()->where('plan_id', $id)->count());
            $this->assertSame(0, PlanOffer::query()->where('plan_id', $id)->count());
        }
    }

    public function test_explicit_zero_price_and_zero_quota_are_not_coerced_to_unlimited(): void
    {
        $id = $this->plan(['transfer_enable' => 0, 'prices' => '{"monthly":0}']);
        $result = $this->service()->publish($id, 'zero');
        $this->assertSame('0', PlanRevision::query()->firstOrFail()->quota_bytes);
        $this->assertSame('0', $result['offers'][0]['price_minor']);
    }

    public function test_invalid_identity_currency_and_timezone_are_rejected_before_admission(): void
    {
        foreach ([
            [0, 'first', 'CNY', 'UTC'], [1, '', 'CNY', 'UTC'],
            [1, str_repeat('a', 65), 'CNY', 'UTC'], [1, 'has spaces', 'CNY', 'UTC'],
            [1, 'first', 'cny', 'UTC'], [1, 'first', 'JPY', 'UTC'],
            [1, 'first', 'CNY', '+08:00'],
        ] as $arguments) {
            $this->reject(fn () => $this->service()->publish(...$arguments));
        }
        $this->assertSame(0, $this->admissions);
        $this->reject(fn () => $this->service()->publish(999, 'missing'), 'PUBLICATION_PLAN_MISSING');
        $this->assertSame(1, $this->admissions);
    }

    public function test_authority_refusal_precedes_plan_reads_or_writes_for_both_mutations(): void
    {
        $this->denyAuthority = true;
        $this->reject(fn () => $this->service()->publish(999, 'denied'), 'INSTALLATION_CATALOG_MUTATION_REFUSED');
        $this->reject(fn () => $this->service()->setAvailability(999, $this->flags(), 1), 'INSTALLATION_CATALOG_MUTATION_REFUSED');
        $this->assertSame(2, $this->admissions);
        $this->assertSame(0, PlanRevision::query()->count());
    }

    public function test_damaged_stored_offer_or_revision_is_not_accepted_as_replay(): void
    {
        $id = $this->plan();
        $first = $this->service()->publish($id, 'first');
        DB::table('tx_plan_offer')->where('id', $first['offers'][0]['offer_id'])->update(['price_minor' => 999]);
        $this->reject(fn () => $this->service()->publish($id, 'first'), 'PUBLICATION_EVIDENCE_DAMAGED');
        DB::table('tx_plan_revision')->where('id', $first['revision_id'])->update(['source_snapshot' => '{}']);
        $this->reject(fn () => $this->service()->publish($id, 'first'), 'PUBLICATION_EVIDENCE_DAMAGED');
        $this->assertSame(1, PlanRevision::query()->count());
    }

    public function test_availability_refuses_damaged_revision_selected_offer_or_sibling_without_changing_state(): void
    {
        foreach (['snapshot', 'quota', 'reset', 'selected_price', 'selected_calendar', 'sibling_price'] as $damage) {
            $id = $this->plan(['prices' => '{"monthly":12.34,"yearly":100}', 'sell' => false]);
            $publication = $this->service()->publish($id, 'damage-'.$damage);
            $offer = (int) $publication['offers'][0]['offer_id'];
            $sibling = (int) $publication['offers'][1]['offer_id'];
            $before = DB::table('tx_plan_offer_availability')->where('offer_id', $offer)->first();
            match ($damage) {
                'snapshot' => DB::table('tx_plan_revision')->where('id', $publication['revision_id'])->update(['source_snapshot' => '{}']),
                'quota' => DB::table('tx_plan_revision')->where('id', $publication['revision_id'])->update(['quota_bytes' => 1]),
                'reset' => DB::table('tx_plan_revision')->where('id', $publication['revision_id'])->update([
                    'reset_policy' => '{"revision":"purchased-reset/v1","method":4,"timezone":"UTC"}',
                ]),
                'selected_price' => DB::table('tx_plan_offer')->where('id', $offer)->update(['price_minor' => 1]),
                'selected_calendar' => DB::table('tx_plan_offer')->where('id', $offer)->update([
                    'calendar_policy' => '{"timezone":"UTC","month_overflow":null}',
                ]),
                'sibling_price' => DB::table('tx_plan_offer')->where('id', $sibling)->update(['price_minor' => 1]),
            };
            $this->reject(fn () => $this->service()->setAvailability($offer, $this->flags(), 1), 'PUBLICATION_EVIDENCE_DAMAGED');
            $this->assertEquals($before, DB::table('tx_plan_offer_availability')->where('offer_id', $offer)->first());
            $this->assertSame(0, (int) DB::table('tx_plan_offer_availability')->where('offer_id', $offer)->value('sell'));
            $this->assertSame(1, (int) DB::table('tx_plan_offer_availability')->where('offer_id', $sibling)->value('row_version'));
        }
    }

    public function test_json_object_key_reordering_does_not_invalidate_replay_or_availability(): void
    {
        $publication = $this->service()->publish($this->plan(), 'json-order');
        $revision = DB::table('tx_plan_revision')->where('id', $publication['revision_id'])->first();
        $reorder = function (array $value) use (&$reorder): array {
            foreach ($value as &$child) {
                if (is_array($child)) {
                    $child = $reorder($child);
                }
            }
            return array_is_list($value) ? $value : array_reverse($value, true);
        };
        DB::table('tx_plan_revision')->where('id', $revision->id)->update([
            'source_snapshot' => json_encode($reorder(json_decode($revision->source_snapshot, true, flags: JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR),
            'reset_policy' => json_encode($reorder(json_decode($revision->reset_policy, true, flags: JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR),
        ]);
        $offer = DB::table('tx_plan_offer')->where('plan_revision_id', $revision->id)->first();
        DB::table('tx_plan_offer')->where('id', $offer->id)->update([
            'calendar_policy' => json_encode($reorder(json_decode($offer->calendar_policy, true, flags: JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame($publication, $this->service()->publish((int) $publication['plan_id'], 'json-order'));
        $updated = $this->service()->setAvailability((int) $offer->id, $this->flags(), 1);
        $this->assertSame('2', $updated['row_version']);
    }

    public function test_outer_transaction_rollback_preserves_previous_publication_and_availability(): void
    {
        $id = $this->plan();
        $first = $this->service()->publish($id, 'first');
        $this->reject(function () use ($id): void {
            DB::transaction(function () use ($id): void {
                $this->service()->publish($id, 'rolled-back');
                throw new RuntimeException('rollback');
            });
        }, 'rollback');
        $this->assertSame(1, PlanRevision::query()->count());
        $this->assertSame('published', DB::table('tx_plan_offer_availability')->where('offer_id', $first['offers'][0]['offer_id'])->value('status'));
        $this->assertSame(1, (int) DB::table('tx_plan_offer_availability')->value('row_version'));
    }

    public function test_native_records_retain_append_only_model_protection(): void
    {
        $this->service()->publish($this->plan(), 'first');
        foreach ([PlanRevision::query()->firstOrFail(), PlanOffer::query()->firstOrFail()] as $record) {
            $record->content_hash = str_repeat('b', 64);
            try {
                $record->save();
                $this->fail('Native catalog history must be immutable.');
            } catch (LogicException $error) {
                $this->assertStringContainsString('immutable', $error->getMessage());
            }
        }
    }

    private function service(): PlanPublicationService
    {
        return $this->app->make(PlanPublicationService::class);
    }

    private function plan(array $overrides = []): int
    {
        return DB::table('tx_plan')->insertGetId(array_replace([
            'name' => 'Native plan', 'group_id' => 1, 'transfer_enable' => 2,
            'speed_limit' => 100, 'device_limit' => 2, 'reset_traffic_method' => 0,
            'prices' => '{"monthly":"12.34"}', 'show' => true, 'sell' => true, 'renew' => true,
            'capacity_limit' => null, 'content' => 'Frozen native catalog', 'tags' => '["native"]',
            'created_at' => time(), 'updated_at' => time(),
        ], $overrides));
    }

    private function flags(): array
    {
        return ['show' => true, 'sell' => true, 'renew' => true, 'capacity_limit' => null];
    }

    private function reject(callable $call, ?string $message = null): void
    {
        try {
            $call();
        } catch (RuntimeException $error) {
            if ($message !== null) {
                $this->assertSame($message, $error->getMessage());
            }
            return;
        }
        $this->fail('Expected a rejected catalog mutation.');
    }
}
