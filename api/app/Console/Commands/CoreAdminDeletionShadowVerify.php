<?php

namespace App\Console\Commands;

use App\Services\Subscription\LegacyAdminDeletionShadow;
use App\Services\Subscription\LegacyCaptureShadowException;
use Illuminate\Console\Command;

final class CoreAdminDeletionShadowVerify extends Command
{
    protected $signature = 'core:admin-deletion-shadow-verify {--user=} {--isolated-copy}';
    protected $description = 'Read-only verify explicitly captured native Admin account deletion evidence';
    public function handle(LegacyAdminDeletionShadow $service): int
    {
        $id = $this->option('user');
        if (!$this->option('isolated-copy') || !is_string($id) || !preg_match('/\A[1-9][0-9]{0,9}\z/D', $id) || (int) $id > 2147483647) {
            $this->error('DELETION_SHADOW_EXPLICIT_ISOLATED_USER_REQUIRED'); return self::FAILURE;
        }
        try {
            $report = $service->verify((int) $id);
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return $report['source_absent'] ? self::SUCCESS : self::FAILURE;
        } catch (LegacyCaptureShadowException $exception) {
            $this->error($exception->getMessage()); return self::FAILURE;
        }
    }
}
