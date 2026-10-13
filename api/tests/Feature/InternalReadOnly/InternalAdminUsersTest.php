<?php

namespace Tests\Feature\InternalReadOnly;

use App\Models\User;
use App\Models\Plan;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InternalAdminUsersTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;
    private array $keys = [];
    private User $admin;
    private string $bearer;
    private string $path;
    private string $operation = 'admin.user.list';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/txboard-admin-users-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $trust = ['proof_keys' => [], 'services' => []];
        foreach (['user', 'admin'] as $role) {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
            openssl_pkey_export($key, $this->keys[$role]);
            $publicPath = $this->directory.'/'.$role.'.pub';
            file_put_contents($publicPath, openssl_pkey_get_details($key)['key']);
            $trust['proof_keys'][$role.'-1'] = ['epoch' => 1, 'service' => $role.'-gateway', 'revoked' => false,
                'not_before' => time() - 60, 'not_after' => time() + 3600, 'public_key_path' => $publicPath];
            $trust['services'][$role.'-gateway']['certificates'][str_repeat($role === 'admin' ? 'b' : 'a', 64)] = [
                'revoked' => false, 'not_before' => time() - 60, 'not_after' => time() + 3600];
        }
        file_put_contents($this->directory.'/trust.json', json_encode($trust, JSON_THROW_ON_ERROR));
        config(['internal_api_slice.enabled' => true, 'internal_api_slice.admin_users_enabled' => true,
            'internal_api_slice.trust_file' => $this->directory.'/trust.json', 'core_shadow.isolated_copy' => true]);
        $this->path = hash('crc32b', config('app.key'));
        $this->admin = User::create(['email' => 'admin-identity@example.test', 'password' => 'private-password-hash',
            'uuid' => (string) Str::uuid(), 'token' => 'private-subscription-token', 'is_admin' => true,
            'is_staff' => false, 'banned' => false]);
        $this->bearer = 'Bearer '.$this->admin->createToken('normal-admin', ['*'], now()->addHour())->plainTextToken;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) unlink($file);
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_strict_default_off_and_isolated_environment_gate_precede_authentication(): void
    {
        foreach ([false, 'true', 1, null] as $enabled) {
            config(['internal_api_slice.admin_users_enabled' => $enabled]);
            $this->send()->assertNotFound()->assertJsonPath('error.code', 'OPERATION_DISABLED');
        }
        config(['internal_api_slice.admin_users_enabled' => true, 'core_shadow.isolated_copy' => false]);
        $this->send()->assertNotFound();
        config(['core_shadow.isolated_copy' => true]);
        $this->app->detectEnvironment(static fn () => 'production');
        $this->send()->assertNotFound();
        $this->assertDatabaseCount('tx_internal_readonly_nonces', 0);
        $this->assertNull($this->admin->tokens()->first()->last_used_at);
    }

    public function test_current_identity_is_closed_and_does_not_grant_future_authority(): void
    {
        $before = $this->admin->fresh()->getRawOriginal();
        $response = $this->send()->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('data.pagination.total',1)->assertJsonPath('data.items.0.id',(string)$this->admin->id);
        $this->assertSame(['current_only' => true, 'slice' => 'internal-admin-users/1'], $response->json('meta'));
        $this->assertEqualsCanonicalizing(['data', 'request_id', 'meta'], array_keys($response->json()));
        $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/D', $response->json('request_id'));
        $this->assertSame($before, $this->admin->fresh()->getRawOriginal());
        $this->assertDatabaseHas('tx_internal_readonly_audits', ['service' => 'admin-gateway',
            'operation' => 'admin.user.list', 'actor_id' => $this->admin->id, 'status' => 200]);
        foreach ([$this->bearer, $this->path, $this->admin->uuid, 'private-subscription-token', 'private-password-hash'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_service_and_actor_roles_cannot_be_substituted(): void
    {
        $this->send(role: 'user')->assertUnauthorized()->assertJsonPath('error.code', 'SERVICE_UNAUTHORIZED');
        $user = User::create(['email' => 'identity-user@example.test', 'password' => 'fixture', 'uuid' => (string) Str::uuid(),
            'token' => 'other-secret', 'is_admin' => false, 'is_staff' => true, 'banned' => false]);
        $token = 'Bearer '.$user->createToken('staff', ['*'])->plainTextToken;
        $this->send(token: $token, headers: ['HTTP_X_ACTOR_ID' => (string) $this->admin->id,
            'HTTP_X_ACTOR_ROLE' => 'admin'])->assertForbidden()->assertJsonPath('error.code', 'ADMIN_ACCESS_DENIED');
        $this->assertDatabaseHas('tx_internal_readonly_audits', ['operation' => 'admin.user.list',
            'actor_id' => $user->id, 'status' => 403]);
    }

    public function test_ban_demotion_and_revocation_are_current_between_requests(): void
    {
        $this->send()->assertOk();
        $this->admin->update(['banned' => true]);
        $this->send()->assertForbidden();
        $this->admin->update(['banned' => false, 'is_admin' => false]);
        $this->send()->assertForbidden();
        $this->admin->update(['is_admin' => true, 'email' => 'current-admin@example.test']);
        $this->send()->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->admin->tokens()->delete();
        $this->send()->assertUnauthorized()->assertJsonPath('error.code', 'ACTOR_UNAUTHORIZED');
    }

    public function test_expired_and_retired_agent_credentials_never_authenticate(): void
    {
        foreach ([$this->admin->createToken('expired', ['*'], now()->subMinute()),
            $this->admin->createToken('agent:legacy', ['*']),
            $this->admin->createToken('renamed-legacy', ['agent:nodes:read'])] as $token) {
            $this->send(token: 'Bearer '.$token->plainTextToken)->assertUnauthorized();
            $this->assertNull($token->accessToken->fresh()->last_used_at);
        }
        $this->assertDatabaseCount('tx_internal_readonly_nonces', 0);
    }

    public function test_path_input_is_exact_and_missing_route_semantics_are_preserved(): void
    {
        $this->send(['admin_path' => 'wrong', 'page' => 1, 'per_page' => 20])->assertNotFound();
        foreach ([[], ['admin_path' => ''], ['admin_path' => str_repeat('a', 129)], ['admin_path' => '../secret'],
            ['admin_path' => 1], ['admin_path' => $this->path, 'actor_id' => (string) $this->admin->id],
            ['admin_path' => $this->path, 'id' => '1']] as $input) {
            $this->send($input)->assertStatus(400)->assertJsonPath('error.code', 'INVALID_REQUEST');
        }
        $this->assertDatabaseCount('tx_internal_readonly_nonces', 1);
    }

    public function test_native_path_rotation_and_duplicate_input_are_rechecked(): void
    {
        $this->send()->assertOk();
        admin_setting(['secure_path' => 'rotated-admin-fixture']);
        $this->send()->assertNotFound();
        $this->send(['admin_path' => 'rotated-admin-fixture', 'page' => 1, 'per_page' => 20])->assertOk();
        $raw = $this->body(['admin_path' => $this->path, 'page' => 1, 'per_page' => 20]);
        $raw = str_replace('"input":{', '"input":{"admin_path":"duplicate",', $raw);
        $this->raw($raw, $this->proof($raw, 'admin', $this->bearer), $this->bearer)->assertStatus(400);
        $this->assertDatabaseCount('tx_internal_readonly_nonces', 3);
    }

    public function test_verified_nonce_replay_does_not_emit_another_identity_or_audit(): void
    {
        $raw = $this->body(['admin_path' => $this->path, 'page' => 1, 'per_page' => 20]);
        $proof = $this->proof($raw, 'admin', $this->bearer);
        $this->raw($raw, $proof, $this->bearer)->assertOk();
        $this->raw($raw, $proof, $this->bearer)->assertStatus(409)->assertJsonPath('error.code', 'SERVICE_REPLAY');
        $this->assertDatabaseCount('tx_internal_readonly_nonces', 1);
        $this->assertDatabaseCount('tx_internal_readonly_audits', 1);
    }

    public function test_audit_insert_failure_is_bounded_and_secret_free_after_admission(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER reject_admin_identity_audit BEFORE INSERT ON tx_internal_readonly_audits BEGIN SELECT RAISE(ABORT, 'synthetic-private-audit-failure'); END");
        } else {
            DB::unprepared("CREATE TRIGGER reject_admin_identity_audit BEFORE INSERT ON tx_internal_readonly_audits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic-private-audit-failure'");
        }
        $response = $this->send()->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        foreach ([$this->bearer, $this->path, 'private-subscription-token', 'private-password-hash', 'SQL', 'synthetic-private-audit-failure'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertDatabaseCount('tx_internal_readonly_nonces', 1);
        $this->assertDatabaseCount('tx_internal_readonly_audits', 0);
        $this->assertTrue((bool) $this->admin->fresh()->is_admin);
    }

    public function test_unavailable_nonce_storage_never_opens_the_operation(): void
    {
        Schema::drop('tx_internal_readonly_nonces');
        $this->send()->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        $this->assertDatabaseCount('tx_internal_readonly_audits', 0);
    }

    public function test_native_current_user_filters_order_and_exact_raw_integer_dto_are_read_only(): void
    {
        $plan = Plan::create(['name' => 'Current user plan', 'group_id' => 1, 'transfer_enable' => 2]);
        $group = DB::table('tx_server_group')->insertGetId(['name' => 'Current user group', 'created_at' => time(), 'updated_at' => time()]);
        $target = $this->user(['plan_id' => $plan->id, 'group_id' => $group, 'balance' => 2147483647,
            'commission_balance' => 1234, 'u' => 9007199254740993, 'd' => 23, 'transfer_enable' => 500000000000000001]);
        $orphan = $this->user(['plan_id' => 2147483647, 'group_id' => 2147483647]);
        $this->user(['banned' => true]);
        $before = DB::table('tx_user')->orderBy('id')->get()->toJson();
        $input = ['admin_path' => $this->path, 'page' => 1, 'per_page' => 1, 'banned' => false, 'plan_id' => (string) $plan->id];
        $response = $this->send($input)->assertOk()->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', (string) $target->id)->assertJsonPath('data.items.0.balance_minor', '2147483647')
            ->assertJsonPath('data.items.0.commission_balance_minor', '1234')
            ->assertJsonPath('data.items.0.traffic', ['upload_bytes'=>'9007199254740993','download_bytes'=>'23','limit_bytes'=>'500000000000000001'])
            ->assertJsonPath('data.items.0.group.id', (string) $group)->assertJsonPath('data.items.0.plan.id', (string) $plan->id);
        $this->send(['admin_path'=>$this->path,'page'=>1,'per_page'=>1,'banned'=>false])->assertOk()
            ->assertJsonPath('data.items.0.id',(string)$orphan->id)->assertJsonPath('data.items.0.plan',null)->assertJsonPath('data.items.0.group',null);
        $this->assertSame($before, DB::table('tx_user')->orderBy('id')->get()->toJson());
        foreach (['private-user-token','private-user-password','subscribe_url','uuid','password','commission_rate'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_current_detail_uses_native_relations_and_missing_user_404(): void
    {
        $target = $this->user(['invite_user_id'=>$this->admin->id,'remarks'=>'Native remarks']);
        $response = $this->detail($target->id)->assertOk()->assertJsonPath('data.id',(string)$target->id)
            ->assertJsonPath('data.invite_user',['id'=>(string)$this->admin->id,'email'=>$this->admin->email])
            ->assertJsonPath('data.remarks','Native remarks');
        $this->detail(2147483647)->assertNotFound();
        $this->assertStringNotContainsString('private-user-token', $response->getContent());
        $target->update(['invite_user_id'=>2147483647]);
        $this->detail($target->id)->assertOk()->assertJsonPath('data.invite_user',null);
    }

    public function test_closed_list_and_detail_ids_filters_and_native_sql_failure_are_bounded(): void
    {
        $input=['admin_path'=>$this->path,'page'=>1,'per_page'=>20];
        foreach ([['page'=>0],['page'=>10000],['per_page'=>101],['banned'=>'false'],['banned'=>1],['email'=>'secret'],
            ['sort'=>'balance'],['plan_id'=>1],['plan_id'=>'01'],['plan_id'=>'2147483648'],['plan_id'=>null]] as $patch) {
            $this->send(array_replace($input,$patch))->assertStatus(400);
        }
        $this->operation='admin.user.get';
        foreach ([1,'0','01','2147483648',null] as $id) $this->send(['admin_path'=>$this->path,'id'=>$id])->assertStatus(400);
        $this->operation='admin.user.list';
        $this->assertDatabaseCount('tx_internal_readonly_nonces',0);
        $plan=Plan::create(['name'=>'SQL failure plan','group_id'=>1,'transfer_enable'=>2]);
        $this->user(['plan_id'=>$plan->id]);
        Schema::drop('tx_plan');
        $response=$this->send()->assertStatus(503)->assertJsonPath('error.code','DEPENDENCY_UNAVAILABLE');
        $this->assertStringNotContainsString('SQL',$response->getContent());
    }

    public function test_invalid_raw_integer_is_refused_without_source_repair(): void
    {
        if (DB::getDriverName() !== 'sqlite') $this->markTestSkipped('SQLite deliberately permits malformed INTEGER evidence');
        $target=$this->user();
        DB::table('tx_user')->where('id',$target->id)->update(['u'=>'private-malformed-counter']);
        $response=$this->detail($target->id)->assertStatus(503)->assertJsonPath('error.code','DEPENDENCY_UNAVAILABLE');
        $this->assertStringNotContainsString('private-malformed-counter',$response->getContent());
        $this->assertSame('private-malformed-counter',$target->fresh()->getRawOriginal('u'));
    }

    private function user(array $patch=[]): User
    {
        return User::create(array_replace(['email'=>'user-'.Str::uuid().'@example.test','password'=>'private-user-password',
            'uuid'=>(string)Str::uuid(),'token'=>'private-user-token','banned'=>false],$patch));
    }

    private function detail(int $id)
    {
        $this->operation='admin.user.get';
        try { return $this->send(['admin_path'=>$this->path,'id'=>(string)$id]); }
        finally { $this->operation='admin.user.list'; }
    }

    private function body(array $input): string
    {
        return json_encode(['contract_version' => '1', 'request_id' => (string) Str::uuid(),
            'deadline_at' => time() + 5, 'input' => (object) $input], JSON_THROW_ON_ERROR);
    }

    private function proof(string $raw, string $role, string $token): string
    {
        $now = time();
        return JWT::encode(['v' => 'internal-readonly/1', 'epoch' => 1, 'iss' => 'txboard-local-tls-boundary',
            'aud' => 'txboard-core-local', 'service' => $role.'-gateway',
            'cert_sha256' => str_repeat($role === 'admin' ? 'b' : 'a', 64), 'method' => 'POST',
            'path' => '/txapi/internal/v1/operations/'.$this->operation, 'body_sha256' => hash('sha256', $raw),
            'actor_sha256' => hash('sha256', $token), 'iat' => $now, 'nbf' => $now - 2, 'exp' => $now + 10,
            'jti' => bin2hex(random_bytes(16))], $this->keys[$role], 'RS256', $role.'-1');
    }

    private function send(?array $input = null, string $role = 'admin', ?string $token = null, array $headers = [])
    {
        $raw = $this->body($input ?? ['admin_path' => $this->path, 'page' => 1, 'per_page' => 20]);
        $token ??= $this->bearer;
        return $this->raw($raw, $this->proof($raw, $role, $token), $token, $headers);
    }

    private function raw(string $raw, string $proof, string $token, array $headers = [])
    {
        Auth::forgetGuards();
        return $this->call('POST', '/txapi/internal/v1/operations/'.$this->operation, [], [], [], array_replace([
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => $token,
            'HTTP_X_TXBOARD_SERVICE_PROOF' => $proof], $headers), $raw);
    }
}
