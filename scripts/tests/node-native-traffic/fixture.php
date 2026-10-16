<?php
$api=getenv('NATIVE_TRAFFIC_API');
if(!is_string($api)||!str_starts_with($api,'/'))throw new RuntimeException('NATIVE_TRAFFIC_API_REQUIRED');
require $api.'/vendor/autoload.php';$app=require $api.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
if(!app()->environment('testing')||config('database.default')!=='mysql'||DB::connection()->getDatabaseName()!=='txboard_native_traffic_test'||config('database.connections.mysql.host')!=='127.0.0.1'||config('database.connections.mysql.username')!=='txboard_native_traffic'||config('node_ws.native_enabled')!==true||config('native_traffic_receipts.enabled')!==true||config('queue.default')!=='redis'||config('cache.default')!=='redis')throw new RuntimeException('NATIVE_TRAFFIC_EXCLUSIVE_FIXTURE_REQUIRED');
$mode=$argv[1]??'';$directory=getenv('NATIVE_TRAFFIC_DIR');
if($mode==='seed'){
 foreach(['tx_user','tx_plan','tx_server','tx_server_group','tx_traffic_batch','tx_native_traffic_receipt']as $table)if(DB::table($table)->exists())throw new RuntimeException('NATIVE_TRAFFIC_NONEMPTY_FIXTURE_REFUSED');
 $protocol=getenv('NATIVE_TRAFFIC_PROTOCOL');$port=(int)getenv('NATIVE_TRAFFIC_NODE_PORT');$origin=(int)getenv('NATIVE_TRAFFIC_ORIGIN_PORT');
 if(!in_array($protocol,['vless','vmess'],true)||$port<1024||$origin<1024||$port===$origin)throw new RuntimeException('NATIVE_TRAFFIC_PORT_REFUSED');
 $group=ServerGroup::unguarded(fn()=>ServerGroup::create(['name'=>'isolated-traffic-group']));
 $plan=Plan::create(['name'=>'isolated-traffic-plan','group_id'=>$group->id,'transfer_enable'=>1,'prices'=>[Plan::PERIOD_MONTHLY=>1],'show'=>true,'sell'=>true,'renew'=>true,'reset_traffic_method'=>Plan::RESET_TRAFFIC_MONTHLY]);
 $node=Server::withoutEvents(fn()=>Server::create(['name'=>'isolated-traffic-node','type'=>$protocol,'host'=>'127.0.0.1','port'=>$port,'server_port'=>$port,'rate'=>1.5,'group_ids'=>[(string)$group->id],'enabled'=>true,'show'=>true,'protocol_settings'=>['tls'=>0],'custom_routes'=>[['network'=>'tcp','ip_cidr'=>['127.0.0.1/32'],'port'=>[$origin],'outbound'=>'direct']]]));
 $users=[];foreach([['primary','00112233-4455-4677-8899-aabbccddeeff',1000],['sibling','ffeeddcc-bbaa-4998-8776-554433221100',1073741824]]as [$label,$uuid,$quota]){
  $user=User::withoutEvents(fn()=>User::create(['email'=>'isolated-traffic-'.$label.'@example.test','password'=>Hash::make('SyntheticTraffic2026'),'uuid'=>$uuid,'token'=>'isolated-traffic-'.$label,'plan_id'=>$plan->id,'group_id'=>$group->id,'transfer_enable'=>$quota,'u'=>0,'d'=>0,'reset_count'=>0,'expired_at'=>time()+3600,'banned'=>false]));$users[]=['id'=>(int)$user->id,'uuid'=>$uuid,'quota'=>$quota];
 }
 User::withoutEvents(fn()=>User::create(['email'=>'native-traffic-admin@example.test','password'=>Hash::make('SyntheticTrafficAdmin2026'),'uuid'=>'00112233-4455-4677-8899-aabbccddee00','token'=>'private-traffic-admin','is_admin'=>true,'banned'=>false,'u'=>0,'d'=>0]));
 admin_setting(['server_token'=>'native-traffic-local-test-only','secure_path'=>'native_traffic_admin','captcha_enable'=>0,'password_limit_enable'=>0,'server_pull_interval'=>1,'server_push_interval'=>5]);
 echo json_encode(['node_id'=>(int)$node->id,'protocol'=>$protocol,'node_port'=>$port,'origin_port'=>$origin,'users'=>$users],JSON_THROW_ON_ERROR)."\n";exit(0);
}
$fixture=json_decode(file_get_contents($directory.'/fixture.json'),true,32,JSON_THROW_ON_ERROR);
if($mode==='state'){
 $users=DB::table('tx_user')->whereIn('id',array_column($fixture['users'],'id'))->orderBy('id')->get(['id','u','d','reset_count','transfer_enable']);
 $receipts=DB::table('tx_native_traffic_receipt')->orderBy('id')->get();
 $ledger=DB::table('tx_traffic_batch')->where('server_id',$fixture['node_id'])->orderBy('id')->get();
 $stats=DB::table('tx_stat_user')->whereIn('user_id',array_column($fixture['users'],'id'))->orderBy('id')->get(['user_id','server_rate','u','d']);
 $node=DB::table('tx_server')->where('id',$fixture['node_id'])->first(['id','rate','u','d']);
 echo json_encode(['users'=>$users,'node'=>$node,'receipts'=>$receipts,'ledger'=>$ledger,'stats'=>$stats,'period_usage'=>DB::table('tx_native_traffic_period_usage')->whereIn('user_id',array_column($fixture['users'],'id'))->orderBy('user_id')->orderBy('reset_count')->get()->toArray(),'reset_log_count'=>DB::table('tx_traffic_reset_logs')->where('user_id',$fixture['users'][0]['id'])->count()],JSON_THROW_ON_ERROR)."\n";exit(0);
}
if($mode==='drop-wakeup'){
 // Only the dedicated namespaced native traffic queue, before any worker starts.
 if(!str_starts_with((string)config('database.redis.options.prefix'),'native-traffic-'))throw new RuntimeException('NATIVE_TRAFFIC_REDIS_PREFIX_REQUIRED');
 $queued=(int)Redis::llen('queues:traffic_fetch');
 if($queued<1 || (int)Redis::zcard('queues:traffic_fetch:reserved')!==0 || (int)Redis::zcard('queues:traffic_fetch:delayed')!==0)throw new RuntimeException('NATIVE_TRAFFIC_PENDING_WAKEUP_REQUIRED');
 $keys=['queues:traffic_fetch'];$counts=[];
 foreach($keys as $key){$counts[$key]=Redis::type($key);Redis::del($key);}
 echo json_encode(['dedicated_wakeup_keys_removed'=>$counts,'queued_jobs_removed'=>$queued,'pending_receipts'=>DB::table('tx_native_traffic_receipt')->where('status','pending')->count()],JSON_THROW_ON_ERROR)."\n";exit(0);
}
throw new RuntimeException('NATIVE_TRAFFIC_MODE_REQUIRED');
