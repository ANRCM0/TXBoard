<?php
require getenv('INTERNAL_CORE_API').'/vendor/autoload.php';
$app=require getenv('INTERNAL_CORE_API').'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || config('database.default')!=='mysql' || config('database.connections.mysql.database')!=='txboard_order_cancel_test') throw new RuntimeException('Exclusive synthetic cancellation DB required');
$mode=$argv[1]??'seed';
if($mode==='state'){
 $orders=App\Models\Order::query()->orderBy('id')->get(['status','balance_amount','total_amount','coupon_id']);
 echo json_encode(['balance'=>(int)App\Models\User::query()->where('email','cancel-user@example.test')->value('balance'),
  'coupon_slots'=>(int)App\Models\Coupon::query()->value('limit_use'),'order_statuses'=>$orders->pluck('status')->all(),
  'token_count'=>Laravel\Sanctum\PersonalAccessToken::count(),'audit_count'=>Illuminate\Support\Facades\DB::table('tx_internal_readonly_audits')->count(),
  'database_version'=>Illuminate\Support\Facades\DB::selectOne('SELECT VERSION() AS version')->version],JSON_THROW_ON_ERROR);exit;
}
if($mode==='audit-fail'){Illuminate\Support\Facades\DB::unprepared("CREATE TRIGGER cancel_audit_failure BEFORE INSERT ON tx_internal_readonly_audits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='private-cancel-audit-failure'");exit;}
if($mode==='audit-restore'){Illuminate\Support\Facades\DB::unprepared('DROP TRIGGER cancel_audit_failure');exit;}
if($mode==='hook'){
 $value=$argv[2]??'';if(!in_array($value,['none','before-fail','after-fail'],true))throw new RuntimeException('Invalid fixed hook');
 file_put_contents(getenv('INTERNAL_FIXTURE_DIR').'/hook-mode',$value);exit;
}
if($mode==='mutate'){
 $user=App\Models\User::query()->where('email','cancel-user@example.test')->firstOrFail();
 match($argv[2]??''){'ban'=>$user->update(['banned'=>true]),'unban'=>$user->update(['banned'=>false]),
 'promote'=>$user->update(['is_admin'=>true]),'demote'=>$user->update(['is_admin'=>false]),'revoke'=>$user->tokens()->delete(),default=>throw new RuntimeException('Unknown mutation')};exit;
}
if($mode==='new-order'){
 $user=App\Models\User::query()->where('email','cancel-user@example.test')->firstOrFail();
 $order=App\Models\Order::create(['user_id'=>$user->id,'plan_id'=>App\Models\Plan::query()->value('id'),'trade_no'=>'native-cancel-'.bin2hex(random_bytes(8)),
 'period'=>'monthly','type'=>1,'status'=>0,'total_amount'=>800,'balance_amount'=>100,'callback_no'=>'private-callback']);
 echo json_encode(['trade_no'=>$order->trade_no]);exit;
}
if($mode==='revoke-all'){Laravel\Sanctum\PersonalAccessToken::query()->delete();exit;}
if($mode==='audit-check'){
 $json=Illuminate\Support\Facades\DB::table('tx_internal_readonly_audits')->get()->toJson();
 foreach(['Bearer ','private-subscription','private-callback','@example.test','trade_no','password']as$secret)if(str_contains($json,$secret))throw new RuntimeException('Audit secret');echo 'safe';exit;
}
if($mode!=='seed'||App\Models\User::query()->exists())throw new RuntimeException('Empty synthetic database required');
admin_setting(['captcha_enable'=>0]);$credentials=[];$ids=[];
foreach(['user','foreign','admin','staff']as$role){
 $user=App\Models\User::create(['email'=>'cancel-'.$role.'@example.test','password'=>'synthetic-no-login','uuid'=>(string)Illuminate\Support\Str::uuid(),
 'token'=>'private-subscription-'.$role,'balance'=>$role==='user'?500:0,'is_admin'=>$role==='admin','is_staff'=>$role==='staff','banned'=>false]);
 $credentials[$role]='Bearer '.$user->createToken('fixture-current',['*'],now()->addHour())->plainTextToken;$ids[$role]=(string)$user->id;
}
$plan=App\Models\Plan::create(['name'=>'Native cancellation plan','group_id'=>1,'transfer_enable'=>1,'prices'=>['monthly'=>12],'capacity_limit'=>null,'show'=>true,'sell'=>true,'renew'=>true]);
App\Models\Coupon::create(['name'=>'Reserved native slot','code'=>'CANCELFIXTURE','type'=>1,'value'=>200,'started_at'=>0,'ended_at'=>0,'limit_use'=>1,'show'=>true]);
echo json_encode(['credentials'=>$credentials,'ids'=>$ids,'plan_id'=>$plan->id],JSON_THROW_ON_ERROR);
