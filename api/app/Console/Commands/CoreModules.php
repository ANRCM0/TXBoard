<?php

namespace App\Console\Commands;

use App\Core\Host\Modules\ModuleRuntime;
use Illuminate\Console\Command;

final class CoreModules extends Command
{
    protected $signature = 'core:modules';
    protected $description = 'Inspect the resolved process module composition without changing runtime state';

    public function handle(ModuleRuntime $runtime): int
    {
        $this->line(json_encode($runtime->inventory(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
