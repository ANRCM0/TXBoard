<?php

namespace Tests\Feature\InternalReadOnly;

use App\Models\Plan;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InternalSessionTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;
    private string $private = '';
    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/txboard-internal-list-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($key, $this->private);
        file_put_contents($this->directory.'/proof.pub', openssl_pkey_get_details($key)['key']);
        file_put_contents($this->directory.'/trust.json', json_encode([
            'proof_keys' => ['user-1' => ['epoch' => 1, 'service' => 'user-gateway', 'revoked' => false,
                'not_before' => time()-60, 'not_after' => time()+3600, 'public_key_path' => $this->directory.'/proof.pub'],
                'admin-1' => ['epoch' => 1, 'service' => 'admin-gateway', 'revoked' => false,
                    'not_before' => time()-60, 'not_after' => time()+3600, 'public_key_path' => $this->directory.'/proof.pub']],
            'services' => ['user-gateway' => ['certificates' => [str_repeat('a', 64) => ['revoked' => false,
                'not_before' => time()-60, 'not_after' => time()+3600]]],
                'admin-gateway' => ['certificates' => [str_repeat('a', 64) => ['revoked' => false,
                    'not_before' => time()-60, 'not_after' => time()+3600]]]],
        ]));
        config(['core_shadow.isolated_copy' => true, 'internal_api_slice.sessions_enabled' => true, 'internal_api_slice.enabled' => true, 'internal_api_slice.trust_file' => $this->directory.'/trust.json']);
        $this->user = User::create(['email' => 'session@example.test', 'password' => 'fixture-only',
            'uuid' => (string) Str::uuid(), 'token' => 'private-list-token']);
        $this->token = 'Bearer '.$this->user->createToken('list', ['*'], now()->addHour())->plainTextToken;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) unlink($file);
        rmdir($this->directory);
        parent::tearDown();
    }



    public function test_list_uses_bounded_native_owner_query_and_closed_nonsecret_dto(): void
    {
        $second = $this->user->createToken('second-device', ['read'], now()->addHour());
        $other = $this->other(); $other->createToken('foreign-device');
        $queries = []; DB::listen(function ($query) use (&$queries): void { $queries[] = strtolower($query->sql); });
        $response = $this->send('user.session.list')->assertOk()
            ->assertJsonPath('meta', ['current_only' => true, 'slice' => 'internal-isolated-sessions/1']);
        $items = $response->json('data.items');
        $this->assertCount(2, $items);
        $this->assertSame((string) $second->accessToken->id, $items[0]['id']);
        $this->assertSame(['id','name','abilities','last_used_at','created_at','updated_at','expires_at'], array_keys($items[0]));
        $this->assertSame(['read'], $items[0]['abilities']);
        $this->assertTrue(collect($queries)->contains(fn ($sql) => str_contains($sql, 'limit 101') && str_contains($sql, 'personal_access_tokens')));
        foreach ([$this->token, $second->plainTextToken, $this->user->token, $second->accessToken->token, 'foreign-device'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
            $this->assertStringNotContainsString($secret, DB::table('tx_internal_readonly_audits')->get()->toJson());
        }
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame(3, DB::table('personal_access_tokens')->count());
    }

    public function test_logout_revokes_only_current_and_refreshes_long_lived_guard(): void
    {
        $other = $this->user->createToken('remaining-device');
        $this->send('user.session.logout')->assertOk()->assertJsonPath('data', ['ok'=>true]);
        $this->assertSame(1, $this->user->tokens()->count());
        $this->send('user.session.list')->assertUnauthorized();
        $this->send('user.profile.get')->assertUnauthorized();
        $this->send('user.session.list', token:'Bearer '.$other->plainTextToken)->assertOk()
            ->assertJsonCount(1, 'data.items');
    }

    public function test_revoke_retains_native_owner_scope_and_missing_duplicate_404(): void
    {
        $second = $this->user->createToken('second-device');
        $foreign = $this->other()->createToken('foreign-device');
        $this->send('user.session.revoke', ['session_id'=>(string)$foreign->accessToken->id])->assertNotFound();
        $this->assertNotNull($foreign->accessToken->fresh());
        $this->send('user.session.revoke', ['session_id'=>(string)$second->accessToken->id])->assertOk()
            ->assertJsonPath('data', ['ok'=>true]);
        $this->send('user.session.revoke', ['session_id'=>(string)$second->accessToken->id])->assertNotFound();
        $this->send('user.session.list')->assertOk()->assertJsonCount(1,'data.items');
    }

    public function test_revoking_current_session_prevents_further_access(): void
    {
        $id = $this->user->tokens()->firstOrFail()->id;
        $this->send('user.session.revoke', ['session_id'=>(string)$id])->assertOk();
        $this->send('user.session.logout')->assertUnauthorized();
        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_exact_default_off_environment_and_isolated_gates(): void
    {
        foreach ([false, 'true', 1, null] as $value) {
            config(['internal_api_slice.sessions_enabled'=>$value]);
            foreach ($this->operations() as $operation=>$input) $this->send($operation,$input)->assertNotFound();
        }
        config(['internal_api_slice.sessions_enabled'=>true, 'core_shadow.isolated_copy'=>false]);
        $this->send('user.session.logout')->assertNotFound();
        config(['core_shadow.isolated_copy'=>true, 'internal_api_slice.enabled'=>false]);
        $this->send('user.session.logout')->assertNotFound();
        config(['internal_api_slice.enabled'=>true]);
        $this->app->detectEnvironment(static fn ()=>'production');
        $this->send('user.session.logout')->assertNotFound();
        $this->assertSame(1,$this->user->tokens()->count());
    }

    public function test_current_role_ban_domain_expiry_and_service_checks_precede_mutation(): void
    {
        $this->send('user.session.logout', service:'admin-gateway')->assertUnauthorized();
        foreach (['Bearer node-fixture', 'Bearer agent-fixture', 'Bearer missing', ''] as $token) {
            $this->send('user.session.logout', token:$token)->assertUnauthorized();
        }
        foreach ([['is_admin'=>true], ['is_admin'=>false,'banned'=>true], ['banned'=>false,'email'=>'real@example.com']] as $patch) {
            $this->user->update($patch); $this->send('user.session.logout')->assertForbidden();
        }
        $this->user->update(['email'=>'session@example.test']);
        $this->user->tokens()->update(['expires_at'=>now()->subMinute()]);
        $this->send('user.session.logout')->assertUnauthorized();
        $this->assertSame(1,$this->user->tokens()->count());
    }

    public function test_non_user_token_principal_cannot_enter_session_mutations(): void
    {
        $this->user->tokens()->update(['tokenable_type'=>Plan::class]);
        $this->send('user.session.logout')->assertUnauthorized();
        $this->assertSame(1,DB::table('personal_access_tokens')->count());
    }

    public function test_closed_input_versions_and_decimal_ids_are_checked_before_mutation(): void
    {
        foreach ([[], ['session_id'=>1], ['session_id'=>'0'], ['session_id'=>'01'], ['session_id'=>'-1'],
            ['session_id'=>'1.0'], ['session_id'=>'1000000000000000000'], ['session_id'=>null],
            ['session_id'=>'1','owner_id'=>'1']] as $input) $this->send('user.session.revoke',$input)->assertStatus(400);
        $this->send('user.session.list',['id'=>'1'])->assertStatus(400);
        $this->send('user.session.logout',['token'=>$this->token])->assertStatus(400);
        $this->send('user.session.logout',version:'2')->assertStatus(400);
        $this->send('user.session.revoke',rawInput:'{"session_id":"1","\\u0073ession_id":"2"}')->assertStatus(400);
        $this->assertSame(1,$this->user->tokens()->count());
    }

    public function test_guard_cannot_reuse_previous_owners_session(): void
    {
        $other = $this->other(); $token = 'Bearer '.$other->createToken('foreign-device')->plainTextToken;
        $mine = (string)$this->user->tokens()->firstOrFail()->id;
        $this->send('user.session.list')->assertOk();
        $this->send('user.session.revoke',['session_id'=>$mine], token:$token)->assertNotFound();
        $this->send('user.session.logout',token:$token)->assertOk();
        $this->send('user.session.list')->assertOk()->assertJsonCount(1,'data.items');
        $this->assertSame(0,$other->tokens()->count());
    }

    public function test_proof_body_actor_binding_and_sql_nonce_replay_prevent_another_mutation(): void
    {
        $second = $this->user->createToken('second-device');
        $this->send('user.session.logout', claims:['actor_sha256'=>hash('sha256','different-actor')])->assertUnauthorized();
        $this->send('user.session.logout', claims:['body_sha256'=>hash('sha256','different-body')])->assertUnauthorized();
        $claims = ['jti'=>str_repeat('b',32)];
        $this->send('user.session.revoke', ['session_id'=>(string)$second->accessToken->id], claims:$claims)->assertOk();
        $this->send('user.session.logout', claims:$claims)->assertStatus(409)->assertJsonPath('error.code','SERVICE_REPLAY');
        $this->assertSame(1,$this->user->tokens()->count());
    }

    public function test_more_than_100_native_sessions_refused_without_unbounded_query_or_delete(): void
    {
        for ($i=0;$i<100;$i++) $this->user->createToken('fixture-'.$i);
        $this->send('user.session.list')->assertStatus(409)->assertJsonPath('error.code','SESSION_LIST_TOO_LARGE');
        $this->assertSame(101,$this->user->tokens()->count());
        $this->user->tokens()->orderByDesc('id')->firstOrFail()->delete();
        $this->send('user.session.list')->assertOk()->assertJsonCount(100,'data.items');
    }

    public function test_native_sessions_and_logout_keep_original_behavior_with_internal_disabled(): void
    {
        config(['internal_api_slice.enabled'=>false, 'internal_api_slice.sessions_enabled'=>false]);
        $headers = ['Authorization'=>$this->token];
        $this->getJson('/txapi/auth/sessions',$headers)->assertOk()->assertJsonCount(1,'data');
        $this->postJson('/txapi/auth/logout',[],$headers)->assertOk()->assertJsonPath('data',['ok'=>true]);
        $this->assertSame(0,$this->user->tokens()->count());
    }

    public function test_sql_delete_failure_keeps_sessions_and_safe_error(): void
    {
        $this->trigger('reject_session_delete','personal_access_tokens','DELETE');
        try {
            $response = $this->send('user.session.logout')->assertStatus(500)->assertJsonMissingPath('data');
        } finally { DB::unprepared('DROP TRIGGER reject_session_delete'); }
        $this->assertSame(1,$this->user->tokens()->count());
        foreach ([$this->token,'fixture-private-sql-error'] as $secret) $this->assertStringNotContainsString($secret,$response->getContent());
    }

    public function test_native_false_current_token_delete_preserved_without_claiming_sql_fence(): void
    {
        \Laravel\Sanctum\PersonalAccessToken::deleting(static fn () => false);
        try { $this->send('user.session.logout')->assertOk(); }
        finally { \Laravel\Sanctum\PersonalAccessToken::flushEventListeners(); }
        $this->assertSame(1,$this->user->tokens()->count());
        $this->send('user.session.list')->assertOk();
    }

    public function test_audit_failure_can_hide_current_token_revocation(): void
    {
        $other = $this->user->createToken('remaining-device');
        $this->trigger('reject_session_audit','tx_internal_readonly_audits','INSERT');
        try { $this->send('user.session.logout')->assertStatus(503)->assertJsonPath('error.code','DEPENDENCY_UNAVAILABLE'); }
        finally { DB::unprepared('DROP TRIGGER reject_session_audit'); }
        $this->assertSame(1,$this->user->tokens()->count());
        $this->send('user.session.list')->assertUnauthorized();
        $this->send('user.session.list',token:'Bearer '.$other->plainTextToken)->assertOk();
    }

    public function test_deadline_can_hide_completed_logout_without_reissuing_session(): void
    {
        \Laravel\Sanctum\PersonalAccessToken::deleted(static function (): void { sleep(5); });
        try { $this->send('user.session.logout')->assertStatus(504)->assertJsonPath('error.code','DEADLINE_EXCEEDED'); }
        finally { \Laravel\Sanctum\PersonalAccessToken::flushEventListeners(); }
        $this->assertSame(0,$this->user->tokens()->count());
        $this->send('user.session.list')->assertUnauthorized();
    }

    private function trigger(string $name,string $table,string $action): void
    {
        DB::unprepared(DB::connection()->getDriverName()==='sqlite'
            ? "CREATE TRIGGER $name BEFORE $action ON $table BEGIN SELECT RAISE(ABORT, 'fixture-private-sql-error'); END"
            : "CREATE TRIGGER $name BEFORE $action ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture-private-sql-error'");
    }
    private function operations(): array { return ['user.session.list'=>[], 'user.session.logout'=>[], 'user.session.revoke'=>['session_id'=>'1']]; }
    private function other(): User
    {
        return User::create(['email'=>'other-session@example.test','password'=>'fixture-only','uuid'=>(string)Str::uuid(),'token'=>'private-other-token']);
    }

    private function send(string $operation, array $input = [], string $version = '1', ?string $token = null, ?string $rawInput = null, string $service = 'user-gateway', array $claims = [])
    {
        $token ??= $this->token;
        $body = json_encode(['contract_version' => $version, 'request_id' => (string) Str::uuid(),
            'deadline_at' => time()+5, 'input' => (object) $input], JSON_PRESERVE_ZERO_FRACTION);
        if ($rawInput !== null) {
            $body = substr($body, 0, strpos($body, '"input":')+8).$rawInput.'}';
        }
        $path = '/txapi/internal/v1/operations/'.$operation;
        $proof = JWT::encode([...['v' => 'internal-readonly/1', 'epoch' => 1, 'iss' => 'txboard-local-tls-boundary',
            'aud' => 'txboard-core-local', 'service' => $service, 'cert_sha256' => str_repeat('a', 64),
            'method' => 'POST', 'path' => $path, 'body_sha256' => hash('sha256', $body), 'actor_sha256' => hash('sha256', $token),
            'iat' => time(), 'nbf' => time()-2, 'exp' => time()+10, 'jti' => bin2hex(random_bytes(16))], ...$claims], $this->private, 'RS256', $service === 'user-gateway' ? 'user-1' : 'admin-1');
        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $token, 'HTTP_X_TXBOARD_SERVICE_PROOF' => $proof], $body);
    }
}
