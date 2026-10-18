<?php

namespace App\Console\Commands;

use App\Core\Host\Modules\ModuleDeliveryWorker;
use Illuminate\Console\Command;

final class CoreModuleWork extends Command
{
    protected $signature = 'core:module-work {--limit=100}';
    protected $description = 'Recover durable extension notification deliveries with their original event IDs';

    public function handle(ModuleDeliveryWorker $worker): int
    {
        try {
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
            if ($limit === false) throw new \InvalidArgumentException('MODULE_WORK_LIMIT_INVALID');
            $this->line(json_encode($worker->run($limit), JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('MODULE_WORK_UNAVAILABLE');
            return self::FAILURE;
        }
    }
}
