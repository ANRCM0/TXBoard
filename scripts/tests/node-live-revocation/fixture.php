<?php
require getenv('LIVE_REVOCATION_CORE_API').'/vendor/autoload.php';
$app = require getenv('LIVE_REVOCATION_CORE_API').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Domains\UsageV2\UsageSettlement;

if (!app()->environment('testing') || config('database.default') !== 'mysql'
    || DB::connection()->getDatabaseName() !== 'txboard_live_revocation_test'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || config('database.connections.mysql.username') !== 'txboard_live_revocation'
    || config('core_usage_v2.isolated_copy') !== true) {
    fwrite(STDERR, "LIVE_REVOCATION_EXCLUSIVE_FIXTURE_REQUIRED\n"); exit(2);
}
$mode = $argv[1] ?? '';
$directory = getenv('LIVE_REVOCATION_DIR');
if (!is_string($directory) || !str_starts_with($directory, '/')) throw new RuntimeException('LIVE_REVOCATION_PATH_REQUIRED');
function decodedArtifact(string $path): array {
    if (!is_file($path) || is_link($path) || filesize($path)>1048576) throw new RuntimeException('LIVE_REVOCATION_ARTIFACT_REQUIRED');
    return json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
}
function assertLegacyUnbilled(): void {
    $user=DB::table('tx_user')->where('id',7)->first();
    if (!$user || (string)$user->u!=='0' || (string)$user->d!=='0'
        || DB::table('tx_traffic_batch')->exists()) throw new RuntimeException('LIVE_REVOCATION_LEGACY_DOUBLE_BILLING');
}
function validateCapture(array $capture): array {
    $p=$capture['submission']['payload']; $entry=$p['entries'][0] ?? [];
    if (count($p['entries'])!==1 || $capture['request_bytes']!==43 || $capture['response_bytes']!==79
        || $entry['raw_up_bytes']!=='43' || $entry['raw_down_bytes']!=='79'
        || $capture['request_sha256']!==hash('sha256',str_repeat('u',43))
        || $capture['response_sha256']!==hash('sha256',str_repeat('D',79))
        || !($capture['actual_service_run']??false) || !($capture['actual_singbox_kernel']??false)
        || !($capture['native_traffic_absent']??false))
        throw new RuntimeException('LIVE_REVOCATION_DECODED_DIRECTION_MISMATCH');
    $heartbeat=$capture['scheduled_heartbeat']??[];
    if (($heartbeat['path']??'')!=='/txapi/node/v1/report' || ($heartbeat['status']??0)!==202
        || ($heartbeat['protocol_version']??0)!==1 || ($heartbeat['accepted']??false)!==true
        || ($heartbeat['traffic_present']??true)!==false || ($heartbeat['traffic_batch_present']??true)!==false
        || ($heartbeat['settlement']??'')!=='none') throw new RuntimeException('LIVE_REVOCATION_SCHEDULED_REPORT_REQUIRED');
    return $p;
}
if ($mode==='seed') {
    $protocol=getenv('LIVE_REVOCATION_PROTOCOL'); $port=getenv('LIVE_REVOCATION_NODE_PORT'); $origin=getenv('LIVE_REVOCATION_ORIGIN_PORT');
    if (!in_array($protocol,['vless','vmess'],true) || !ctype_digit($port) || (int)$port<1024 || (int)$port>65535
        || !ctype_digit($origin) || (int)$origin<1024 || (int)$origin>65535 || $origin===$port)
        throw new RuntimeException('LIVE_REVOCATION_SYNTHETIC_PROTOCOL_REQUIRED');
    foreach (['tx_subscription','tx_server','tx_usage_batch','tx_auth_snapshot','tx_user','tx_traffic_batch'] as $table)
        if (DB::table($table)->exists()) throw new RuntimeException('LIVE_REVOCATION_NONEMPTY_FIXTURE_REFUSED');
    $fixture=new class { use Tests\Support\UsageV2Fixture; public function run(): array{return $this->usageFixture();} };
    $f=$fixture->run();
    \App\Models\ServerGroup::unguarded(fn()=>\App\Models\ServerGroup::create(['id'=>1,'name'=>'synthetic-live-revocation-group']));
    $f['node']->forceFill(['type'=>$protocol,'port'=>(int)$port,'server_port'=>(int)$port,'protocol_settings'=>['tls'=>0]])->saveOrFail();
    \App\Models\User::withoutEvents(function():void {
        $user=new \App\Models\User();
        $user->forceFill(['id'=>7,'email'=>'live-revocation@example.test','password'=>'synthetic-no-login',
            'uuid'=>'00112233-4455-6677-8899-aabbccddeeff','token'=>'synthetic-private-no-use',
            'plan_id'=>1,'group_id'=>1,'transfer_enable'=>1073741824,'u'=>0,'d'=>0,'expired_at'=>time()+1000,'banned'=>false])->saveOrFail();
    });
    admin_setting(['server_token'=>'live-revocation-local-test-only','secure_path'=>'live_revocation_admin']);
    $admin=\App\Models\User::withoutEvents(function(){
        $admin=new \App\Models\User();
        $admin->forceFill(['id'=>8,'email'=>'live-revocation-admin@example.test','password'=>'synthetic-no-login',
            'uuid'=>'00112233-4455-6677-8899-aabbccddee00','token'=>'synthetic-admin-private-no-use','is_admin'=>true,
            'plan_id'=>null,'group_id'=>null,'u'=>0,'d'=>0,'banned'=>false])->saveOrFail();return $admin;
    });
    $private=['admin_token'=>$admin->createToken('live-revocation-admin',['*'])->plainTextToken];
    $privatePath=$directory.'/private-admin.json';file_put_contents($privatePath,json_encode($private,JSON_THROW_ON_ERROR));chmod($privatePath,0600);
    echo json_encode(['node_id'=>(string)$f['node']->id,'protocol'=>$protocol,'node_port'=>(int)$port,'origin_port'=>(int)$origin,
        'period_id'=>(string)$f['period'],'subscription_id'=>(string)$f['sub'],'subject_id'=>(string)$f['subject'],
        'grant_id'=>(string)$f['grant']->id,'terms_id'=>(string)$f['terms'],'tariff_id'=>(string)$f['tariff']->id,
        'tariff_numerator'=>'3','tariff_denominator'=>'2'],JSON_THROW_ON_ERROR);
} elseif ($mode==='assert-pending' || $mode==='settle') {
    $capture=decodedArtifact($directory.'/history-capture.json'); $p=validateCapture($capture); $r=$capture['pending_receipt'];
    assertLegacyUnbilled();
    $batch=DB::table('tx_usage_batch')->where('node_id',$p['node_id'])->where('batch_id',$p['batch_id'])->first();
    if (!$batch || DB::table('tx_usage_batch')->count()!==1 || $batch->payload_digest!==$capture['submission']['payload_digest']
        || $batch->receipt_id!==$r['receipt_id'] || (string)$batch->source_epoch!==$p['source_epoch'] || $batch->status!=='pending'
        || DB::table('tx_usage_outbox')->where('batch_id',$batch->id)->where('kind','usage.accepted')->count()!==1)
        throw new RuntimeException('LIVE_REVOCATION_ACK_NOT_IN_SQL');
    if (DB::table('tx_usage_entry')->count()!==0 || (string)DB::table('tx_usage_period')->value('billable_bytes')!=='0')
        throw new RuntimeException('LIVE_REVOCATION_PREMATURE_SETTLEMENT');
    if ($mode==='assert-pending') {
        echo json_encode(['persisted_ack_sql_verified'=>true,'batch_rows'=>'1','entry_rows'=>'0','receipt_id'=>$batch->receipt_id,
            'scheduled_heartbeat_ack_verified'=>true,'legacy_user_u'=>'0','legacy_user_d'=>'0','legacy_batch_rows'=>'0'],JSON_THROW_ON_ERROR); exit(0);
    }
    $worker=app(UsageSettlement::class); $first=$worker->scan(10,time()); $second=$worker->scan(10,time());
    if ($first!==[$batch->id=>'settled'] || $second!==[]) throw new RuntimeException('LIVE_REVOCATION_NOT_EXACTLY_ONCE');
    $entry=DB::table('tx_usage_entry')->where('batch_id',$batch->id)->first();
    $period=DB::table('tx_usage_period')->where('id',$p['entries'][0]['usage_period_id'])->first();
    $stat=DB::table('tx_node_usage_stat')->where('node_id',$p['node_id'])->first();
    if (!$entry || DB::table('tx_usage_entry')->count()!==1 || (string)$period->upload_bytes!=='64'
        || (string)$period->download_bytes!=='118' || (string)$period->billable_bytes!=='182'
        || (string)$entry->raw_up_bytes!=='43' || (string)$entry->raw_down_bytes!=='79'
        || (string)$entry->billed_up_bytes!=='64' || (string)$entry->billed_down_bytes!=='118'
        || (string)$stat->raw_up_bytes!=='43' || (string)$stat->raw_down_bytes!=='79'
        || (string)$entry->terms_id!==$p['entries'][0]['terms_id'] || (string)$entry->usage_period_id!==$p['entries'][0]['usage_period_id'])
        throw new RuntimeException('LIVE_REVOCATION_FROZEN_SETTLEMENT_MISMATCH');
    assertLegacyUnbilled();
    echo json_encode(['status'=>'settled','sql_batch_rows'=>'1','sql_entry_rows'=>'1','raw_up_bytes'=>'43','raw_down_bytes'=>'79',
        'billed_up_bytes'=>'64','billed_down_bytes'=>'118','billable_bytes'=>'182','receipt_id'=>$batch->receipt_id,
        'second_scan_empty'=>true,'legacy_user_u'=>'0','legacy_user_d'=>'0'],JSON_THROW_ON_ERROR);
} elseif ($mode==='assert-final') {
    $capture=decodedArtifact($directory.'/history-capture.json');$p=validateCapture($capture);assertLegacyUnbilled();
    $batch=DB::table('tx_usage_batch')->where('node_id',$p['node_id'])->where('source_epoch',$p['source_epoch'])->where('batch_id',$p['batch_id'])->first();
    $period=DB::table('tx_usage_period')->where('id',$p['entries'][0]['usage_period_id'])->first();
    $stat=DB::table('tx_node_usage_stat')->where('node_id',$p['node_id'])->first();
    if (!$batch||$batch->status!=='settled'||$batch->receipt_id!==$capture['pending_receipt']['receipt_id']||DB::table('tx_usage_batch')->count()!==1
        ||DB::table('tx_usage_entry')->count()!==1||(string)$period->billable_bytes!=='182'||(string)$stat->raw_up_bytes!=='43'
        ||(string)$stat->raw_down_bytes!=='79'||app(UsageSettlement::class)->scan(10,time())!==[])
        throw new RuntimeException('LIVE_REVOCATION_UNKNOWN_TAIL_COUNTED');
    echo json_encode(['status'=>'settled','batch_rows'=>'1','entry_rows'=>'1','billable_bytes'=>'182','legacy_batch_rows'=>'0',
        'legacy_user_u'=>'0','legacy_user_d'=>'0','historical_receipt'=>$batch->receipt_id,'unknown_tail_successful_batch'=>false],JSON_THROW_ON_ERROR);
} else {throw new RuntimeException('LIVE_REVOCATION_FIXTURE_ACTION_INVALID');}
