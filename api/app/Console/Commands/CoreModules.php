<?php

namespace App\Console\Commands;

use App\Core\Host\Modules\ModuleRuntime;
use Illuminate\Console\Command;

final class CoreModules extends Command
{
    protected $signature = 'core:modules {--hooks : Include currently registered hook ownership metadata}';
    protected $description = 'Inspect the resolved process module composition without changing runtime state';

    public function handle(ModuleRuntime $runtime): int
    {
        $result = $runtime->inventory();
        if ($this->option('hooks')) {
            $result = ['composition' => $result, 'hooks' => \App\Services\Plugin\HookManager::describe()];
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
