<?php
// Test-only router: fixed reviewed fault modes, never an HTTP-controlled hook.
require getenv('INTERNAL_CORE_API').'/vendor/autoload.php';
$app=require getenv('INTERNAL_CORE_API').'/bootstrap/app.php';
$request=Illuminate\Http\Request::capture();$app->instance('request',$request);Illuminate\Support\Facades\Facade::clearResolvedInstance('request');
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$kernel->bootstrap();
if(!$app->environment('testing')||config('database.default')!=='mysql'||config('database.connections.mysql.database')!=='txboard_order_cancel_test')throw new RuntimeException('Synthetic cancellation router required');
$dir=getenv('INTERNAL_FIXTURE_DIR');$mode=is_file($dir.'/hook-mode')?file_get_contents($dir.'/hook-mode'):'none';
if(in_array($mode,['before-fail','after-fail'],true)){
 App\Services\Plugin\HookManager::register($mode==='before-fail'?'order.cancel.before':'order.cancel.after',function($order):void{
  throw new RuntimeException('private-hook-argument-'.$order->trade_no,0,new RuntimeException('Bearer private-previous-secret'));
 });
}
if(is_file($dir.'/capture-mode')&&file_get_contents($dir.'/capture-mode')==='on'){
 config(['legacy_capture_shadow.enabled'=>true,'legacy_capture_shadow.run_key'=>'transport-cancel','legacy_capture_shadow.billing_timezone'=>'UTC','legacy_capture_shadow.currency'=>'USD']);
}
$response=$kernel->handle($request);
if($response->getStatusCode()>=400){
 $payload=json_decode($response->getContent(),true);$fields=$payload['error']['fields']??[];
 file_put_contents($dir.'/rejections.jsonl',json_encode(['status'=>$response->getStatusCode(),'code'=>$payload['error']['code']??'UNKNOWN',
 'fields'=>array_values(array_filter($fields,static fn($field)=>in_array($field,['plan_id','period','coupon_code'],true)))],JSON_THROW_ON_ERROR)."\n",FILE_APPEND);
}
$response->send();$kernel->terminate($request,$response);
