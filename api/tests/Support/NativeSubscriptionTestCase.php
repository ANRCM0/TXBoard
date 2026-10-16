<?php

namespace Tests\Support;

use App\Domains\Installation\InstallationAuthority;
use App\Models\Plan;
use App\Models\User;
use App\Domains\Subscription\Services\PlanPublicationService;
use App\Support\Setting as RuntimeSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** Real domain schemas and transactions; only the not-yet-activatable admission is substituted. */
abstract class NativeSubscriptionTestCase extends TestCase
{
    protected const INSTALLATION = '11111111-1111-4111-8111-111111111111';
    protected array $settings = [];
    protected bool $denyNativeAdmission = false;
    protected int $nativeAdmissions = 0;
    private bool $mysqlFixture = false;
    private int $fixtureSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mysqlFixture = getenv('CORE_NATIVE_TEST_MYSQL') === '1';
        if ($this->mysqlFixture) {
            $this->assertDedicatedMysql();
            Schema::dropAllTables();
        } else {
            config([
                'database.default' => 'native_subscription_testing',
                'database.connections.native_subscription_testing' => array_merge(config('database.connections.sqlite'), [
                    'database' => ':memory:', 'foreign_key_constraints' => true,
                ]),
            ]);
            DB::purge('native_subscription_testing');
        }
        config(['app.timezone' => 'UTC', 'cache.stores.redis' => ['driver' => 'array']]);
        Bus::fake();
        $this->sourceTables();
        foreach ([
            '2026_10_11_000001_create_idempotency_keys.php',
            '2026_10_12_000001_create_subscription_shadow_tables.php',
            '2026_10_12_000002_create_plan_catalog_shadow_tables.php',
            '2026_10_14_000001_create_authority_writer_fence.php',
            '2026_10_15_000001_create_order_purchase_terms.php',
            '2026_10_15_000002_extend_installation_authority.php',
            '2026_10_15_000003_enable_plan_publication.php',
            '2026_10_15_000004_enable_native_subscriptions.php',
            '2026_10_16_000004_create_native_money_ledger.php',
            '2026_10_16_000005_create_native_payment_attempts.php',
            '2026_10_16_000007_create_native_payment_settlement.php',
            '2026_10_16_000009_create_native_wallet_reservations.php',
            '2026_10_16_000016_create_native_free_checkout.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $authority = Mockery::mock(InstallationAuthority::class);
        $admit = function (): array {
            $this->assertGreaterThan(0, DB::transactionLevel());
            $this->nativeAdmissions++;
            if ($this->denyNativeAdmission) {
                throw new RuntimeException('INSTALLATION_NATIVE_NOT_READY');
            }
            return ['installation_id' => self::INSTALLATION, 'authority_epoch' => '1'];
        };
        $authority->shouldReceive('assertNativeMutation')->andReturnUsing($admit);
        $authority->shouldReceive('assertNativeRead')->andReturnUsing($admit);
        $authority->shouldReceive('assertCatalogMutation')->andReturnUsing(function (): void {
            $this->assertGreaterThan(0, DB::transactionLevel());
        });
        $authority->shouldReceive('inspect')->byDefault()->andReturn([
            'initialized' => true, 'mode' => 'native', 'phase' => 'open',
            'installation_id' => self::INSTALLATION, 'authority_epoch' => '1',
        ]);
        $this->app->instance(InstallationAuthority::class, $authority);
        $setting = Mockery::mock(RuntimeSetting::class);
        $setting->shouldReceive('get')->andReturnUsing(
            fn (string $key, mixed $default = null): mixed => $this->settings[$key] ?? $default,
        );
        $this->app->instance(RuntimeSetting::class, $setting);
    }

    protected function tearDown(): void
    {
        if ($this->mysqlFixture) {
            $this->assertDedicatedMysql();
            Schema::dropAllTables();
        } else {
            DB::purge('native_subscription_testing');
        }
        parent::tearDown();
    }

    protected function user(array $overrides = []): User
    {
        return User::withoutEvents(fn (): User => User::query()->create(array_replace([
            'email' => 'native-'.(++$this->fixtureSequence).'@example.test', 'password' => 'not-a-real-password',
            'token' => 'fixture-legacy-token-'.$this->fixtureSequence,
            'uuid' => sprintf('22222222-2222-4222-8222-%012d', $this->fixtureSequence),
            'banned' => false, 'is_admin' => false, 'plan_id' => null, 'group_id' => null,
            'transfer_enable' => 0, 'u' => 0, 'd' => 0, 'expired_at' => null,
            'balance' => 0, 'commission_balance' => 0,
        ], $overrides)));
    }

    protected function plan(array $overrides = []): Plan
    {
        return Plan::withoutEvents(fn (): Plan => Plan::query()->create(array_replace([
            'name' => 'Native fixture plan', 'group_id' => 7, 'transfer_enable' => 2,
            'speed_limit' => 30, 'device_limit' => 4, 'reset_traffic_method' => 2,
            'prices' => ['monthly' => '12.34', 'yearly' => '120.00', 'onetime' => '99.00', 'reset_traffic' => '2.00'],
            'show' => true, 'sell' => true, 'renew' => true, 'capacity_limit' => null,
            'content' => 'Published native fixture', 'tags' => ['native'], 'sort' => 0,
        ], $overrides)));
    }

    protected function publishedOffer(Plan $plan, string $period = 'monthly', string $key = 'first'): int
    {
        $published = app(PlanPublicationService::class)->publish((int) $plan->id, $key, 'USD', 'UTC');
        foreach ($published['offers'] as $offer) {
            if ($offer['offer_code'] === $period) {
                return (int) $offer['offer_id'];
            }
        }
        throw new RuntimeException('TEST_PUBLISHED_OFFER_MISSING');
    }

    private function assertDedicatedMysql(): void
    {
        if (DB::getDriverName() !== 'mysql'
            || !preg_match('/\Atxboard_c2_native_test(?:_[a-z0-9_]+)?\z/D', DB::connection()->getDatabaseName())) {
            throw new RuntimeException('Native MySQL fixtures require a dedicated txboard_c2_native_test database.');
        }
    }

    private function sourceTables(): void
    {
        Schema::create('tx_user', function (Blueprint $table): void {
            $table->integer('id', true);
            $table->string('email')->unique();
            $table->string('password');
            $table->string('token');
            $table->string('uuid');
            $table->boolean('banned')->default(false);
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_staff')->default(false);
            foreach (['plan_id', 'group_id', 'speed_limit', 'device_limit', 'discount', 'invite_user_id'] as $field) {
                $table->integer($field)->nullable();
            }
            foreach (['transfer_enable', 'u', 'd', 'balance', 'commission_balance', 'reset_count'] as $field) {
                $table->unsignedBigInteger($field)->default(0);
            }
            foreach (['expired_at', 'last_reset_at', 'next_reset_at', 'last_login_at'] as $field) {
                $table->unsignedBigInteger($field)->nullable();
            }
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
        });
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
            $table->integer('sort')->default(0);
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
        });
        Schema::create('tx_order', function (Blueprint $table): void {
            $table->integer('id', true);
            $table->integer('user_id');
            $table->integer('plan_id');
            $table->string('period');
            $table->string('trade_no')->unique();
            $table->unsignedTinyInteger('type');
            $table->unsignedTinyInteger('status')->default(0);
            $table->integer('total_amount');
            foreach (['handling_amount', 'balance_amount', 'surplus_credit', 'surplus_amount', 'discount_amount',
                'commission_balance', 'actual_commission_balance', 'commission_rate', 'commission_status'] as $field) {
                $table->integer($field)->default(0);
            }
            foreach (['payment_id', 'coupon_id', 'invite_user_id'] as $field) {
                $table->integer($field)->nullable();
            }
            $table->json('surplus_order_ids')->nullable();
            $table->string('callback_no')->nullable();
            $table->unsignedBigInteger('paid_at')->nullable();
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
        });
        Schema::create('tx_payment', function (Blueprint $table): void {
            $table->integer('id', true);
            $table->char('uuid', 32);
            $table->string('payment', 16);
            $table->string('name');
            $table->text('icon')->nullable();
            $table->text('config');
            $table->string('notify_domain', 128)->nullable();
            $table->integer('handling_fee_fixed')->nullable();
            $table->decimal('handling_fee_percent', 5)->nullable();
            $table->boolean('enable')->default(false);
            $table->integer('sort')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }
}
