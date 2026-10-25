<?php

namespace App\Console\Commands;

use App\Domains\Installation\NativeRuntimeDiagnostics;
use Illuminate\Console\Command;

final class CoreDiagnose extends Command
{
    protected $signature = 'core:diagnose {--json : Output the versioned JSON report} {--show-admin-path : Include the private administrator entry path}';
    protected $description = 'Inspect native deployment configuration and runtime state without changing it';

    public function handle(NativeRuntimeDiagnostics $diagnostics): int
    {
        $report = $diagnostics->report((bool) $this->option('show-admin-path'));
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('TXBoard read-only diagnostics');
            $this->table(['Section', 'Status', 'Error code'], array_map(
                static fn (string $name, array $section): array => [$name, $section['status'], $section['error_code'] ?? '-'],
                array_keys($report['sections']), array_values($report['sections'])));
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        return $report['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}
