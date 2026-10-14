<?php

namespace Tests\Feature\Server;

use App\Domains\Network\NativeNodeAuthorization;
use App\Models\Server;
use App\Models\ServerMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class NativeNodeAuthorizationSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private string $signingSeed;

    public function createApplication()
    {
        $application = parent::createApplication();
        if ($application['config']->get('database.default') !== 'sqlite'
            || $application['config']->get('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Native authorization tests require dedicated in-memory SQLite');
        }
        return $application;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->signingSeed = rtrim(strtr(base64_encode(str_repeat('s', SODIUM_CRYPTO_SIGN_SEEDBYTES)), '+/', '-_'), '=');
        config(['native_node_authorization.enabled' => true,
            'native_node_authorization.core_instance_id' => 'core_node_auth_test_01',
            'native_node_authorization.key_id' => 'core-node-auth-test',
            'native_node_authorization.key_epoch' => '1',
            'native_node_authorization.private_seed' => $this->signingSeed,
            'native_node_authorization.lease_seconds' => 90,
            'native_node_authorization.max_lease_seconds' => 120,
            'native_node_authorization.refresh_interval_seconds' => 30,
            'native_node_authorization.max_clock_skew_seconds' => 5,
            'native_traffic_receipts.enabled' => true,
            'native_traffic_periods.enabled' => true]);
        admin_setting(['server_token' => 'native-node-auth-test-token']);
    }

    private function node(?int $machineId = null): Server
    {
        if ($machineId !== null) {
            $machine = ServerMachine::create(['name' => 'signed-snapshot-machine',
                'token' => 'native-snapshot-machine-token', 'is_active' => true]);
            $machineId = (int) $machine->id;
        }
        return Server::create(['name' => 'signed-snapshot-node', 'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1', 'port' => 443, 'server_port' => 443, 'rate' => 1,
            'group_ids' => [1], 'enabled' => true, 'machine_id' => $machineId]);
    }

    private function headers(Server $node, array $extra = []): array
    {
        return $extra + ['Authorization' => 'Bearer native-node-auth-test-token', 'X-TX-Node-ID' => (string) $node->id];
    }

    private function publicKey(): string
    {
        $seed = base64_decode(strtr($this->signingSeed, '-_', '+/').str_repeat('=', (4 - strlen($this->signingSeed) % 4) % 4), true);
        $pair = sodium_crypto_sign_seed_keypair($seed);
        return rtrim(strtr(base64_encode(sodium_crypto_sign_publickey($pair)), '+/', '-_'), '=');
    }

    public function test_handshake_publishes_only_public_ed25519_bootstrap_pin(): void
    {
        $node = $this->node();
        $response = $this->postJson('/txapi/node/v1/handshake', [], $this->headers($node))->assertOk();
        self::assertContains(NativeNodeAuthorization::CAPABILITY, $response->json('data.capabilities'));
        $response->assertJsonPath('data.authorization_trust.protocol_version', 1)
            ->assertJsonPath('data.authorization_trust.core_instance_id', 'core_node_auth_test_01')
            ->assertJsonPath('data.authorization_trust.algorithm', 'Ed25519')
            ->assertJsonPath('data.authorization_trust.audience', NativeNodeAuthorization::AUDIENCE)
            ->assertJsonPath('data.authorization_trust.policy_version', 'native-node-authorization/v1')
            ->assertJsonPath('data.authorization_trust.key_id', 'core-node-auth-test')
            ->assertJsonPath('data.authorization_trust.key_epoch', '1')
            ->assertJsonPath('data.authorization_refresh_interval_seconds', 30)
            ->assertJsonPath('data.authorization_trust.public_key', $this->publicKey());
        self::assertStringNotContainsString($this->signingSeed, $response->getContent());
    }

    public function test_empty_and_full_user_snapshots_are_signed_and_renew_with_monotonic_revision(): void
    {
        $node = $this->node();
        $headers = $this->headers($node);
        $empty = $this->getJson('/txapi/node/v1/users', $headers + ['If-None-Match' => '"anything"'])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $emptyPayload = NativeNodeAuthorization::verify($empty->json('data.authorization'), $this->publicKey(), [
            'core_instance_id' => 'core_node_auth_test_01', 'node_id' => $node->id, 'machine_id' => null,
            'key_id' => 'core-node-auth-test', 'key_epoch' => '1', 'policy_version' => 'native-node-authorization/v1'], time());
        self::assertSame([], $emptyPayload['users']);
        self::assertTrue($emptyPayload['complete']);
        self::assertSame([], $empty->json('data.users'));
        self::assertGreaterThan(time(), $emptyPayload['expires_at']);

        $users = [['id' => 501, 'uuid' => 'synthetic-user-secret-marker', 'speed_limit' => 8,
            'device_limit' => 2, 'traffic_period' => ['signed' => 'synthetic-period']]];
        $first = DB::transaction(function () use ($node, $users) {
            return app(NativeNodeAuthorization::class)->issueLocked(
                Server::query()->whereKey($node->id)->lockForUpdate()->first(), $users, time());
        });
        $firstPayload = NativeNodeAuthorization::verify($first, $this->publicKey(), [
            'core_instance_id' => 'core_node_auth_test_01', 'node_id' => $node->id, 'machine_id' => null,
            'key_id' => 'core-node-auth-test', 'key_epoch' => '1', 'policy_version' => 'native-node-authorization/v1'], time());
        self::assertEquals($users, $firstPayload['users']);
        self::assertSame('2', $firstPayload['revision']);
        self::assertStringNotContainsString('synthetic-user-secret-marker', DB::table('tx_node_authorization_state')->value('snapshot_digest'));

        $second = DB::transaction(function () use ($node, $users) {
            return app(NativeNodeAuthorization::class)->issueLocked(
                Server::query()->whereKey($node->id)->lockForUpdate()->first(), $users, time() + 1);
        });
        $secondPayload = NativeNodeAuthorization::verify($second, $this->publicKey(), [
            'core_instance_id' => 'core_node_auth_test_01', 'node_id' => $node->id, 'machine_id' => null,
            'key_id' => 'core-node-auth-test', 'key_epoch' => '1', 'policy_version' => 'native-node-authorization/v1'], time() + 1);
        self::assertSame('3', $secondPayload['revision']);
        self::assertGreaterThan($firstPayload['issued_at'], $secondPayload['issued_at']);
        self::assertSame('3', (string) DB::table('tx_node_authorization_state')->value('revision'));
    }

    public function test_signed_payload_is_bound_to_node_machine_key_and_finite_window(): void
    {
        $node = $this->node(17);
        $envelope = DB::transaction(function () use ($node) {
            return app(NativeNodeAuthorization::class)->issueLocked(
                Server::query()->whereKey($node->id)->lockForUpdate()->first(), [], time());
        });
        $expected = ['core_instance_id' => 'core_node_auth_test_01', 'node_id' => $node->id,
            'machine_id' => $node->machine_id, 'key_id' => 'core-node-auth-test', 'key_epoch' => '1',
            'policy_version' => 'native-node-authorization/v1'];
        $payload = NativeNodeAuthorization::verify($envelope, $this->publicKey(), $expected, time());
        self::assertSame($node->machine_id, $payload['machine_id']);
        self::assertLessThanOrEqual(120, $payload['expires_at'] - $payload['issued_at']);
        $this->expectException(\DomainException::class);
        NativeNodeAuthorization::verify($envelope, $this->publicKey(), array_replace($expected, ['node_id' => 999]), time());
    }

    public function test_enabled_but_invalid_signing_configuration_fails_closed(): void
    {
        $node = $this->node();
        config(['native_node_authorization.private_seed' => null]);
        $this->postJson('/txapi/node/v1/handshake', [], $this->headers($node))
            ->assertStatus(503)->assertJsonPath('error.code', 'NODE_AUTHORIZATION_UNAVAILABLE');
        $this->getJson('/txapi/node/v1/users', $this->headers($node))
            ->assertStatus(503)->assertJsonPath('error.code', 'NODE_AUTHORIZATION_UNAVAILABLE')
            ->assertHeader('Cache-Control', 'no-store, private');
        self::assertSame(0, DB::table('tx_node_authorization_state')->count());

        config(['native_node_authorization.private_seed' => $this->signingSeed,
            'native_node_authorization.lease_seconds' => 121]);
        $this->getJson('/txapi/node/v1/users', $this->headers($node))
            ->assertStatus(503)->assertJsonPath('error.code', 'NODE_AUTHORIZATION_UNAVAILABLE');
    }

    public function test_string_environment_boolean_and_integer_values_are_parsed_strictly(): void
    {
        config(['native_node_authorization.enabled' => 'true',
            'native_node_authorization.lease_seconds' => '90',
            'native_node_authorization.max_clock_skew_seconds' => '5']);
        self::assertTrue(app(NativeNodeAuthorization::class)->enabled());
        $node = $this->node();
        $response = $this->postJson('/txapi/node/v1/handshake', [], $this->headers($node))->assertOk();
        self::assertSame(90, $response->json('data.authorization_trust.lease_seconds'));

        config(['native_node_authorization.enabled' => 'false']);
        self::assertFalse(app(NativeNodeAuthorization::class)->enabled());
        config(['native_node_authorization.enabled' => 'yes']);
        self::assertTrue(app(NativeNodeAuthorization::class)->enabled());
        config(['native_node_authorization.enabled' => 'unexpected']);
        self::assertFalse(app(NativeNodeAuthorization::class)->enabled());
    }

    public function test_reassignment_cannot_reuse_an_authorization_state_for_another_machine_scope(): void
    {
        $node = $this->node(17);
        DB::transaction(function () use ($node): void {
            app(NativeNodeAuthorization::class)->issueLocked(
                Server::query()->whereKey($node->id)->lockForUpdate()->first(), [], time());
        });
        $node->forceFill(['machine_id' => null])->save();
        $this->expectException(\DomainException::class);
        DB::transaction(function () use ($node): void {
            app(NativeNodeAuthorization::class)->issueLocked(
                Server::query()->whereKey($node->id)->lockForUpdate()->first(), [], time());
        });
    }

    private function signed(Server $node, int $at): array
    {
        return DB::transaction(fn () => app(NativeNodeAuthorization::class)->issueLocked(
            Server::query()->whereKey($node->id)->lockForUpdate()->first(), [], $at));
    }

    private function identity(Server $node): array
    {
        return ['core_instance_id' => 'core_node_auth_test_01', 'node_id' => $node->id,
            'machine_id' => $node->machine_id, 'key_id' => 'core-node-auth-test', 'key_epoch' => '1',
            'policy_version' => NativeNodeAuthorization::POLICY_VERSION];
    }

    public function test_tampered_payload_and_exact_expiry_are_refused(): void
    {
        $node = $this->node(); $at = time(); $envelope = $this->signed($node, $at);
        $payload = NativeNodeAuthorization::verify($envelope, $this->publicKey(), $this->identity($node), $at);
        self::assertSame(NativeNodeAuthorization::AUDIENCE, $payload['audience']);
        foreach (['tampered', 'exact-expiry'] as $case) {
            $candidate = $envelope;
            if ($case === 'tampered') {
                $bytes = base64_decode(strtr($candidate['payload'], '-_', '+/'));
                $bytes = str_replace(NativeNodeAuthorization::AUDIENCE, 'tx-node-forged-authorization-v1', $bytes);
                $candidate['payload'] = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
            }
            try {
                NativeNodeAuthorization::verify($candidate, $this->publicKey(), $this->identity($node),
                    $case === 'exact-expiry' ? $payload['expires_at'] : $at);
                self::fail('Unsafe authorization was accepted: '.$case);
            } catch (\DomainException $error) { self::assertSame('NATIVE_NODE_AUTHORIZATION_INVALID', $error->getMessage()); }
        }
    }

    public function test_key_epoch_rollback_and_equal_epoch_key_conflict_preserve_last_revision(): void
    {
        $node = $this->node(); $at = time(); $this->signed($node, $at);
        config(['native_node_authorization.key_epoch' => '2']);
        $this->signed($node, $at + 1);
        $before = DB::table('tx_node_authorization_state')->where('node_id', $node->id)->first();
        foreach ([['key_epoch' => '1'], ['key_id' => 'other-key', 'key_epoch' => '2']] as $values) {
            config(['native_node_authorization.key_id' => 'core-node-auth-test', 'native_node_authorization.key_epoch' => '2']);
            foreach ($values as $key => $value) { config(['native_node_authorization.'.$key => $value]); }
            try { $this->signed($node, $at + 2); self::fail('Key rollback/conflict was accepted'); }
            catch (\DomainException) { self::assertEquals($before, DB::table('tx_node_authorization_state')->where('node_id', $node->id)->first()); }
        }
    }

    public function test_transaction_rollback_never_advances_revision_or_retains_signed_users(): void
    {
        $node = $this->node(); $at = time(); $this->signed($node, $at);
        $before = DB::table('tx_node_authorization_state')->where('node_id', $node->id)->first();
        try {
            DB::transaction(function () use ($node, $at): void {
                app(NativeNodeAuthorization::class)->issueLocked(
                    Server::query()->whereKey($node->id)->lockForUpdate()->first(),
                    [['id' => 501, 'uuid' => 'rollback-private-marker', 'speed_limit' => 1, 'device_limit' => 1]], $at + 1);
                throw new \RuntimeException('synthetic-rollback');
            });
        } catch (\RuntimeException $error) { self::assertSame('synthetic-rollback', $error->getMessage()); }
        $after = DB::table('tx_node_authorization_state')->where('node_id', $node->id)->first();
        self::assertEquals($before, $after);
        self::assertStringNotContainsString('rollback-private-marker', json_encode($after, JSON_THROW_ON_ERROR));
        $next = NativeNodeAuthorization::verify($this->signed($node, $at + 2), $this->publicKey(), $this->identity($node), $at + 2);
        self::assertSame('2', $next['revision']);
    }

    public function test_cryptographically_valid_wrong_audience_policy_and_incomplete_claims_are_refused(): void
    {
        $node = $this->node(); $at = time(); $valid = $this->signed($node, $at);
        $bytes = base64_decode(strtr($valid['payload'], '-_', '+/'));
        $claims = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        $seed = base64_decode(strtr($this->signingSeed, '-_', '+/'));
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed));
        foreach (['audience' => 'other-domain', 'policy_version' => 'other-policy', 'complete' => false] as $field => $value) {
            $modified = array_replace($claims, [$field => $value]); ksort($modified, SORT_STRING);
            $raw = json_encode($modified, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $candidate = ['payload' => rtrim(strtr(base64_encode($raw), '+/', '-_'), '='),
                'signature' => rtrim(strtr(base64_encode(sodium_crypto_sign_detached($raw, $secret)), '+/', '-_'), '=')];
            try { NativeNodeAuthorization::verify($candidate, $this->publicKey(), $this->identity($node), $at); self::fail('Accepted '.$field); }
            catch (\DomainException $error) { self::assertSame('NATIVE_NODE_AUTHORIZATION_INVALID', $error->getMessage()); }
        }
    }

    public function test_signing_clock_regression_and_invalid_refresh_interval_fail_closed(): void
    {
        $node = $this->node(); $at = time(); $this->signed($node, $at);
        $before = DB::table('tx_node_authorization_state')->where('node_id', $node->id)->first();
        try { $this->signed($node, $at - 1); self::fail('Clock rollback was accepted'); }
        catch (\DomainException $error) { self::assertSame('NATIVE_NODE_AUTHORIZATION_CLOCK_REGRESSION', $error->getMessage()); }
        self::assertEquals($before, DB::table('tx_node_authorization_state')->where('node_id', $node->id)->first());
        config(['native_node_authorization.refresh_interval_seconds' => '31']);
        $this->getJson('/txapi/node/v1/users', $this->headers($node))
            ->assertStatus(503)->assertJsonPath('error.code', 'NODE_AUTHORIZATION_UNAVAILABLE');
    }
}

