<?php

namespace Tests\Feature\InternalReadOnly;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InternalOrderCancelTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;
    private string $private = '';
    private User $user;
    private string $token;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Services\Plugin\HookManager::reset();
        \Illuminate\Support\Facades\Bus::fake();
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
        config(['core_shadow.isolated_copy' => true, 'internal_api_slice.order_write_enabled' => true, 'internal_api_slice.order_cancel_enabled' => true, 'internal_api_slice.enabled' => true, 'internal_api_slice.trust_file' => $this->directory.'/trust.json']);
        $this->user = User::create(['email' => 'list@example.test', 'password' => 'fixture-only',
            'uuid' => (string) Str::uuid(), 'token' => 'private-list-token']);
        $this->token = 'Bearer '.$this->user->createToken('list', ['*'], now()->addHour())->plainTextToken;
        $this->plan = Plan::create(['name' => 'List Plan', 'group_id' => 1, 'transfer_enable' => 1,
            'show' => true, 'sell' => true, 'renew' => true, 'capacity_limit' => null, 'prices' => [Plan::PERIOD_MONTHLY => 12]]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) unlink($file);
        rmdir($this->directory);
        \App\Services\Plugin\HookManager::reset();
        parent::tearDown();
    }


    public function test_native_reserved_money_coupon_and_duplicate_cancel_are_transactional(): void
    {
        $this->user->update(['balance' => 500]);
        $coupon = \App\Models\Coupon::create(['name' => 'Native reserve', 'code' => 'CANCELFIXTURE', 'type' => 1,
            'value' => 200, 'started_at' => 0, 'ended_at' => 0, 'limit_use' => 1, 'show' => true]);
        $trade = $this->send('user.order.create', $this->input() + ['coupon_code' => $coupon->code])->assertStatus(201)->json('data.trade_no');
        $this->assertSame(0, (int) $this->user->fresh()->balance); $this->assertSame(0, (int) $coupon->fresh()->limit_use);
        $beforeCalls = 0; $afterCalls = 0;
        \App\Services\Plugin\HookManager::register('order.cancel.before', function ($order) use (&$beforeCalls): void {
            $beforeCalls++; $this->assertGreaterThan(0, DB::transactionLevel()); $this->assertSame(0, (int) $order->status);
        });
        \App\Services\Plugin\HookManager::register('order.cancel.after', function ($order) use (&$afterCalls): void {
            $afterCalls++; $this->assertSame(2, (int) $order->status);
        });
        $response = $this->send('user.order.cancel', ['trade_no' => $trade])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['ok' => true], $response->json('data'));
        $this->assertSame(['current_only' => true, 'slice' => 'internal-order-cancel/1'], $response->json('meta'));
        $this->assertSame(500, (int) $this->user->fresh()->balance); $this->assertSame(1, (int) $coupon->fresh()->limit_use);
        $this->assertSame(1, $beforeCalls); $this->assertSame(1, $afterCalls);
        $this->send('user.order.cancel', ['trade_no' => $trade])->assertStatus(409)->assertJsonPath('error.code', 'ORDER_CONFLICT');
        $this->assertSame(500, (int) $this->user->fresh()->balance); $this->assertSame(1, (int) $coupon->fresh()->limit_use);
        $this->assertSame(1, $beforeCalls); $this->assertSame(1, $afterCalls);
        foreach ([$this->token,$trade,$this->user->uuid,'private-list-token','callback_no','balance_amount'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_strict_default_off_isolated_production_service_and_current_role_gates(): void
    {
        $order = $this->pending();
        foreach ([false,'true',1,null] as $enabled) {
            config(['internal_api_slice.order_cancel_enabled' => $enabled]);$this->cancel($order)->assertNotFound();
        }
        config(['internal_api_slice.order_cancel_enabled'=>true,'core_shadow.isolated_copy'=>false]);$this->cancel($order)->assertNotFound();
        config(['core_shadow.isolated_copy'=>true]);
        $this->send('user.order.cancel',['trade_no'=>$order->trade_no],service:'admin-gateway')->assertUnauthorized();
        $this->send('user.order.cancel',['trade_no'=>$order->trade_no],token:'Bearer absent')->assertUnauthorized();
        $this->user->update(['banned'=>true]);$this->cancel($order)->assertForbidden();
        $this->user->update(['banned'=>false,'is_admin'=>true]);$this->cancel($order)->assertForbidden();
        $this->user->update(['is_admin'=>false]);$this->app->detectEnvironment(static fn()=> 'production');$this->cancel($order)->assertNotFound();
        $this->assertSame(0,(int)$order->fresh()->status);$this->assertSame(0,(int)$this->user->fresh()->balance);
    }

    public function test_foreign_missing_orders_and_nonpending_states_do_not_refund(): void
    {
        $foreign = $this->pending(['user_id'=>2147483647]); $this->cancel($foreign)->assertNotFound();
        $this->send('user.order.cancel',['trade_no'=>'missing'])->assertNotFound();
        foreach ([1,2,3,4] as $status) { $order=$this->pending(['status'=>$status]);$this->cancel($order)->assertStatus(409);
            $this->assertSame($status,(int)$order->fresh()->status); }
        $this->assertSame(0,(int)$this->user->fresh()->balance);
    }

    public function test_closed_input_duplicate_utf8_and_version_validation_never_refund(): void
    {
        $order=$this->pending();
        foreach ([[],['trade_no'=>1],['trade_no'=>''],['trade_no'=>str_repeat('x',129)],['trade_no'=>"bad\n"],
            ['trade_no'=>'../private'],['trade_no'=>$order->trade_no,'owner_id'=>(string)$this->user->id],
            ['trade_no'=>$order->trade_no,'id'=>'1'],['trade_no'=>$order->trade_no,'idempotency_key'=>'private']] as $input) {
            $this->send('user.order.cancel',$input)->assertStatus(400);
        }
        $this->send('user.order.cancel',['trade_no'=>$order->trade_no],'2')->assertStatus(400);
        $this->send('user.order.cancel',rawInput:'{"trade_no":"a","trade_no":"b"}')->assertStatus(400);
        $this->assertSame(0,(int)$order->fresh()->status);$this->assertDatabaseCount('tx_internal_readonly_nonces',0);
    }

    public function test_verified_request_nonce_replay_never_cancels_twice(): void
    {
        $order=$this->pending();$path='/txapi/internal/v1/operations/user.order.cancel';
        $body=json_encode(['contract_version'=>'1','request_id'=>(string)Str::uuid(),'deadline_at'=>time()+5,'input'=>['trade_no'=>$order->trade_no]]);
        $proof=JWT::encode(['v'=>'internal-readonly/1','epoch'=>1,'iss'=>'txboard-local-tls-boundary','aud'=>'txboard-core-local',
            'service'=>'user-gateway','cert_sha256'=>str_repeat('a',64),'method'=>'POST','path'=>$path,'body_sha256'=>hash('sha256',$body),
            'actor_sha256'=>hash('sha256',$this->token),'iat'=>time(),'nbf'=>time()-2,'exp'=>time()+10,'jti'=>bin2hex(random_bytes(16))],$this->private,'RS256','user-1');
        $send=function()use($path,$body,$proof){
            \Illuminate\Support\Facades\Auth::forgetGuards();
            return $this->call('POST',$path,[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json',
                'HTTP_AUTHORIZATION'=>$this->token,'HTTP_X_TXBOARD_SERVICE_PROOF'=>$proof],$body);
        };
        $send()->assertOk();$send()->assertStatus(409)->assertJsonPath('error.code','SERVICE_REPLAY');
        $this->assertSame(200,(int)$this->user->fresh()->balance);$this->assertDatabaseCount('tx_internal_readonly_audits',1);
    }

    public function test_audit_failure_hides_committed_refund_and_retry_is_conflict_not_compensation(): void
    {
        $order=$this->pending();$this->rejectAudit();
        $this->cancel($order)->assertStatus(503)->assertJsonPath('error.code','DEPENDENCY_UNAVAILABLE');
        DB::unprepared('DROP TRIGGER reject_internal_audit');
        $this->assertSame(2,(int)$order->fresh()->status);$this->assertSame(200,(int)$this->user->fresh()->balance);
        $this->cancel($order)->assertStatus(409);$this->assertSame(200,(int)$this->user->fresh()->balance);
    }

    public function test_before_exception_rolls_back_and_caught_diagnostic_never_contains_exception_args_or_previous(): void
    {
        $order=$this->pending();
        \Illuminate\Support\Facades\Log::shouldReceive('error')->once()->with('ORDER_CANCELLATION_FAILED');
        \App\Services\Plugin\HookManager::register('order.cancel.before', function ($order): void {
            throw new \RuntimeException('private-before-'.$order->trade_no, 0, new \RuntimeException('Bearer private-previous-token'));
        });
        $response=$this->cancel($order)->assertStatus(409);$this->assertSame(0,(int)$order->fresh()->status);
        $this->assertSame(0,(int)$this->user->fresh()->balance);$this->assertStringNotContainsString('private-before',$response->getContent());
    }

    public function test_after_exception_preserves_false_after_commit_and_diagnostic_is_constant(): void
    {
        $order=$this->pending();$calls=0;
        \Illuminate\Support\Facades\Log::shouldReceive('error')->once()->with('ORDER_CANCELLATION_FAILED');
        \App\Services\Plugin\HookManager::register('order.cancel.after', function ($order) use (&$calls): void {
            $calls++; throw new \RuntimeException('private-after-'.$order->trade_no,0,new \RuntimeException('private-previous'));
        });
        $this->cancel($order)->assertStatus(409);$this->assertSame(2,(int)$order->fresh()->status);
        $this->assertSame(200,(int)$this->user->fresh()->balance);$this->assertSame(1,$calls);
        $this->cancel($order)->assertStatus(409);$this->assertSame(1,$calls);$this->assertSame(200,(int)$this->user->fresh()->balance);
    }

    public function test_sql_refund_failure_rolls_back_order_and_emits_only_constant_diagnostic(): void
    {
        $order=$this->pending();
        DB::unprepared(DB::getDriverName()==='sqlite'
            ? "CREATE TRIGGER reject_refund BEFORE UPDATE ON tx_user BEGIN SELECT RAISE(ABORT,'private-sql-refund'); END"
            : "CREATE TRIGGER reject_refund BEFORE UPDATE ON tx_user FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='private-sql-refund'");
        \Illuminate\Support\Facades\Log::shouldReceive('error')->once()->with('ORDER_CANCELLATION_FAILED');
        $response=$this->cancel($order)->assertStatus(409);$this->assertSame(0,(int)$order->fresh()->status);
        $this->assertSame(0,(int)$this->user->fresh()->balance);$this->assertStringNotContainsString('private-sql',$response->getContent());
    }


    private function rejectAudit(): void
    {
        DB::unprepared(DB::getDriverName()==='sqlite'
            ? "CREATE TRIGGER reject_internal_audit BEFORE INSERT ON tx_internal_readonly_audits BEGIN SELECT RAISE(ABORT,'private-audit'); END"
            : "CREATE TRIGGER reject_internal_audit BEFORE INSERT ON tx_internal_readonly_audits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='private-audit'");
    }
    private function pending(array $patch=[]): Order
    {
        return Order::create(array_replace(['user_id'=>$this->user->id,'plan_id'=>$this->plan->id,'trade_no'=>'cancel-'.bin2hex(random_bytes(8)),
            'period'=>'monthly','type'=>1,'status'=>0,'total_amount'=>800,'balance_amount'=>200,'discount_amount'=>0,
            'callback_no'=>'private-callback-reference'], $patch));
    }
    private function cancel(Order $order) { return $this->send('user.order.cancel',['trade_no'=>$order->trade_no]); }

    private function input(): array
    {
        return ['plan_id' => (string) $this->plan->id, 'period' => 'monthly', 'idempotency_key' => 'original-business-key'];
    }

    private function send(string $operation, array $input = [], string $version = '1', ?string $token = null, ?string $rawInput = null, string $service = 'user-gateway')
    {
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $token ??= $this->token;
        $body = json_encode(['contract_version' => $version, 'request_id' => (string) Str::uuid(),
            'deadline_at' => time()+5, 'input' => (object) $input], JSON_PRESERVE_ZERO_FRACTION);
        if ($rawInput !== null) {
            $body = substr($body, 0, strpos($body, '"input":')+8).$rawInput.'}';
        }
        $path = '/txapi/internal/v1/operations/'.$operation;
        $proof = JWT::encode(['v' => 'internal-readonly/1', 'epoch' => 1, 'iss' => 'txboard-local-tls-boundary',
            'aud' => 'txboard-core-local', 'service' => $service, 'cert_sha256' => str_repeat('a', 64),
            'method' => 'POST', 'path' => $path, 'body_sha256' => hash('sha256', $body), 'actor_sha256' => hash('sha256', $token),
            'iat' => time(), 'nbf' => time()-2, 'exp' => time()+10, 'jti' => bin2hex(random_bytes(16))], $this->private, 'RS256', $service === 'user-gateway' ? 'user-1' : 'admin-1');
        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $token, 'HTTP_X_TXBOARD_SERVICE_PROOF' => $proof], $body);
    }
}
