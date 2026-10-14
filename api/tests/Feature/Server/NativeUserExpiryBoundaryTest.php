<?php

namespace Tests\Feature\Server;

use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class NativeUserExpiryBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_exact_expiry_second_is_consistently_denied_by_model_and_real_node_snapshot(): void
    {
        Bus::fake();
        Carbon::setTestNow(Carbon::createFromTimestampUTC(1791954000));
        $plan = Plan::create(['name'=>'Boundary plan', 'group_id'=>1, 'transfer_enable'=>1,
            'prices'=>['monthly'=>1], 'reset_traffic_method'=>1, 'show'=>true, 'sell'=>true, 'renew'=>true]);
        $node = Server::create(['type'=>'vless', 'name'=>'Boundary node', 'host'=>'127.0.0.1',
            'port'=>'8443', 'server_port'=>8443, 'rate'=>1, 'group_ids'=>[1], 'enabled'=>true]);
        $base=['password'=>'synthetic-unused-hash', 'uuid'=>'00000000-0000-0000-0000-000000000001',
            'token'=>str_repeat('a',32), 'plan_id'=>$plan->id, 'group_id'=>1,
            'banned'=>0, 'u'=>0, 'd'=>0, 'transfer_enable'=>1000];
        $users=[];
        foreach (['before'=>1791953999, 'exact'=>1791954000, 'after'=>1791954001, 'unlimited'=>null] as $label=>$expiry) {
            $users[$label]=User::withoutEvents(fn()=>User::create($base+['email'=>$label.'@example.test','expired_at'=>$expiry]));
        }
        $this->assertFalse($users['before']->isAvailable());
        $this->assertFalse($users['exact']->isAvailable());
        $this->assertTrue($users['after']->isAvailable());
        $this->assertTrue($users['unlimited']->isAvailable());
        $expected=[$users['after']->id,$users['unlimited']->id];
        $this->assertSame($expected, ServerService::getAvailableUsers($node)->pluck('id')->all());
        admin_setting(['server_token'=>'expiry-boundary-synthetic-token']);
        $response=$this->getJson('/txapi/node/v1/users',[
            'Authorization'=>'Bearer expiry-boundary-synthetic-token', 'X-TX-Node-ID'=>(string)$node->id,
        ])->assertOk();
        $this->assertSame($expected,array_column($response->json('data.users'),'id'));
        Carbon::setTestNow(Carbon::createFromTimestampUTC(1791954001));
        $this->assertFalse($users['after']->isAvailable());
        $this->assertSame([$users['unlimited']->id],ServerService::getAvailableUsers($node)->pluck('id')->all());
    }

    public function test_node_eligibility_keeps_account_and_renewal_access_separate_from_remaining_quota(): void
    {
        Bus::fake();
        $user=User::withoutEvents(fn()=>User::create(['email'=>'exhausted@example.test',
            'password'=>'synthetic-unused-hash','uuid'=>'00000000-0000-0000-0000-000000000002',
            'token'=>str_repeat('b',32),'plan_id'=>null,'group_id'=>1,'banned'=>0,'u'=>100,'d'=>0,'transfer_enable'=>100]));
        $token=$user->createToken('native-expiry-account');
        $this->assertFalse($user->isAvailable());
        $this->withHeader('Authorization','Bearer '.$token->plainTextToken)->getJson('/txapi/me')->assertOk();
        $this->getJson('/txapi/plans')->assertOk();
    }
}
