<?php

namespace App\Console\Commands;

use App\Domains\Installation\Contracts\InstallationAdmission;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class CoreModuleDeliveries extends Command
{
    protected $signature = 'core:module-deliveries {--limit=50} {--retry=}';
    protected $description = 'Inspect delivery outcomes or retry an unresolved original event without replacing its snapshot';

    public function handle(InstallationAdmission $installation): int
    {
        try {
            $installation->assertNativeRuntime();
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
            if ($limit === false || $limit < 1 || $limit > 1000) throw new \RuntimeException('MODULE_WORK_LIMIT_INVALID');
            $retry = $this->option('retry');
            if ($retry !== null) {
                if (!is_string($retry) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $retry) !== 1) throw new \RuntimeException('MODULE_DELIVERY_ID_INVALID');
                DB::table('tx_module_delivery')->where('id', $retry)->whereIn('status', ['pending', 'unknown', 'sending'])
                    ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', time()))
                    ->update(['next_attempt_at' => 0]);
            }
            $items = DB::table('tx_module_delivery')->useWritePdo()->orderByDesc('id')->limit($limit)
                ->get(['id', 'event_key', 'module_code', 'package_version', 'configuration_version', 'status', 'attempts', 'next_attempt_at', 'error_code']);
            $this->line(json_encode(['deliveries' => $items], JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('MODULE_DELIVERY_INSPECTION_UNAVAILABLE');
            return self::FAILURE;
        }
    }
}
