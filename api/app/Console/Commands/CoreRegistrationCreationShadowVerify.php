<?php

namespace App\Console\Commands;

use App\Services\Subscription\LegacyRegistrationCreationShadow;
use App\Services\Subscription\LegacyCaptureShadowException;
use Illuminate\Console\Command;

final class CoreRegistrationCreationShadowVerify extends Command
{
    protected $signature = 'core:registration-creation-shadow-verify {--user=} {--isolated-copy}';
    protected $description = 'Read-only verify explicitly captured native registration account creation evidence';

    public function handle(LegacyRegistrationCreationShadow $service): int
    {
        $id = $this->option('user');
        if (!$this->option('isolated-copy') || !is_string($id) || !preg_match('/\A[1-9][0-9]{0,9}\z/D', $id)
            || (int) $id > 2147483647) {
            $this->error('REGISTRATION_SHADOW_EXPLICIT_ISOLATED_USER_REQUIRED'); return self::FAILURE;
        }
        try {
            $report = $service->verify((int) $id);
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return $report['source_matches_initial'] ? self::SUCCESS : self::FAILURE;
        } catch (LegacyCaptureShadowException $exception) {
            $this->error($exception->getMessage()); return self::FAILURE;
        }
    }
}
