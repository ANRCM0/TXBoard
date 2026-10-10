<?php

namespace App\Console\Commands;

use App\Services\ThemeService;
use App\Services\VersionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use App\Services\Plugin\PluginManager;

class TxboardUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'txboard:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'TXBoard runtime migration and extension refresh';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('正在导入数据库请稍等...');
        $migrateExitCode = Artisan::call("migrate", ['--force' => true, '--no-interaction' => true]);
        $this->info(Artisan::output());
        if ($migrateExitCode !== 0) {
            $this->error("数据库迁移失败，阻止插件与主题刷新。请核对备份、迁移日志与数据库结构。");
            return self::FAILURE;
        }
        $this->info('正在检查并安装默认插件...');
        PluginManager::installDefaultPlugins();
        app(PluginManager::class)->publishInstalledAssets();
        $this->info('默认插件与插件前端资源检查完成');
        app(VersionService::class)->refreshCache();
        $themeService = app(ThemeService::class);
        $themeService->refreshCurrentTheme();
        if (config('queue.default') === 'sync') {
            $this->info('horizon:terminate skipped (sync queue, no workers to terminate).');
        } else {
            try {
                Artisan::call('horizon:terminate');
            } catch (\Throwable $e) {
                $this->warn('horizon:terminate skipped: ' . $e->getMessage());
            }
        }
        $this->info('运行时迁移与扩展刷新完成。');
    }
}
