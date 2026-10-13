<?php
require getenv('BOUNDED_USAGE_CORE_API').'/vendor/autoload.php';
$app = require getenv('BOUNDED_USAGE_CORE_API').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Domains\UsageV2\UsageSettlement;

if (!app()->environment('testing') || config('database.default') !== 'mysql'
    || DB::connection()->getDatabaseName() !== 'txboard_bounded_usage_test'
    || config('core_usage_v2.isolated_copy') !== true) {
    fwrite(STDERR, "BOUNDED_USAGE_FIXTURE_SCOPE_REQUIRED\n"); exit(2);
}
$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    foreach (['tx_subscription','tx_server','tx_usage_batch','tx_auth_snapshot'] as $table) {
        if (DB::table($table)->exists()) throw new RuntimeException('BOUNDED_USAGE_NONEMPTY_FIXTURE_REFUSED');
    }
    $fixture = new class { use Tests\Support\UsageV2Fixture;
        public function run(): array { return $this->usageFixture(); }
    };
    $f=$fixture->run();
    admin_setting(['server_token'=>'bounded-usage-local-test-only']);
    echo json_encode(['node_id'=>(string)$f['node']->id,'period_id'=>(string)$f['period'],
        'subscription_id'=>(string)$f['sub'],'subject_id'=>(string)$f['subject'],'grant_id'=>(string)$f['grant']->id,
        'tariff_id'=>(string)$f['tariff']->id,'tariff_numerator'=>'3','tariff_denominator'=>'2'], JSON_THROW_ON_ERROR);
} elseif ($mode === 'assert-pending' || $mode === 'settle') {
    $path=getenv('BOUNDED_USAGE_DIR').'/node-capture.json';
    if (!is_file($path) || filesize($path)>1048576) throw new RuntimeException('BOUNDED_USAGE_CAPTURE_MISSING');
    $capture=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    $p=$capture['submission']['payload'];$r=$capture['pending_receipt'];
    if (count($p['entries'])!==1 || $p['entries'][0]['raw_up_bytes']!=='11' || $p['entries'][0]['raw_down_bytes']!=='7')
        throw new RuntimeException('BOUNDED_USAGE_ACTUAL_DIRECTION_MISMATCH');
    $batch=DB::table('tx_usage_batch')->where('node_id',$p['node_id'])->where('batch_id',$p['batch_id'])->first();
    if (!$batch || DB::table('tx_usage_batch')->count()!==1 || $batch->payload_digest!==$capture['submission']['payload_digest']
        || $batch->receipt_id!==$r['receipt_id'] || $batch->status!=='pending'
        || !DB::table('tx_usage_outbox')->where('batch_id',$batch->id)->where('kind','usage.accepted')->exists())
        throw new RuntimeException('BOUNDED_USAGE_DURABLE_ACK_NOT_IN_SQL');
    if (DB::table('tx_usage_entry')->count()!==0 || (string)DB::table('tx_usage_period')->value('billable_bytes')!=='0')
        throw new RuntimeException('BOUNDED_USAGE_PREMATURE_SETTLEMENT');
    if ($mode==='assert-pending') {
        echo json_encode(['persisted_ack_sql_verified'=>true,'batch_rows'=>'1','entry_rows'=>'0','receipt_id'=>$batch->receipt_id],JSON_THROW_ON_ERROR);exit(0);
    }
    $worker=app(UsageSettlement::class);$first=$worker->scan(10,time());$second=$worker->scan(10,time());
    if ($first!==[$batch->id=>'settled'] || $second!==[]) throw new RuntimeException('BOUNDED_USAGE_SETTLEMENT_NOT_EXACTLY_ONCE');
    $period=DB::table('tx_usage_period')->where('id',$p['entries'][0]['usage_period_id'])->first();
    $entry=DB::table('tx_usage_entry')->where('batch_id',$batch->id)->first();$stat=DB::table('tx_node_usage_stat')->where('node_id',$p['node_id'])->first();
    if (!$entry || DB::table('tx_usage_entry')->count()!==1 || (string)$period->upload_bytes!=='16'
        || (string)$period->download_bytes!=='10' || (string)$period->billable_bytes!=='26'
        || (string)$stat->raw_up_bytes!=='11' || (string)$stat->raw_down_bytes!=='7'
        || (string)$entry->billed_up_bytes!=='16' || (string)$entry->billed_down_bytes!=='10')
        throw new RuntimeException('BOUNDED_USAGE_SETTLEMENT_TOTAL_MISMATCH');
    echo json_encode(['status'=>'settled','sql_batch_rows'=>'1','sql_entry_rows'=>'1','raw_up_bytes'=>'11','raw_down_bytes'=>'7',
        'billed_up_bytes'=>'16','billed_down_bytes'=>'10','billable_bytes'=>'26','receipt_id'=>$batch->receipt_id,'second_scan_empty'=>true],JSON_THROW_ON_ERROR);
 } elseif ($mode === 'assert-settled') {
    $result=json_decode(file_get_contents(getenv('BOUNDED_USAGE_DIR').'/node-settled.json'),true,32,JSON_THROW_ON_ERROR);
    $p=$result['submission']['payload'];$r=$result['settled_receipt'];
    $batch=DB::table('tx_usage_batch')->where('node_id',$p['node_id'])->where('batch_id',$p['batch_id'])->first();
    $period=DB::table('tx_usage_period')->where('id',$p['entries'][0]['usage_period_id'])->first();
    $stat=DB::table('tx_node_usage_stat')->where('node_id',$p['node_id'])->first();
    if (!$batch||$batch->status!=='settled'||$batch->receipt_id!==$r['receipt_id']
        ||DB::table('tx_usage_batch')->count()!==1||DB::table('tx_usage_entry')->count()!==1
        ||(string)$period->billable_bytes!=='26'||(string)$stat->raw_up_bytes!=='11'||(string)$stat->raw_down_bytes!=='7'
        ||app(UsageSettlement::class)->scan(10,time())!==[]) throw new RuntimeException('BOUNDED_USAGE_REPLAY_COUNTED_TWICE');
    echo json_encode(['batch_rows'=>'1','entry_rows'=>'1','billable_bytes'=>'26','duplicate_counted_twice'=>false,'status'=>'settled'],JSON_THROW_ON_ERROR);
} else {throw new RuntimeException('BOUNDED_USAGE_FIXTURE_ACTION_INVALID');}
