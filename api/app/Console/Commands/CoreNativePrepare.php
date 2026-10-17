<?php

namespace App\Console\Commands;

use App\Core\Host\Configuration\TrustedConfiguration;
use App\Domains\Installation\NativePreparation;
use Illuminate\Console\Command;

final class CoreNativePrepare extends Command
{
    protected $signature = 'core:native-prepare {--config-file=}';
    protected $description = 'Prepare a fresh native installation through owner use cases; resume with the same private input file';

    public function handle(NativePreparation $preparation): int
    {
        try {
            $result = $preparation->prepare(TrustedConfiguration::object($this->option('config-file'), 262144));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (\Throwable $error) {
            $code = $error->getMessage();
            if (preg_match('/\A(?:NATIVE_PREPARATION|INSTALLATION_AUTHORITY|PUBLICATION)_[A-Z_]{1,80}\z/D', $code) !== 1) {
                $code = 'NATIVE_PREPARATION_UNAVAILABLE';
            }
            $this->line(json_encode(['error_code' => $code]));
            return self::FAILURE;
        }
    }
}
