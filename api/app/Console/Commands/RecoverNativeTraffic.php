<?php

namespace App\Console\Commands;

use App\Domains\Network\NativeTrafficReceipt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecoverNativeTraffic extends Command
{
    protected $signature = 'traffic:native-recover {--limit=100 : Maximum pending receipts processed in this run}';
    protected $description = 'Recover durable native traffic acceptances using their original settlement input';

    public function handle(NativeTrafficReceipt $receipts): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('NATIVE_TRAFFIC_RECOVERY_LIMIT_INVALID');
            return self::FAILURE;
        }
        try {
            $ids = DB::table('tx_native_traffic_receipt')->where('status', 'pending')->orderBy('id')->limit($limit)->pluck('id');
        } catch (Throwable) {
            $this->error('NATIVE_TRAFFIC_RECOVERY_STORAGE_UNAVAILABLE');
            return self::FAILURE;
        }
        $settled = $failed = 0;
        foreach ($ids as $id) {
            try {
                $receipts->recover((int) $id);
                ++$settled;
            } catch (Throwable) {
                ++$failed;
                Log::error('NATIVE_TRAFFIC_RECOVERY_FAILED', ['receipt_id' => (int) $id]);
                try { $receipts->recordRecoveryFailure((int) $id); } catch (Throwable) { Log::error('NATIVE_TRAFFIC_RECOVERY_FAILURE_RECORD_UNAVAILABLE'); }
            }
        }
        $this->line("Recovered {$settled} pending native receipts; {$failed} failed.");
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
