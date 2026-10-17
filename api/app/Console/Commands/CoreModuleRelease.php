<?php

namespace App\Console\Commands;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\InstallationAuthorityException;
use Illuminate\Console\Command;

final class CoreModuleRelease extends Command
{
    protected $signature = 'core:module-release {operation=preflight} {--key=} {--expected=} {--candidate=}';
    protected $description = 'Inspect or explicitly approve the fixed module/image composition without changing installation authority';

    public function handle(InstallationAuthority $installation): int
    {
        try {
            $operation = $this->argument('operation');
            if ($operation === 'preflight') $result = $installation->modulePreflight();
            elseif ($operation === 'adopt') {
                $expected = $this->option('expected');
                $result = $installation->adoptModuleRelease((string) $this->option('key'),
                    $expected === 'none' ? null : (is_string($expected) ? $expected : ''), (string) $this->option('candidate'));
            } else throw new \InvalidArgumentException('MODULE_RELEASE_COMMAND_INVALID');
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->line(json_encode(['error_code' => $error instanceof InstallationAuthorityException
                ? $error->getMessage() : 'MODULE_RELEASE_UNAVAILABLE']));
            return self::FAILURE;
        }
    }
}
