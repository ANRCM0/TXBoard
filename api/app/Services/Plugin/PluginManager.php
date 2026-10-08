<?php

namespace App\Services\Plugin;

use App\Http\Middleware\EnsurePluginEnabled;
use App\Models\Plugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PluginManager
{
    protected string $pluginPath;
    protected string $corePluginPath;
    protected array $loadedPlugins = [];
    protected bool $pluginsInitialized = false;
    protected array $configTypesCache = [];

    public function __construct(
        protected PluginPackage $pluginPackage
    ) {
        $this->pluginPath = base_path('plugins');
        $this->corePluginPath = base_path('plugins-core');
    }

    /**
     * 获取插件的命名空间
     */
    public function getPluginNamespace(string $pluginCode): string
    {
        return 'Plugin\\' . Str::studly($pluginCode);
    }

    public function resolvePluginPath(string $pluginCode): ?string
    {
        $dirName = Str::studly($pluginCode);
        $corePath = $this->corePluginPath . '/' . $dirName;
        if (File::isDirectory($corePath)) {
            return $corePath;
        }
        $userPath = $this->pluginPath . '/' . $dirName;
        if (File::isDirectory($userPath)) {
            return $userPath;
        }
        return null;
    }

    public function getPluginPath(string $pluginCode): string
    {
        return $this->resolvePluginPath($pluginCode)
            ?? $this->pluginPath . '/' . Str::studly($pluginCode);
    }

    public function getUserPluginPath(string $pluginCode): string
    {
        return $this->pluginPath . '/' . Str::studly($pluginCode);
    }

    public function isCorePlugin(string $pluginCode): bool
    {
        $dirName = Str::studly($pluginCode);
        return File::isDirectory($this->corePluginPath . '/' . $dirName);
    }

    public function getPluginPaths(): array
    {
        return [$this->corePluginPath, $this->pluginPath];
    }

    public function getPublicAssetBase(string $pluginCode): string
    {
        return $this->pluginPackage->publicAssetBase($pluginCode);
    }

    /**
     * 加载插件类
     */
    protected function loadPlugin(string $pluginCode): ?AbstractPlugin
    {
        if (isset($this->loadedPlugins[$pluginCode])) {
            return $this->loadedPlugins[$pluginCode];
        }

        $pluginClass = $this->getPluginNamespace($pluginCode) . '\\Plugin';

        if (!class_exists($pluginClass)) {
            $pluginFile = $this->getPluginPath($pluginCode) . '/Plugin.php';
            if (!File::exists($pluginFile)) {
                Log::warning("Plugin class file not found: {$pluginFile}");
                return null;
            }
            require_once $pluginFile;
        }

        if (!class_exists($pluginClass)) {
            Log::error("Plugin class not found: {$pluginClass}");
            return null;
        }

        $plugin = new $pluginClass($pluginCode);
        $this->loadedPlugins[$pluginCode] = $plugin;

        return $plugin;
    }

    /**
     * 注册插件的服务提供者
     */
    protected function registerServiceProvider(string $pluginCode): void
    {
        $providerClass = $this->getPluginNamespace($pluginCode) . '\\Providers\\PluginServiceProvider';

        if (class_exists($providerClass)) {
            app()->register($providerClass);
        }
    }

    /**
     * 加载插件的路由
     */
    protected function loadRoutes(string $pluginCode): void
    {
        $routesPath = $this->getPluginPath($pluginCode) . '/routes';
        if (File::exists($routesPath)) {
            $webRouteFile = $routesPath . '/web.php';
            $apiRouteFile = $routesPath . '/api.php';
            if (File::exists($webRouteFile)) {
                Route::middleware(['web', EnsurePluginEnabled::class . ':' . $pluginCode])
                    ->namespace($this->getPluginNamespace($pluginCode) . '\\Controllers')
                    ->group(function () use ($webRouteFile) {
                        require $webRouteFile;
                    });
            }
            if (File::exists($apiRouteFile)) {
                Route::middleware(['api', EnsurePluginEnabled::class . ':' . $pluginCode])
                    ->namespace($this->getPluginNamespace($pluginCode) . '\\Controllers')
                    ->group(function () use ($apiRouteFile) {
                        require $apiRouteFile;
                    });
            }
        }
    }

    /**
     * 加载插件的视图
     */
    protected function loadViews(string $pluginCode): void
    {
        $viewsPath = $this->getPluginPath($pluginCode) . '/resources/views';
        if (File::exists($viewsPath)) {
            View::addNamespace(Str::studly($pluginCode), $viewsPath);
            return;
        }
    }

    /**
     * 注册插件命令
     */
    protected function registerPluginCommands(string $pluginCode, AbstractPlugin $pluginInstance): void
    {
        try {
            // 调用插件的命令注册方法
            $pluginInstance->registerCommands();
        } catch (\Exception $e) {
            Log::error("Failed to register commands for plugin '{$pluginCode}': " . $e->getMessage());
        }
    }

    /**
     * 安装插件
     */
    public function install(string $pluginCode): bool
    {
        $configFile = $this->getPluginPath($pluginCode) . '/config.json';

        if (!File::exists($configFile)) {
            throw new \Exception('Plugin config file not found');
        }

        $config = json_decode(File::get($configFile), true);
        if (!$this->validateConfig($config)) {
            throw new \Exception('Invalid plugin config');
        }
        $this->pluginPackage->assertDeclaredAdminAppsExist($this->getPluginPath($pluginCode), $config);

        // 检查插件是否已安装
        if (Plugin::where('code', $pluginCode)->exists()) {
            throw new \Exception('Plugin already installed');
        }

        // Check manifest versions before any migration or filesystem action.
        $this->assertDependencies($config['require'] ?? []);

        if (($config['code'] ?? null) !== $pluginCode) {
            throw new \RuntimeException('Plugin manifest identity does not match requested installation');
        }

        // Missing plugin PHP must be rejected before running migrations.
        $plugin = $this->loadPlugin($pluginCode);
        if (!$plugin) {
            throw new \RuntimeException('Plugin implementation not found: ' . $pluginCode);
        }

        $this->runMigrations(pluginCode: $pluginCode);

        DB::beginTransaction();
        try {
            $defaultValues = $this->extractDefaultConfig($config);

            // 注册到数据库
            Plugin::create([
                'code' => $pluginCode,
                'name' => $config['name'],
                'version' => $config['version'],
                'type' => $config['type'] ?? Plugin::TYPE_FEATURE,
                'is_enabled' => false,
                'config' => json_encode($defaultValues),
                'installed_at' => now(),
            ]);

            // 运行插件安装方法
            if (method_exists($plugin, 'install')) {
                $plugin->install();
            }

            // 发布插件资源
            $this->publishAssets($pluginCode);

            DB::commit();
            return true;
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            HookManager::removeOwner($pluginCode);
            throw $e;
        }
    }

    /**
     * 提取插件默认配置
     */
    protected function extractDefaultConfig(array $config): array
    {
        $defaultValues = [];
        if (isset($config['config']) && is_array($config['config'])) {
            foreach ($config['config'] as $key => $item) {
                if (is_array($item)) {
                    $defaultValues[$key] = $item['default'] ?? null;
                } else {
                    $defaultValues[$key] = $item;
                }
            }
        }
        return $defaultValues;
    }

    /**
     * 获取 Migrator 实例并确保迁移仓库存在
     */
    protected function getMigrator(): \Illuminate\Database\Migrations\Migrator
    {
        $migrator = app('migrator');

        if (!$migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        return $migrator;
    }

    /**
     * 运行插件数据库迁移
     */
    protected function runMigrations(string $pluginCode): void
    {
        $migrationsPath = $this->getPluginPath($pluginCode) . '/database/migrations';

        if (File::exists($migrationsPath)) {
            $migrator = $this->getMigrator();
            $migrator->run([$migrationsPath]);
        }
    }

    /**
     * 回滚插件数据库迁移
     */
    protected function runMigrationsRollback(string $pluginCode): void
    {
        $migrationsPath = $this->getPluginPath($pluginCode) . '/database/migrations';

        if (File::exists($migrationsPath)) {
            $migrator = $this->getMigrator();
            $migrator->rollback([$migrationsPath]);
        }
    }

    /**
     * 发布插件资源
     */
    public function publishAssets(string $pluginCode): void
    {
        $pluginPath = $this->getPluginPath($pluginCode);
        $legacyAssetsPath = $pluginPath . '/resources/assets';
        $adminDistPath = $pluginPath . '/admin/dist';

        if (!File::isDirectory($legacyAssetsPath) && !File::isDirectory($adminDistPath)) {
            return;
        }

        $publishPath = public_path('plugins/' . $pluginCode);
        if (File::isDirectory($publishPath)) {
            File::deleteDirectory($publishPath);
        }
        File::ensureDirectoryExists($publishPath);

        if (File::isDirectory($legacyAssetsPath)) {
            File::copyDirectory($legacyAssetsPath, $publishPath);
        }

        if (File::isDirectory($adminDistPath)) {
            $adminPublishPath = $publishPath . '/admin';
            File::ensureDirectoryExists($adminPublishPath);
            File::copyDirectory($adminDistPath, $adminPublishPath);
        }
    }

    public function publishInstalledAssets(): void
    {
        Plugin::query()->pluck('code')->each(function (string $pluginCode): void {
            if ($this->resolvePluginPath($pluginCode)) {
                $this->publishAssets($pluginCode);
            }
        });
    }

    protected function removePublishedAssets(string $pluginCode): void
    {
        $publishPath = public_path('plugins/' . $pluginCode);
        if (File::isDirectory($publishPath)) {
            File::deleteDirectory($publishPath);
        }
    }

    /**
     * 验证配置文件
     */
    protected function validateConfig(array $config): bool
    {
        $requiredFields = [
            'name',
            'code',
            'version',
            'description',
            'author'
        ];

        foreach ($requiredFields as $field) {
            if (!isset($config[$field]) || empty($config[$field])) {
                return false;
            }
        }

        // 验证插件代码格式
        if (!preg_match('/^[a-z0-9_]+$/', $config['code'])) {
            return false;
        }

        // 验证版本号格式
        if (!preg_match('/^\d+\.\d+\.\d+$/', $config['version'])) {
            return false;
        }

        // 验证插件类型
        if (isset($config['type'])) {
            $validTypes = ['feature', 'payment'];
            if (!in_array($config['type'], $validTypes)) {
                return false;
            }
        }

        return $this->pluginPackage->validateManifestExtension($config);
    }

    /**
     * 启用插件
     */
    public function enable(string $pluginCode): bool
    {
        $dbPlugin = Plugin::query()->where('code', $pluginCode)->first();
        if (!$dbPlugin) {
            throw new \RuntimeException('Plugin is not installed: ' . $pluginCode);
        }
        if ($dbPlugin->is_enabled) {
            return true; // Idempotent enable: never boot/register a second time.
        }

        $plugin = $this->loadPlugin($pluginCode);
        if (!$plugin) {
            throw new \RuntimeException('Plugin runtime is unavailable: ' . $pluginCode);
        }

        if ($dbPlugin && !empty($dbPlugin->config)) {
            $values = json_decode($dbPlugin->config, true) ?: [];
            $values = $this->castConfigValuesByType($pluginCode, $values);
            $plugin->setConfig($values);
        }

        $configFile = $this->getPluginPath($pluginCode) . '/config.json';
        $config = File::isFile($configFile)
            ? json_decode((string) File::get($configFile), true)
            : null;
        if (!is_array($config) || !$this->validateConfig($config)
            || $config['code'] !== $pluginCode) {
            throw new \RuntimeException('Invalid plugin manifest for enable');
        }
        $this->assertDependencies($config['require'] ?? [], true);

        // Plugin Package v1 admin assets are immutable build artifacts. Re-publish
        // on enable so a newly replaced container always has the matching UI.
        $this->publishAssets($pluginCode);

        try {
            // The database must never claim enabled if the plugin failed boot.
            HookManager::withOwner($pluginCode, function () use ($pluginCode, $plugin): void {
                $this->registerServiceProvider($pluginCode);
                $this->loadRoutes($pluginCode);
                $this->loadViews($pluginCode);
                $plugin->boot();
            });
            $dbPlugin->update(['is_enabled' => true, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            HookManager::removeOwner($pluginCode);
            try {
                $plugin->cleanup();
            } catch (\Throwable $cleanupError) {
                Log::warning('Plugin boot rollback cleanup failed', [
                    'plugin' => $pluginCode, 'error' => $cleanupError->getMessage(),
                ]);
            }
            // Do not delete the plugin record or its saved configuration.
            throw $e;
        }

        return true;
    }

    /**
     * 禁用插件
     */
    public function disable(string $pluginCode): bool
    {
        $dbPlugin = Plugin::query()->where('code', $pluginCode)->first();
        if (!$dbPlugin) {
            throw new \RuntimeException('Plugin is not installed: ' . $pluginCode);
        }
        if (!$dbPlugin->is_enabled) {
            HookManager::removeOwner($pluginCode);
            return true;
        }
        $this->assertNoActiveDependents($pluginCode);

        // Revoke execution before calling untrusted plugin cleanup.
        $dbPlugin->update(['is_enabled' => false, 'updated_at' => now()]);
        HookManager::removeOwner($pluginCode);
        try {
            $plugin = $this->loadPlugin($pluginCode);
            $plugin?->cleanup();
        } catch (\Throwable $e) {
            Log::warning('Plugin cleanup failed after it was disabled', [
                'plugin' => $pluginCode, 'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    /**
     * 卸载插件
     */
    public function uninstall(string $pluginCode): bool
    {
        $this->assertNoActiveDependents($pluginCode);
        $this->disable($pluginCode);
        // Uninstall removes runtime registration and owned assets, but does
        // not drop user/business tables. Database migrations are retained so
        // accidental uninstalls cannot destroy plugin-owned persistent data.
        $this->removePublishedAssets($pluginCode);
        Plugin::query()->where('code', $pluginCode)->delete();

        return true;
    }

    /**
     * 删除插件
     *
     * @param string $pluginCode
     * @return bool
     * @throws \Exception
     */
    public function delete(string $pluginCode): bool
    {
        if ($this->isCorePlugin($pluginCode)) {
            throw new \Exception('核心插件不允许删除');
        }
        if (Plugin::where('code', $pluginCode)->exists()) {
            $this->uninstall($pluginCode);
        }

        $pluginPath = $this->getUserPluginPath($pluginCode);
        if (!File::exists($pluginPath)) {
            throw new \Exception('插件不存在');
        }

        File::deleteDirectory($pluginPath);
        $this->removePublishedAssets($pluginCode);

        return true;
    }

    /**
     * 检查依赖关系
     */
    protected function checkDependencies(array $requires): bool
    {
        try {
            $this->assertDependencies($requires);
            return true;
        } catch (\InvalidArgumentException | \RuntimeException) {
            return false;
        }
    }

    private function assertDependencies(mixed $requires, bool $activeOnly = false): void
    {
        if (!is_array($requires) || array_is_list($requires) && $requires !== []) {
            throw new \InvalidArgumentException('Plugin require must be a package/version map');
        }

        foreach ($requires as $package => $constraint) {
            if (!is_string($package) || !preg_match('/^[a-z][a-z0-9_]*$/', $package)
                || !is_string($constraint)) {
                throw new \InvalidArgumentException('Invalid plugin dependency entry');
            }

            if ($package === 'txboard' || $package === 'xboard') {
                $installedVersion = (string) config('app.version', '1.0.0');
            } else {
                $installed = Plugin::query()->where('code', $package)->first();
                if (!$installed || ($activeOnly && !$installed->is_enabled)) {
                    throw new \RuntimeException("Missing or disabled plugin dependency: {$package}");
                }
                $installedVersion = (string) $installed->version;
            }

            if (!PluginVersionConstraint::matches($installedVersion, $constraint)) {
                throw new \RuntimeException("Incompatible plugin dependency: {$package}");
            }
        }
    }

    private function assertNoActiveDependents(string $code): void
    {
        foreach (Plugin::query()->where('is_enabled', true)->where('code', '!=', $code)->get() as $dependent) {
            $configFile = $this->getPluginPath($dependent->code) . '/config.json';
            if (!File::isFile($configFile)) {
                // Fail closed for enabled plugins whose dependency metadata is unavailable.
                throw new \RuntimeException("Cannot inspect enabled plugin: {$dependent->code}");
            }
            $config = json_decode((string) File::get($configFile), true);
            if (!is_array($config)) {
                throw new \RuntimeException("Invalid enabled plugin manifest: {$dependent->code}");
            }
            $requires = $config['require'] ?? [];
            if (!is_array($requires)) {
                throw new \RuntimeException("Invalid enabled plugin dependencies: {$dependent->code}");
            }
            if (array_key_exists($code, $requires)) {
                throw new \RuntimeException("Plugin {$code} is required by {$dependent->code}");
            }
        }
    }

    /**
     * 升级插件
     *
     * @param string $pluginCode
     * @return bool
     * @throws \Exception
     */
    public function update(string $pluginCode): bool
    {
        $dbPlugin = Plugin::where('code', $pluginCode)->first();
        if (!$dbPlugin) {
            throw new \Exception('Plugin not installed: ' . $pluginCode);
        }

        // 获取插件配置文件中的最新版本
        $configFile = $this->getPluginPath($pluginCode) . '/config.json';
        if (!File::exists($configFile)) {
            throw new \Exception('Plugin config file not found');
        }

        $config = json_decode(File::get($configFile), true);
        if (!$config || !isset($config['version']) || !$this->validateConfig($config)) {
            throw new \Exception('Invalid plugin config or missing version');
        }
        $this->pluginPackage->assertDeclaredAdminAppsExist($this->getPluginPath($pluginCode), $config);

        if ($config['code'] !== $pluginCode) {
            throw new \RuntimeException('Plugin manifest identity changed');
        }
        $this->assertDependencies($config['require'] ?? [], true);
        $newVersion = $config['version'];
        $oldVersion = $dbPlugin->version;

        if (version_compare($newVersion, $oldVersion, '<=')) {
            throw new \Exception('Plugin is already up to date');
        }

        $wasEnabled = (bool) $dbPlugin->is_enabled;
        $previousConfig = $dbPlugin->config;
        try {
            if ($wasEnabled) {
                $this->disable($pluginCode);
            }
            // Plugin-authored DDL / external effects cannot be atomically
            // rolled back by a SQL transaction on MySQL.
            $this->runMigrations($pluginCode);

            $plugin = $this->loadPlugin($pluginCode);
            if (!$plugin) {
                throw new \RuntimeException('Plugin implementation unavailable after upgrade');
            }
            if (!empty($previousConfig)) {
                $values = json_decode($previousConfig, true) ?: [];
                $plugin->setConfig($this->castConfigValuesByType($pluginCode, $values));
            }
            $plugin->update($oldVersion, $newVersion);
            $dbPlugin->update(['version' => $newVersion, 'updated_at' => now()]);
            if ($wasEnabled) {
                $this->enable($pluginCode);
            }
        } catch (\Throwable $e) {
            // Restore durable metadata. If a new package is already on disk,
            // upload() restores that directory before trying reactivation.
            $dbPlugin->update([
                'version' => $oldVersion,
                'config' => $previousConfig,
                'is_enabled' => false,
                'updated_at' => now(),
            ]);
            HookManager::removeOwner($pluginCode);
            throw $e;
        }

        return true;
    }

    /**
     * 上传插件
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return bool
     * @throws \Exception
     */
    public function upload($file): bool
    {
        $tmpPath = storage_path('tmp/plugins');
        if (!File::exists($tmpPath)) {
            File::makeDirectory($tmpPath, 0755, true);
        }

        $extractPath = $tmpPath . '/' . uniqid();
        $zip = new \ZipArchive();

        if ($zip->open($file->path()) !== true) {
            throw new \Exception('无法打开插件包文件');
        }

        try {
            $this->pluginPackage->assertSafeArchive($zip);
            if (!$zip->extractTo($extractPath)) {
                throw new \Exception('插件包解压失败');
            }
        } catch (\Throwable $e) {
            $zip->close();
            File::deleteDirectory($extractPath);
            throw $e;
        }
        $zip->close();

        $configFile = File::glob($extractPath . '/*/config.json');
        if (empty($configFile)) {
            $configFile = File::glob($extractPath . '/config.json');
        }

        if (empty($configFile)) {
            File::deleteDirectory($extractPath);
            throw new \Exception('插件包格式错误：缺少配置文件');
        }

        $pluginPath = dirname(reset($configFile));
        $config = json_decode(File::get($pluginPath . '/config.json'), true);

        if (!$this->validateConfig($config)) {
            File::deleteDirectory($extractPath);
            throw new \Exception('插件配置文件格式错误');
        }

        try {
            $this->pluginPackage->assertDeclaredAdminAppsExist($pluginPath, $config);
        } catch (\Throwable $e) {
            File::deleteDirectory($extractPath);
            throw $e;
        }

        $code = $config['code'];
        if ($this->isCorePlugin($code)) {
            File::deleteDirectory($extractPath);
            throw new \RuntimeException('Bundled plugins cannot be overwritten by uploads');
        }
        try {
            $this->assertDependencies($config['require'] ?? []);
        } catch (\Throwable $e) {
            File::deleteDirectory($extractPath);
            throw $e;
        }
        $targetPath = $this->getUserPluginPath($code);
        $existingRow = Plugin::query()->where('code', $code)->first();

        if (File::isDirectory($targetPath)) {
            $installedConfigFile = $targetPath . '/config.json';
            $oldConfig = File::isFile($installedConfigFile)
                ? json_decode((string) File::get($installedConfigFile), true)
                : null;
            if (!is_array($oldConfig) || !isset($oldConfig['version'])
                || !version_compare($config['version'], $oldConfig['version'], '>')) {
                File::deleteDirectory($extractPath);
                throw new \RuntimeException('Uploaded plugin version must be newer');
            }
        }
        $staged = $targetPath . '.staging-' . bin2hex(random_bytes(8));
        $backup = $targetPath . '.backup-' . bin2hex(random_bytes(8));
        $hadOldFiles = false;
        $wasEnabled = (bool) ($existingRow?->is_enabled ?? false);
        try {
            if (!File::copyDirectory($pluginPath, $staged)) {
                throw new \RuntimeException('Failed to stage plugin package');
            }
            if (File::isDirectory($targetPath)) {
                if (!rename($targetPath, $backup)) {
                    throw new \RuntimeException('Failed to back up installed plugin');
                }
                $hadOldFiles = true;
            }
            if (!rename($staged, $targetPath)) {
                throw new \RuntimeException('Failed to publish staged plugin');
            }

            if ($existingRow) {
                $this->update($code);
            }
        } catch (\Throwable $e) {
            File::deleteDirectory($staged);
            File::deleteDirectory($targetPath);
            if ($hadOldFiles && !rename($backup, $targetPath)) {
                Log::critical('Plugin upgrade rollback requires manual recovery', [
                    'plugin' => $code, 'backup' => $backup,
                ]);
            }
            if ($existingRow && $wasEnabled) {
                try {
                    $this->enable($code);
                } catch (\Throwable $restoreError) {
                    Log::error('Failed to restore plugin after upgrade rollback', [
                        'plugin' => $code, 'error' => $restoreError->getMessage(),
                    ]);
                }
            }
            throw $e;
        } finally {
            File::deleteDirectory($extractPath);
        }
        if ($hadOldFiles) {
            File::deleteDirectory($backup);
        }
        return true;
    }

    /**
     * Initializes all enabled plugins from the database.
     * This method ensures that plugins are loaded, and their routes, views,
     * and service providers are registered only once per request cycle.
     */
    public function initializeEnabledPlugins(): void
    {
        if ($this->pluginsInitialized) {
            return;
        }

        $enabledPlugins = Plugin::where('is_enabled', true)->get();

        foreach ($enabledPlugins as $dbPlugin) {
            try {
                $pluginCode = $dbPlugin->code;

                $pluginInstance = $this->loadPlugin($pluginCode);
                if (!$pluginInstance) {
                    continue;
                }

                if (!empty($dbPlugin->config)) {
                    $values = json_decode($dbPlugin->config, true) ?: [];
                    $values = $this->castConfigValuesByType($pluginCode, $values);
                    $pluginInstance->setConfig($values);
                }

                HookManager::withOwner($pluginCode, function () use ($pluginCode, $pluginInstance): void {
                    $this->registerServiceProvider($pluginCode);
                    $this->loadRoutes($pluginCode);
                    $this->loadViews($pluginCode);
                    $this->registerPluginCommands($pluginCode, $pluginInstance);
                    $pluginInstance->boot();
                });

            } catch (\Throwable $e) {
                HookManager::removeOwner($dbPlugin->code);
                Log::error("Failed to initialize plugin '{$dbPlugin->code}': " . $e->getMessage());
            }
        }

        $this->pluginsInitialized = true;
    }

    /**
     * Register scheduled tasks for all enabled plugins.
     * Called from Console Kernel. Only loads main plugin class and config for scheduling.
     * Avoids full HTTP/plugin boot overhead.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     */
    public function registerPluginSchedules(Schedule $schedule): void
    {
        Plugin::where('is_enabled', true)
            ->get()
            ->each(function ($dbPlugin) use ($schedule) {
                try {
                    $pluginInstance = $this->loadPlugin($dbPlugin->code);
                    if (!$pluginInstance) {
                        return;
                    }
                    if (!empty($dbPlugin->config)) {
                        $values = json_decode($dbPlugin->config, true) ?: [];
                        $values = $this->castConfigValuesByType($dbPlugin->code, $values);
                        $pluginInstance->setConfig($values);
                    }
                    $pluginInstance->schedule($schedule);

                } catch (\Exception $e) {
                    Log::error("Failed to register schedule for plugin '{$dbPlugin->code}': " . $e->getMessage());
                }
            });
    }

    /**
     * Get all enabled plugin instances.
     *
     * This method ensures that all enabled plugins are initialized and then returns them.
     * It's the central point for accessing active plugins.
     *
     * @return array<AbstractPlugin>
     */
    public function getEnabledPlugins(): array
    {
        $this->initializeEnabledPlugins();

        $enabledPluginCodes = Plugin::where('is_enabled', true)
            ->pluck('code')
            ->all();

        return array_intersect_key($this->loadedPlugins, array_flip($enabledPluginCodes));
    }

    /**
     * Get enabled plugins by type
     */
    public function getEnabledPluginsByType(string $type): array
    {
        $this->initializeEnabledPlugins();

        $enabledPluginCodes = Plugin::where('is_enabled', true)
            ->byType($type)
            ->pluck('code')
            ->all();

        return array_intersect_key($this->loadedPlugins, array_flip($enabledPluginCodes));
    }

    /**
     * Get enabled payment plugins
     */
    public function getEnabledPaymentPlugins(): array
    {
        return $this->getEnabledPluginsByType('payment');
    }

    /**
     * install default plugins
     */
    public static function installDefaultPlugins(): void
    {
        $pluginManager = app(self::class);
        $coreDir = base_path('plugins-core');

        if (!File::isDirectory($coreDir)) {
            return;
        }

        foreach (File::directories($coreDir) as $directory) {
            $configFile = $directory . '/config.json';
            if (!File::exists($configFile)) {
                continue;
            }
            $config = json_decode(File::get($configFile), true);
            $code = $config['code'] ?? null;
            if (!$code) {
                continue;
            }
            if (!Plugin::where('code', $code)->exists()) {
                $pluginManager->install($code);
                $pluginManager->enable($code);
                Log::info("Installed and enabled core plugin: {$code}");
            }
        }
    }

    /**
     * 根据 config.json 的类型信息对配置值进行类型转换（仅处理 type=json 键）。
     */
    protected function castConfigValuesByType(string $pluginCode, array $values): array
    {
        $types = $this->getConfigTypes($pluginCode);
        foreach ($values as $key => $value) {
            $type = $types[$key] ?? null;

            if ($type === 'json') {
                if (is_array($value)) {
                    continue;
                }
                
                if (is_string($value) && $value !== '') {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $values[$key] = $decoded;
                    }
                }
            }
        }
        return $values;
    }

    /**
     * 读取并缓存插件 config.json 中的键类型映射。
     */
    protected function getConfigTypes(string $pluginCode): array
    {
        if (isset($this->configTypesCache[$pluginCode])) {
            return $this->configTypesCache[$pluginCode];
        }
        $types = [];
        $configFile = $this->getPluginPath($pluginCode) . '/config.json';
        if (File::exists($configFile)) {
            $config = json_decode(File::get($configFile), true);
            $fields = $config['config'] ?? [];
            foreach ($fields as $key => $meta) {
                $types[$key] = is_array($meta) ? ($meta['type'] ?? 'string') : 'string';
            }
        }
        $this->configTypesCache[$pluginCode] = $types;
        return $types;
    }
}