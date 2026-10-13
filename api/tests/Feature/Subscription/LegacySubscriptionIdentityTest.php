<?php

namespace Tests\Feature\Subscription;

use App\Http\Controllers\SubscriptionController;
use App\Http\Middleware\SubscriptionIdentityShadow;
use App\Http\Middleware\LegacySubscriptionLinkShadow;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\Subscription\LegacySubscriptionIdentityReader;
use App\Services\Subscription\LegacySubscriptionSnapshot;
use App\Services\Subscription\SubscriptionShadowBackfill;
use App\Utils\Helper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LegacySubscriptionIdentityTest extends TestCase
{
    private int $sequence = 0;
    private ?array $observation = null;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->mysql()) {
            config(['database.default' => 'identity_mysql_testing',
                'database.connections.identity_mysql_testing' => config('database.connections.mysql')]);
            DB::purge('identity_mysql_testing');
            $this->clearMysqlFixtures();
        } else {
            config(['database.default' => 'identity_sqlite_testing', 'database.connections.identity_sqlite_testing' =>
                array_merge(config('database.connections.sqlite'), ['database' => ':memory:', 'foreign_key_constraints' => true])]);
            DB::purge('identity_sqlite_testing');
        }
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        config(['core_shadow.isolated_copy' => true, 'core_shadow.subscription_identity_read' => true,
            'cache.stores.redis' => ['driver' => 'array']]);
        admin_setting(['subscribe_url' => '', 'show_info_to_server_enable' => false,
            'show_protocol_to_server_enable' => false, 'app_name' => 'Identity Fixture']);
        $this->plan = Plan::create(['name' => 'identity-plan', 'group_id' => 1, 'transfer_enable' => 1,
            'prices' => ['monthly' => 2], 'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY]);
        $this->server();
        HookManager::register('client.subscribe.before', function (): void {
            $this->observation = request()->attributes->get(LegacySubscriptionIdentityReader::ATTRIBUTE);
        });
    }

    protected function tearDown(): void
    {
        if ($this->mysql()) {
            $this->clearMysqlFixtures();
            DB::purge('identity_mysql_testing');
        } else {
            DB::purge('identity_sqlite_testing');
        }
        parent::tearDown();
    }

    public function test_real_general_link_is_identical_when_disabled_mapped_or_missing_and_reads_never_rotate(): void
    {
        $user = $this->user();
        config(['core_shadow.subscription_identity_read' => false]);
        $before = $this->render($user);
        $this->assertNull($this->observation);
        $this->backfill();
        $saved = $this->shadowSnapshot();
        $source = $user->fresh()->getRawOriginal();
        config(['core_shadow.subscription_identity_read' => true]);
        $after = $this->render($user);
        $this->assertSame($before, $after);
        $this->assertSame('mapped', $this->observation['status']);
        $this->assertFalse($this->observation['authorizes']);
        $this->assertSame('legacy', $this->observation['authority']);
        $this->assertSame($saved, $this->shadowSnapshot());
        $this->assertSame($source, $user->fresh()->getRawOriginal());
        $other = $this->user();
        $this->render($other);
        $this->assertSame('IDENTITY_MAPPING_MISSING', $this->observation['error_code']);
    }

    public function test_disabled_native_request_and_direct_guard_do_not_query_shadow_tables(): void
    {
        $user = $this->user();
        config(['core_shadow.subscription_identity_read' => false]);
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->render($user);
        $this->assertNull($this->observation);
        $this->assertSame([], array_filter(DB::getQueryLog(), fn ($q) => $this->shadowQuery($q['query'])));
        DB::flushQueryLog();
        $this->assertSame('IDENTITY_ISOLATED_COPY_REQUIRED', $this->reader()->forLegacyUser($user->id)['error_code']);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_unsafe_activation_and_existing_transaction_leave_native_rendering_unchanged(): void
    {
        $user = $this->user(); $this->backfill();
        $baseline = $this->render($user);
        config(['core_shadow.isolated_copy' => false]);
        $this->assertSame($baseline, $this->render($user));
        $this->assertSame('IDENTITY_ISOLATED_COPY_REQUIRED', $this->observation['error_code']);
        config(['core_shadow.isolated_copy' => true]);
        app()->instance('env', 'production');
        $this->assertSame($baseline, $this->render($user));
        $this->assertSame('IDENTITY_ISOLATED_COPY_REQUIRED', $this->observation['error_code']);
        app()->instance('env', 'testing');
        DB::beginTransaction();
        try {
            $this->assertSame($baseline, $this->render($user));
            $this->assertSame('IDENTITY_OUTER_TRANSACTION_UNSUPPORTED', $this->observation['error_code']);
        } finally { DB::rollBack(); }
    }

    public function test_query_token_precedence_is_preserved_and_invalid_native_tokens_never_call_reader(): void
    {
        $first = $this->user(); $second = $this->user(); $this->backfill();
        $response = $this->get($this->url($first).'?token='.$second->token, ['User-Agent' => 'v2rayn/6.40']);
        $response->assertOk();
        $this->assertStringContainsString($second->uuid, $this->generalDecoded($response->getContent()));
        $this->assertStringNotContainsString($first->uuid, $this->generalDecoded($response->getContent()));
        $this->assertSame((string) $second->id, $this->observation['legacy_user_id']);
        $this->observation = null;
        $this->get($this->url($first).'?token=wrong-native-token')->assertStatus(403);
        $this->assertNull($this->observation);
        $this->get(route('client.subscribe', ['token' => 'wrong-native-token'], false))->assertStatus(403);
        $this->assertNull($this->observation);
        $this->assertSame(403, $this->get($this->url($first).'?token=')->status());
        $this->assertNull($this->observation);
    }

    public function test_native_subscription_availability_is_not_reinterpreted_as_shadow_authorization(): void
    {
        foreach ([['banned' => true], ['expired_at' => time() - 1], ['transfer_enable' => 0]] as $overrides) {
            $user = $this->user($overrides);
            config(['core_shadow.subscription_identity_read' => false]);
            $before = $this->render($user, expected: 403);
            $this->backfill(); config(['core_shadow.subscription_identity_read' => true]);
            $this->assertSame($before, $this->render($user, expected: 403));
            $this->assertFalse($this->observation['authorizes']);
        }
        foreach ([['u' => 100000, 'd' => 100000], ['plan_id' => null, 'expired_at' => null]] as $overrides) {
            $user = $this->user($overrides); $this->backfill();
            $this->render($user);
            $this->assertSame('mapped', $this->observation['status']);
            $this->assertFalse($this->observation['authorizes']);
        }
    }

    public function test_real_clashmeta_singbox_and_surge_preserve_original_config_and_headers(): void
    {
        $user = $this->user(); $this->backfill();
        foreach (['clash.meta/1.19', 'sing-box/1.10.0', 'surge/5.9'] as $agent) {
            config(['core_shadow.subscription_identity_read' => false]);
            $before = $this->render($user, $agent);
            config(['core_shadow.subscription_identity_read' => true]);
            $this->assertSame($before, $this->render($user, $agent));
            $this->assertSame('mapped', $this->observation['status']);
            $this->assertStringContainsString($user->uuid, $before['body']);
            $this->assertStringNotContainsString('subscription_id', $before['body']);
            if (str_starts_with($agent, 'surge')) {
                $this->assertStringContainsString($user->token, $before['body']);
            } else {
                $this->assertStringContainsString('upload=111; download=222; total=10000', $before['headers']['subscription-userinfo']);
            }
        }
    }

    public function test_real_types_filters_and_hidden_server_selection_stay_native(): void
    {
        $user = $this->user(); $this->backfill();
        $this->server(['type' => Server::TYPE_TROJAN, 'name' => 'Secondary', 'tags' => ['selected']]);
        $this->server(['name' => 'Hidden', 'show' => false]);
        $this->server(['name' => 'Foreign', 'group_ids' => ['2']]);
        foreach (['types=vmess&filter=primary', 'types=trojan&filter=selected', 'types=all&filter=missing'] as $query) {
            config(['core_shadow.subscription_identity_read' => false]);
            $before = $this->render($user, query: $query);
            config(['core_shadow.subscription_identity_read' => true]);
            $this->assertSame($before, $this->render($user, query: $query));
            $this->assertStringNotContainsString('Hidden', base64_decode($before['body']));
            $this->assertStringNotContainsString('Foreign', base64_decode($before['body']));
        }
        $this->assertStringContainsString('trojan://', base64_decode($this->render($user, query: 'types=trojan&filter=selected')['body']));
        $this->assertSame('', $this->render($user, query: 'filter=missing')['body']);
    }

    public function test_remaining_native_renderers_and_ss2022_derived_password_preserve_real_link_output(): void
    {
        $user = $this->user(); $this->backfill();
        $server = $this->server(['type' => Server::TYPE_SHADOWSOCKS, 'name' => 'SS2022',
            'protocol_settings' => ['cipher' => '2022-blake3-aes-128-gcm']]);
        $password = $server->generateServerPassword($user);
        $this->assertNotSame($user->uuid, $password);
        $this->assertStringContainsString(':', $password);
        foreach (['clash/1.18', 'stash/2.6', 'loon/3.2', 'quantumult-x/1.4', 'shadowrocket/2.2',
            'surfboard/2.23', 'shadowsocks/4.4'] as $agent) {
            config(['core_shadow.subscription_identity_read' => false]);
            $before = $this->render($user, $agent);
            config(['core_shadow.subscription_identity_read' => true]);
            $this->assertSame($before, $this->render($user, $agent));
            $this->assertSame('mapped', $this->observation['status']);
            $this->assertNotSame('', $before['body']);
        }
        // Explicit flag overrides UA through the actual controller, as before.
        $overridden = $this->render($user, 'sing-box/1.10.0', 'flag=v2rayn');
        $this->assertStringContainsString('vmess://', base64_decode($overridden['body']));
        $this->assertStringNotContainsString($password, json_encode($this->observation));
    }

    public function test_duplicate_token_or_uuid_blocks_only_observation_without_disclosing_credentials(): void
    {
        $user = $this->user(); $this->backfill(); $baseline = $this->render($user);
        $duplicate = $this->user(['token' => $user->token]);
        $this->assertSame($baseline, $this->render($user));
        $this->assertSame('IDENTITY_TOKEN_AMBIGUOUS', $this->observation['error_code']);
        $duplicate->update(['token' => str_repeat('f', 32), 'uuid' => $user->uuid]);
        $this->assertSame($baseline, $this->render($user));
        $this->assertSame('IDENTITY_UUID_AMBIGUOUS', $this->observation['error_code']);
        foreach ([$user->token, $user->uuid, $duplicate->token, 'provider_subject_ref', 'source_hash'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($this->observation));
        }
        $this->assertFalse($this->observation['authorizes']);
    }

    public function test_missing_uuid_or_token_and_missing_user_fail_closed_with_fixed_shape(): void
    {
        $user = $this->user(); $this->backfill(); $baseline = $this->render($user);
        $user->update(['uuid' => '']);
        $this->assertSame('IDENTITY_CREDENTIAL_MISSING', $this->reader()->forLegacyUser($user->id)['error_code']);
        $this->render($user);
        $this->assertSame('IDENTITY_CREDENTIAL_MISSING', $this->observation['error_code']);
        $user->update(['token' => '']);
        $this->assertSame('IDENTITY_CREDENTIAL_MISSING', $this->reader()->forLegacyUser($user->id)['error_code']);
        $this->assertSame('IDENTITY_USER_MISSING', $this->reader()->forLegacyUser(999999)['error_code']);
        $this->assertSame('IDENTITY_USER_INVALID', $this->reader()->forLegacyUser(0)['error_code']);
        $this->get(route('client.subscribe', ['token' => sprintf('%032d', 1)], false))->assertStatus(403);
    }

    public function test_unverified_map_unresolved_exception_and_invalid_evidence_do_not_change_live_response(): void
    {
        $user = $this->user(); $this->backfill(); $baseline = $this->render($user);
        $original = (array) DB::table('tx_legacy_subscription_map')->first();
        foreach ([['state' => 'source_changed'], ['migration_key' => 'wrong'], ['source_hash' => 'bad'],
            ['run_key' => '../bad'], ['row_version' => 0], ['error_code' => 'LEGACY_SOURCE_CHANGED']] as $damage) {
            DB::table('tx_legacy_subscription_map')->where('id', $original['id'])->update($damage);
            $this->assertSame($baseline, $this->render($user));
            $this->assertSame('blocked', $this->observation['status']);
            DB::table('tx_legacy_subscription_map')->where('id', $original['id'])->update($original);
        }
        DB::table('tx_legacy_subscription_exception')->insert(['legacy_user_id' => $user->id, 'run_key' => 'identity',
            'error_code' => 'LEGACY_PLAN_MISSING', 'attempts' => 1, 'state' => 'unresolved', 'created_at' => time(), 'updated_at' => time()]);
        $this->assertSame($baseline, $this->render($user));
        $this->assertSame('IDENTITY_EXCEPTION_UNRESOLVED', $this->observation['error_code']);
        DB::table('tx_legacy_subscription_exception')->where('legacy_user_id', $user->id)->update(['state' => 'resolved']);
        $this->render($user); $this->assertSame('mapped', $this->observation['status']);
    }

    public function test_foreign_provider_or_subject_reference_never_maps_a_legacy_identity(): void
    {
        $user = $this->user(); $this->backfill(); $baseline = $this->render($user);
        $subject = (array) DB::table('tx_access_subject')->first();
        foreach ([['provider_id' => 'fixture-synthetic'], ['provider_subject_ref' => 'user:999']] as $damage) {
            DB::table('tx_access_subject')->where('id', $subject['id'])->update($damage);
            $this->assertSame($baseline, $this->render($user));
            $this->assertSame('IDENTITY_PROJECTION_OWNER_INVALID', $this->observation['error_code']);
            DB::table('tx_access_subject')->where('id', $subject['id'])->update($subject);
        }
    }

    public function test_cross_subscription_map_and_period_ownership_block_without_loading_payloads(): void
    {
        $user = $this->user(); $other = $this->user(); $this->backfill();
        $baseline = $this->render($user);
        $original = (array) DB::table('tx_legacy_subscription_map')->where('legacy_user_id', $user->id)->first();
        $foreign = (array) DB::table('tx_legacy_subscription_map')->where('legacy_user_id', $other->id)->first();
        foreach (['terms_id', 'usage_period_id', 'access_subject_id'] as $field) {
            Schema::disableForeignKeyConstraints();
            try { DB::table('tx_legacy_subscription_map')->where('id', $original['id'])->update([$field => $foreign[$field]]); }
            finally { Schema::enableForeignKeyConstraints(); }
            $this->assertSame($baseline, $this->render($user));
            $this->assertSame('IDENTITY_PROJECTION_OWNER_INVALID', $this->observation['error_code']);
            DB::table('tx_legacy_subscription_map')->where('id', $original['id'])->update($original);
        }
        Schema::disableForeignKeyConstraints();
        try { DB::table('tx_subscription')->where('id', $original['subscription_id'])->update(['user_id' => $other->id]); }
        finally { Schema::enableForeignKeyConstraints(); }
        $this->assertSame($baseline, $this->render($user));
        $this->assertSame('IDENTITY_SUBSCRIPTION_OWNER_INVALID', $this->observation['error_code']);
    }

    public function test_slot_conflict_is_reported_without_creating_reclaiming_or_altering_a_slot(): void
    {
        $user = $this->user(); $this->backfill(); $baseline = $this->render($user);
        $other = DB::table('tx_subscription')->insertGetId(['user_id' => $user->id, 'status' => 'pending',
            'starts_at' => time(), 'row_version' => 1, 'created_at' => time(), 'updated_at' => time()]);
        DB::table('tx_subscription_slot')->where('user_id', $user->id)->update(['subscription_id' => $other]);
        $saved = $this->shadowSnapshot();
        $this->assertSame($baseline, $this->render($user));
        $this->assertSame('IDENTITY_SLOT_CONFLICT', $this->observation['error_code']);
        $this->assertSame($saved, $this->shadowSnapshot());
    }

    public function test_shadow_terms_subject_status_and_live_quota_changes_do_not_supply_a_second_authority(): void
    {
        $user = $this->user(); $this->backfill();
        DB::table('tx_subscription_terms')->update(['quota_bytes' => 1]);
        DB::table('tx_access_subject')->update(['status' => 'revoked', 'revoked_at' => time()]);
        $user->update(['transfer_enable' => 25000]);
        $config = $this->render($user);
        $this->assertStringContainsString('total=25000', $config['headers']['subscription-userinfo']);
        $this->assertSame('mapped', $this->observation['status']);
        $this->assertFalse($this->observation['authorizes']);
    }

    public function test_reader_is_bounded_select_only_and_emits_no_secret_binding_payload_or_log(): void
    {
        $user = $this->user(); $this->backfill(); $saved = $this->shadowSnapshot();
        DB::enableQueryLog(); DB::flushQueryLog(); Log::spy();
        $result = $this->reader()->forLegacyUser($user->id);
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertSame('mapped', $result['status']);
        $this->assertLessThanOrEqual(12, count($queries));
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select /i', $query['query']);
            $this->assertStringNotContainsString('select *', strtolower($query['query']));
            foreach (['source_snapshot', 'legacy_snapshot', 'tx_order', 'content_hash'] as $payload) {
                $this->assertStringNotContainsString($payload, $query['query']);
            }
            foreach ([$user->token, $user->uuid, $user->password, $user->email] as $secret) {
                $this->assertStringNotContainsString($secret, json_encode($query));
                $this->assertStringNotContainsString($secret, json_encode($result));
            }
        }
        $this->assertSame($saved, $this->shadowSnapshot());
        foreach (['debug', 'info', 'warning', 'error', 'log'] as $method) { Log::shouldNotHaveReceived($method); }
    }

    public function test_failed_shadow_sql_does_not_leak_error_or_break_a_real_native_link(): void
    {
        $user = $this->user(); $baseline = $this->render($user);
        Schema::drop('tx_legacy_subscription_map'); Log::spy();
        $this->assertSame($baseline, $this->render($user));
        $this->assertSame('IDENTITY_READ_FAILED', $this->observation['error_code']);
        $this->assertSame(0, DB::transactionLevel());
        foreach (['debug', 'info', 'warning', 'error', 'log'] as $method) { Log::shouldNotHaveReceived($method); }
    }

    public function test_real_native_credential_rotation_invalidates_old_link_and_keeps_stable_mapping(): void
    {
        $user = $this->user(); $other = $this->user(); $this->backfill();
        $original = $this->render($user); $oldIdentity = $this->observation;
        $saved = $this->shadowSnapshot(); $oldUrl = $this->url($user); $oldUuid = $user->uuid;
        $otherCredentials = [$other->token, $other->uuid];
        Sanctum::actingAs($user);
        $this->getJson('/txapi/me/subscription-credentials/rotate')->assertStatus(404);
        $rotated = $this->postJson('/txapi/me/subscription-credentials/rotate');
        $rotated->assertOk(); $user->refresh();
        $this->assertNotSame($oldUrl, $this->url($user));
        $this->assertNotSame($oldUuid, $user->uuid);
        $this->assertStringContainsString($user->token, $rotated->json('data.subscribe_url'));
        $this->get($oldUrl)->assertStatus(403);
        $this->observation = null;
        $new = $this->render($user);
        $this->assertSame($oldIdentity, $this->observation);
        $this->assertStringContainsString($user->uuid, $this->generalDecoded($new['body']));
        $this->assertStringNotContainsString($oldUuid, $this->generalDecoded($new['body']));
        $this->assertNotSame($original['body'], $new['body']);
        $this->assertSame($saved, $this->shadowSnapshot());
        $this->assertSame($otherCredentials, [$other->fresh()->token, $other->fresh()->uuid]);
    }

    public function test_real_node_snapshot_keeps_legacy_uuid_and_never_uses_subscription_or_subject_as_credentials(): void
    {
        $user = $this->user(); $exhausted = $this->user(['u' => 10000]); $this->backfill();
        $node = Server::query()->first();
        admin_setting(['server_token' => 'identity-native-node-fixture']);
        $headers = ['Authorization' => 'Bearer identity-native-node-fixture', 'X-TX-Node-ID' => (string) $node->id];
        config(['core_shadow.subscription_identity_read' => false]);
        $before = $this->getJson('/txapi/node/v1/users', $headers)->assertOk()->json('data');
        config(['core_shadow.subscription_identity_read' => true]);
        $after = $this->getJson('/txapi/node/v1/users', $headers)->assertOk()->json('data');
        $this->assertSame($before, $after);
        $this->assertStringContainsString($user->uuid, json_encode($after));
        $this->assertStringNotContainsString($exhausted->uuid, json_encode($after));
        $this->assertStringNotContainsString('subscription_id', json_encode($after));
        $this->assertStringNotContainsString('access_subject_id', json_encode($after));
        Auth::forgetGuards();
        $this->assertSame(401, $this->getJson('/txapi/me', $headers)->status());
    }

    public function test_dynamic_subscription_path_and_original_controller_registration_are_preserved(): void
    {
        $user = $this->user(); $this->backfill();
        admin_setting(['subscribe_path' => 'private_fixture_links']);
        $this->assertSame('private_fixture_links', admin_setting('subscribe_path'));
        // Rebuild from the native source with the configured path, as at boot;
        // do not leave a second route with the same name in the registry.
        Route::setRoutes(new \Illuminate\Routing\RouteCollection());
        Route::middleware('web')->group(base_path('routes/web.php'));
        Route::getRoutes()->refreshNameLookups();
        app('url')->setRoutes(Route::getRoutes());
        $route = Route::getRoutes()->getByName('client.subscribe');
        $this->assertSame('private_fixture_links/{token}', $route->uri());
        $this->assertStringContainsString(SubscriptionController::class, $route->getActionName());
        $this->assertSame(['web', 'client', SubscriptionIdentityShadow::class, LegacySubscriptionLinkShadow::class], $route->gatherMiddleware());
        $url = Helper::getSubscribeUrl($user->token, 'https://links.fixture.invalid');
        $this->assertStringContainsString('/private_fixture_links/'.$user->token, $url);
        $this->get($url, ['User-Agent' => 'v2rayn/6.40'])->assertOk();
        $this->assertSame('mapped', $this->observation['status']);
    }

    public function test_independent_mysql_writer_commits_identity_collision_before_waiting_reader_proceeds(): void
    {
        if (!$this->mysql() || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires independent MariaDB sessions and pcntl.');
        }
        $user = $this->user(); $duplicate = $this->user(); $this->backfill();
        $saved = $this->shadowSnapshot();
        [$parentPipe, $childPipe] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        // No PDO/socket crosses the fork: each process opens its own connection.
        DB::purge();
        $pid = pcntl_fork();
        if ($pid === -1) { $this->fail('Could not fork independent reader.'); }
        if ($pid === 0) {
            fclose($parentPipe);
            stream_set_timeout($childPipe, 10);
            if (fread($childPipe, 1) !== 'S') { exit(2); }
            fwrite($childPipe, 'R');
            $result = $this->reader()->forLegacyUser($user->id);
            fwrite($childPipe, json_encode($result, JSON_THROW_ON_ERROR));
            fclose($childPipe); DB::disconnect(); exit(0);
        }
        fclose($childPipe); stream_set_timeout($parentPipe, 10);
        try {
            DB::beginTransaction();
            DB::table('tx_user')->where('id', $user->id)->lockForUpdate()->first(['id']);
            DB::table('tx_user')->where('id', $user->id)->update(['uuid' => $duplicate->uuid]);
            fwrite($parentPipe, 'S');
            $this->assertSame('R', fread($parentPipe, 1));
            $read = [$parentPipe]; $write = []; $except = [];
            $this->assertSame(0, stream_select($read, $write, $except, 0, 300000), 'Reader escaped the uncommitted User fence.');
            DB::commit();
            $payload = stream_get_contents($parentPipe, 4096);
            $result = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('IDENTITY_UUID_AMBIGUOUS', $result['error_code']);
            $this->assertFalse($result['authorizes']);
            $this->assertSame($saved, $this->shadowSnapshot());
            $this->assertStringNotContainsString($duplicate->uuid, $payload);
        } finally {
            if (DB::transactionLevel()) { DB::rollBack(); }
            fclose($parentPipe); pcntl_waitpid($pid, $status);
        }
        $this->assertSame(0, pcntl_wexitstatus($status));
    }

    public function test_stale_mysql_outer_snapshot_is_explicitly_rejected_after_another_session_changes_mapping(): void
    {
        if (!$this->mysql()) { $this->markTestSkipped('Requires independent MariaDB sessions.'); }
        $user = $this->user(); $this->backfill();
        $primary = DB::getDefaultConnection();
        config(['database.connections.identity_mysql_peer' => config('database.connections.'.$primary)]);
        DB::purge('identity_mysql_peer');
        DB::beginTransaction();
        try {
            $this->assertSame('mapped', DB::table('tx_legacy_subscription_map')->where('legacy_user_id', $user->id)->value('state'));
            DB::connection('identity_mysql_peer')->table('tx_legacy_subscription_map')->where('legacy_user_id', $user->id)
                ->update(['state' => 'source_changed']);
            DB::enableQueryLog(); DB::flushQueryLog();
            $this->assertSame('IDENTITY_OUTER_TRANSACTION_UNSUPPORTED', $this->reader()->forLegacyUser($user->id)['error_code']);
            $this->assertSame([], DB::getQueryLog());
        } finally { DB::rollBack(); DB::purge('identity_mysql_peer'); }
        $this->assertSame('IDENTITY_MAPPING_UNVERIFIED', $this->reader()->forLegacyUser($user->id)['error_code']);
    }

    private function user(array $overrides = []): User
    {
        $number = ++$this->sequence;
        return User::create(array_replace(['email' => 'identity-'.$number.'@example.test', 'password' => 'identity-fixture-password',
            'uuid' => sprintf('00000000-0000-4000-8000-%012d', $number), 'token' => sprintf('%032d', $number),
            'plan_id' => $this->plan->id, 'group_id' => 1, 'u' => 111, 'd' => 222,
            'transfer_enable' => 10000, 'expired_at' => time() + 86400, 'banned' => false], $overrides));
    }

    private function server(array $overrides = []): Server
    {
        return Server::create(array_replace(['name' => 'Primary', 'type' => Server::TYPE_VMESS,
            'host' => 'node.fixture.invalid', 'port' => '443', 'server_port' => 443, 'rate' => 1,
            'group_ids' => ['1'], 'enabled' => true, 'show' => true,
            'protocol_settings' => ['network' => 'tcp', 'tls' => 0], 'sort' => 1], $overrides));
    }

    private function backfill(): void
    {
        app(SubscriptionShadowBackfill::class)->run('identity-'.$this->sequence, 100,
            app(LegacySubscriptionSnapshot::class)->context('UTC', 'USD'));
    }

    private function render(User $user, string $agent = 'v2rayn/6.40', string $query = '', int $expected = 200): array
    {
        $this->observation = null;
        Auth::forgetGuards();
        $response = $this->get($this->url($user).($query === '' ? '' : '?'.$query), ['User-Agent' => $agent]);
        $response->assertStatus($expected);
        $headers = [];
        foreach (['content-type', 'subscription-userinfo', 'profile-title', 'profile-update-interval', 'content-disposition'] as $name) {
            $headers[$name] = $response->headers->get($name);
        }
        return ['status' => $response->status(), 'body' => $response->getContent(), 'headers' => $headers];
    }

    private function url(User $user): string { return route('client.subscribe', ['token' => $user->token], false); }
    private function generalDecoded(string $body): string
    {
        return preg_replace_callback('~vmess://([A-Za-z0-9+/=]+)~',
            fn ($match) => 'vmess://'.base64_decode($match[1]), base64_decode($body));
    }
    private function reader(): LegacySubscriptionIdentityReader { return app(LegacySubscriptionIdentityReader::class); }
    private function mysql(): bool { return getenv('CORE_IDENTITY_TEST_MYSQL') === '1'; }
    private function shadowQuery(string $sql): bool { return (bool) preg_match('/tx_(subscription|usage_|access_subject|legacy_subscription|migration_checkpoint)/', $sql); }

    private function shadowSnapshot(): array
    {
        $result = [];
        foreach (Schema::getTableListing() as $table) {
            if ($this->shadowQuery($table)) {
                $columns = Schema::getColumnListing($table);
                $key = in_array('id', $columns, true) ? 'id' : $columns[0];
                $result[$table] = DB::table($table)->orderBy($key)->get()->map(fn ($row) => (array) $row)->all();
            }
        }
        ksort($result); return $result;
    }

    private function clearMysqlFixtures(): void
    {
        if (DB::connection()->getDatabaseName() !== 'txboard_concurrency_test') {
            throw new \RuntimeException('Identity fixture requires its dedicated database.');
        }
        Schema::disableForeignKeyConstraints();
        try { foreach (Schema::getTableListing() as $table) { Schema::drop($table); } }
        finally { Schema::enableForeignKeyConstraints(); }
    }
}
