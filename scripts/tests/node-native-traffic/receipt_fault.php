<?php

use App\Jobs\TrafficBatchJob;
use App\Domains\Network\NativeTrafficReceipt;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require rtrim((string) getenv('NATIVE_TRAFFIC_API'), '/') . '/vendor/autoload.php';
$app = require rtrim((string) getenv('NATIVE_TRAFFIC_API'), '/') . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (app()->environment() !== 'testing' || config('database.default') !== 'mysql'
    || DB::connection()->getDatabaseName() !== 'txboard_native_traffic_test'
    || !str_starts_with((string) config('database.redis.options.prefix'), 'native-traffic-')
    || !function_exists('posix_kill') || !defined('SIGKILL')
    || config('native_traffic_receipts.enabled') !== true) {
    throw new RuntimeException('NATIVE_TRAFFIC_FAULT_EXCLUSIVE_FIXTURE_REQUIRED');
}
$stage = $argv[1] ?? '';
$id = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT);
if (!in_array($stage, ['before-ledger-commit', 'after-ledger-commit'], true) || $id === false || $id <= 0) {
    throw new RuntimeException('NATIVE_TRAFFIC_FAULT_INPUT_INVALID');
}
$row = DB::table('tx_native_traffic_receipt')->where('id', $id)->first();
if ($row === null || $row->status !== 'pending' || DB::table('tx_traffic_batch')->where([
    'server_id' => $row->node_id, 'batch_id' => $row->batch_id,
])->exists()) throw new RuntimeException('NATIVE_TRAFFIC_FAULT_PENDING_ORIGINAL_REQUIRED');
// Verify the original immutable input via production code before installing a
// process fault. No usage or prepared batch is manufactured by this helper.
$input = json_decode($row->settlement_input, true, 64, JSON_THROW_ON_ERROR);
$receipts = app(NativeTrafficReceipt::class);
$receipts->verifySettlementInput((int) $row->id, $input['server'], $input['data'],
    $input['protocol'], $input['record_at'], $row->batch_id, $input['segments'] ?? null);
$kill = static function (string $observed) use ($stage, $row): never {
    echo json_encode(['observed_stage' => $observed, 'receipt_id' => (int) $row->id,
        'batch_id' => $row->batch_id], JSON_THROW_ON_ERROR) . "\n";
    fflush(STDOUT);
    posix_kill(getmypid(), SIGKILL);
    throw new RuntimeException('NATIVE_TRAFFIC_SIGKILL_UNAVAILABLE');
};
if ($stage === 'before-ledger-commit') {
    DB::listen(static function (QueryExecuted $query) use ($kill): void {
        $sql = strtolower($query->sql);
        if (DB::transactionLevel() > 0 && str_contains($sql, 'tx_traffic_batch')
            && str_starts_with(ltrim($sql), 'insert')) $kill('before-ledger-commit');
    });
} else {
    Event::listen(TransactionCommitted::class, static function () use ($row, $kill): void {
        if (DB::transactionLevel() === 0 && DB::table('tx_traffic_batch')->where([
            'server_id' => $row->node_id, 'batch_id' => $row->batch_id,
        ])->exists() && DB::table('tx_native_traffic_receipt')->where('id', $row->id)->value('status') === 'pending') {
            $kill('after-ledger-commit');
        }
    });
}
$receipts->recover((int) $row->id);
throw new RuntimeException('NATIVE_TRAFFIC_FAULT_POINT_NOT_REACHED');
