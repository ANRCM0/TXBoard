<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use App\Services\Theme\ThemePackage;
use Illuminate\Http\UploadedFile;
use Exception;
use Throwable;
use ZipArchive;

class ThemeService
{
    private const SYSTEM_THEME_DIR = 'theme/';
    private const USER_THEME_DIR = '/storage/theme/';
    private const CONFIG_FILE = 'config.json';
    private const SETTING_PREFIX = 'theme_';
    private const SYSTEM_THEMES = ['TXBoard', 'v2board'];
    private const DEFAULT_THEME = 'TXBoard';

    public function __construct(
        private readonly ThemePackage $themePackage,
    ) {
        $this->registerThemeViewPaths();
    }


    /**
     * Register theme view paths
     */
    private function registerThemeViewPaths(): void
    {
        $systemPath = base_path(self::SYSTEM_THEME_DIR);
        if (File::exists($systemPath)) {
            View::addNamespace('theme', $systemPath);
        }

        $userPath = base_path(self::USER_THEME_DIR);
        if (File::exists($userPath)) {
            View::prependNamespace('theme', $userPath);
        }
    }

    /**
     * Get theme view path
     */
    public function getThemeViewPath(string $theme): ?string
    {
        $themePath = $this->getThemePath($theme);
        if (!$themePath) {
            return null;
        }
        return $themePath . '/dashboard.blade.php';
    }

    /**
     * Resolve the effective user-facing theme.
     *
     * frontend_theme is the canonical setting used by the web entrypoint.
     * current_theme is read only as a legacy fallback for older installations.
     */
    public function getActiveTheme(): string
    {
        $theme = trim((string) admin_setting('frontend_theme', ''));
        if ($theme !== '') {
            return $this->exists($theme)
                ? $theme
                : self::DEFAULT_THEME;
        }

        // Historical compatibility only. Reads may consult current_theme
        // only when canonical state is absent; explicit switches are the
        // only writes to frontend_theme.
        $legacyTheme = trim((string) admin_setting('current_theme', ''));
        if ($legacyTheme !== '' && $this->exists($legacyTheme)) {
            return $legacyTheme;
        }

        return self::DEFAULT_THEME;
    }

    /**
     * Get all available themes
     */
    public function getList(): array
    {
        $themes = [];

        // 获取系统主题
        $systemPath = base_path(self::SYSTEM_THEME_DIR);
        if (File::exists($systemPath)) {
            $themes = $this->getThemesFromPath($systemPath, false);
        }

        // 获取用户主题
        $userPath = base_path(self::USER_THEME_DIR);
        if (File::exists($userPath)) {
            // System theme identities remain authoritative if a legacy user
            // directory collides with the same exact runtime name.
            $themes += $this->getThemesFromPath($userPath, true);
        }

        return $themes;
    }

    /**
     * Get themes from specified path
     */
    private function getThemesFromPath(string $path, bool $canDelete): array
    {
        $activeTheme = $this->getActiveTheme();

        return collect(File::directories($path))
            ->mapWithKeys(function ($dir) use ($canDelete, $activeTheme) {
                $name = basename($dir);
                if (
                    !File::exists($dir . '/' . self::CONFIG_FILE) ||
                    !File::exists($dir . '/dashboard.blade.php')
                ) {
                    return [];
                }
                $config = $this->readConfigFile($name);
                if (!$config) {
                    return [];
                }

                $config['can_delete'] = $canDelete && $name !== $activeTheme;
                $config['is_system'] = !$canDelete;
                return [$name => $config];
            })->toArray();
    }

    /**
     * Upload new theme
     */
    public function upload(UploadedFile $file): bool
    {
        $zip = new ZipArchive;
        $tmpPath = storage_path('tmp/' . uniqid('', true));
        $opened = false;

        try {
            if ($zip->open($file->path()) !== true) {
                throw new Exception('Invalid theme package');
            }
            $opened = true;

            $this->themePackage->assertSafeArchive($zip);
            $configEntry = $this->themePackage->configEntry($zip);

            if (!$zip->extractTo($tmpPath)) {
                throw new Exception('Failed to extract theme package');
            }

            $sourcePath = $this->themePackage->sourcePath($tmpPath, $configEntry);
            $manifest = $this->themePackage->manifestFromFile(
                $sourcePath . '/' . self::CONFIG_FILE
            );
            $this->themePackage->assertRequiredFiles($sourcePath);

            if ($this->isSystemTheme($manifest->name)) {
                throw new Exception('Cannot upload theme with same name as system theme');
            }

            $userThemePath = base_path(self::USER_THEME_DIR);
            if (!File::exists($userThemePath)) {
                File::makeDirectory($userThemePath, 0755, true);
            }

            $targetPath = $userThemePath . $manifest->name;
            if (File::exists($targetPath)) {
                $oldConfigFile = $targetPath . '/' . self::CONFIG_FILE;
                if (!File::exists($oldConfigFile)) {
                    throw new Exception('Existing theme missing config file');
                }

                $oldConfig = json_decode((string) File::get($oldConfigFile), true);
                $oldVersion = is_array($oldConfig) && is_string($oldConfig['version'] ?? null)
                    ? $oldConfig['version']
                    : '0.0.0';

                if (!version_compare($manifest->version, $oldVersion, '>')) {
                    throw new Exception('Theme exists and not a newer version');
                }

                // Never remove the working theme before the newer package has
                // copied successfully. Stage on the same filesystem to make
                // the promotion reversible if anything fails.
                $backup = $userThemePath . '.backup-' . bin2hex(random_bytes(8));
                $staging = $userThemePath . '.staging-' . bin2hex(random_bytes(8));
                try {
                    if (!File::copyDirectory($sourcePath, $staging)) {
                        throw new Exception('Failed to stage theme upgrade');
                    }
                    if (!rename($targetPath, $backup)) {
                        throw new Exception('Failed to back up current theme');
                    }
                    if (!rename($staging, $targetPath)) {
                        throw new Exception('Failed to activate upgraded theme');
                    }

                    $this->initConfig($manifest->name, true);
                    if ($manifest->name === $this->getActiveTheme()
                        && !$this->refreshCurrentTheme()) {
                        throw new Exception('Failed to publish upgraded active theme');
                    }
                } catch (Throwable $e) {
                    File::deleteDirectory($staging);
                    if (File::isDirectory($backup)) {
                        File::deleteDirectory($targetPath);
                        if (!rename($backup, $targetPath)) {
                            Log::critical('Theme upgrade rollback needs manual recovery', [
                                'theme' => $manifest->name, 'backup' => $backup,
                            ]);
                        }
                        if ($manifest->name === $this->getActiveTheme()) {
                            $this->refreshCurrentTheme();
                        }
                    }
                    throw $e;
                }
                File::deleteDirectory($backup);
                return true;
            }

            if (!File::copyDirectory($sourcePath, $targetPath)) {
                throw new Exception('Failed to install theme files');
            }
            $this->initConfig($manifest->name);

            return true;
        } finally {
            if ($opened) {
                $zip->close();
            }
            if (File::exists($tmpPath)) {
                File::deleteDirectory($tmpPath);
            }
        }
    }

    /**
     * Switch theme
     */
    public function switch(string|null $theme): bool
    {
        if ($theme === null) {
            return true;
        }

        $currentTheme = $this->getActiveTheme();

        try {
            $themePath = $this->getThemePath($theme);
            if (!$themePath) {
                throw new Exception('Theme not found');
            }

            if (!File::exists($this->getThemeViewPath($theme))) {
                throw new Exception('Theme view file not found');
            }

            $this->publishThemeAssets($theme, $themePath, function () use ($theme): void {
                admin_setting(['frontend_theme' => $theme]);
            });

            if ($currentTheme && $currentTheme !== $theme) {
                $this->cleanupThemeFiles($currentTheme);
            }
            return true;

        } catch (Exception $e) {
            Log::error('Theme switch failed', ['theme' => $theme, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Delete theme
     */
    public function delete(string $theme): bool
    {

        try {
            if (!$this->isSafeThemeName($theme)) {
                throw new Exception('Invalid theme name');
            }

            if ($this->isSystemTheme($theme)) {
                throw new Exception('System theme cannot be deleted');
            }

            if ($theme === $this->getActiveTheme()) {
                throw new Exception('Current theme cannot be deleted');
            }

            $themePath = base_path(self::USER_THEME_DIR . $theme);
            if (!File::exists($themePath)) {
                throw new Exception('Theme not found');
            }

            $this->cleanupThemeFiles($theme);
            File::deleteDirectory($themePath);
            admin_setting([self::SETTING_PREFIX . $theme => null]);
            return true;

        } catch (Exception $e) {
            Log::error('Theme deletion failed', ['theme' => $theme, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Check if theme exists
     */
    public function exists(string $theme): bool
    {
        return $this->getThemePath($theme) !== null;
    }

    /**
     * Get theme path
     */
    public function getThemePath(string $theme): ?string
    {
        if (!$this->isSafeThemeName($theme)) {
            return null;
        }

        $systemPath = base_path(self::SYSTEM_THEME_DIR . $theme);
        if (File::exists($systemPath)) {
            return $systemPath;
        }

        $userPath = base_path(self::USER_THEME_DIR . $theme);
        if (File::exists($userPath)) {
            return $userPath;
        }

        return null;
    }

    /**
     * Get theme config
     */
    public function getConfig(string $theme): ?array
    {
        $config = admin_setting(self::SETTING_PREFIX . $theme);

        if ($config === null) {
            $this->initConfig($theme);
            $config = admin_setting(self::SETTING_PREFIX . $theme);
        }

        return $config;
    }

    /**
     * Update theme config
     */
    public function updateConfig(string $theme, array $config): bool
    {

        try {
            if (!$this->getThemePath($theme)) {
                throw new Exception('Theme not found');
            }

            $schema = $this->readConfigFile($theme);
            if (!$schema) {
                throw new Exception('Invalid theme config file');
            }

            $validFields = collect($schema['configs'] ?? [])->pluck('field_name')->toArray();
            $validConfig = collect($config)
                ->only($validFields)
                ->toArray();

            $currentConfig = $this->getConfig($theme) ?? [];
            $newConfig = array_merge($currentConfig, $validConfig);

            admin_setting([self::SETTING_PREFIX . $theme => $newConfig]);
            return true;

        } catch (Exception $e) {
            Log::error('Config update failed', ['theme' => $theme, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Read theme config file
     */
    private function readConfigFile(string $theme): ?array
    {
        $themePath = $this->getThemePath($theme);
        if (!$themePath) {
            return null;
        }

        $file = $themePath . '/' . self::CONFIG_FILE;
        return File::exists($file) ? json_decode(File::get($file), true) : null;
    }

    /**
     * Clean up theme files including public directory
     */
    public function cleanupThemeFiles(string $theme): void
    {
        try {
            $publicThemePath = public_path('theme/' . $theme);
            if (File::exists($publicThemePath)) {
                File::deleteDirectory($publicThemePath);
                Log::info('Cleaned up public theme files', ['theme' => $theme, 'path' => $publicThemePath]);
            }

            $cacheKey = "theme_{$theme}_assets";
            if (cache()->has($cacheKey)) {
                cache()->forget($cacheKey);
                Log::info('Cleaned up theme cache', ['theme' => $theme, 'cache_key' => $cacheKey]);
            }

        } catch (Exception $e) {
            Log::warning('Failed to cleanup theme files', [
                'theme' => $theme,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Force refresh current theme public files
     */
    public function refreshCurrentTheme(): bool
    {
        try {
            $currentTheme = $this->getActiveTheme();

            $themePath = $this->getThemePath($currentTheme);
            if (!$themePath) {
                throw new Exception('Current theme path not found');
            }

            $this->publishThemeAssets($currentTheme, $themePath);

            Log::info('Refreshed current theme files', ['theme' => $currentTheme]);
            return true;

        } catch (Exception $e) {
            Log::error('Failed to refresh current theme', [
                'theme' => $currentTheme,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Publish into an owner-scoped directory through staging and rename.
     * Preserve the previous publication if copy, activation or setting
     * persistence fails. Never touch a different theme's assets.
     */
    private function publishThemeAssets(string $theme, string $source, ?callable $afterPublish = null): void
    {
        if (!$this->isSafeThemeName($theme)) {
            throw new Exception('Invalid theme name');
        }
        $parent = public_path('theme');
        File::ensureDirectoryExists($parent);

        $target = $parent . '/' . $theme;
        $staged = $parent . '/.staging-' . bin2hex(random_bytes(8));
        $backup = $parent . '/.backup-' . bin2hex(random_bytes(8));
        $savedOld = false;
        $published = false;

        try {
            if (!File::copyDirectory($source, $staged)) {
                throw new Exception('Failed to stage theme assets');
            }

            if (File::isDirectory($target)) {
                if (!rename($target, $backup)) {
                    throw new Exception('Failed to back up theme assets');
                }
                $savedOld = true;
            }
            if (!rename($staged, $target)) {
                throw new Exception('Failed to publish theme assets');
            }
            $published = true;

            if ($afterPublish !== null) {
                $afterPublish();
            }
        } catch (Throwable $e) {
            File::deleteDirectory($staged);
            if ($savedOld) {
                File::deleteDirectory($target);
                if (!rename($backup, $target)) {
                    Log::critical('Published theme assets need manual recovery', [
                        'theme' => $theme, 'backup' => $backup,
                    ]);
                }
            } elseif ($published) {
                // Staging or backup failed before publication: the original
                // target still belongs to the active theme and must survive.
                File::deleteDirectory($target);
            }
            throw $e;
        }

        if ($savedOld) {
            File::deleteDirectory($backup);
        }
        cache()->forget("theme_{$theme}_assets");
    }

    /**
     * Initialize theme config
     * 
     * @param string $theme 主题名称
     * @param bool $preserveExisting 是否保留现有配置（更新主题时使用）
     */
    private function initConfig(string $theme, bool $preserveExisting = false): void
    {
        $config = $this->readConfigFile($theme);
        if (!$config) {
            return;
        }

        $defaults = collect($config['configs'] ?? [])
            ->mapWithKeys(fn($col) => [$col['field_name'] => $col['default_value'] ?? ''])
            ->toArray();

        if ($preserveExisting) {
            $existingConfig = admin_setting(self::SETTING_PREFIX . $theme) ?? [];
            $mergedConfig = array_merge($defaults, $existingConfig);
            admin_setting([self::SETTING_PREFIX . $theme => $mergedConfig]);
        } else {
            admin_setting([self::SETTING_PREFIX . $theme => $defaults]);
        }
    }
    private function isSafeThemeName(string $theme): bool
    {
        $name = trim($theme);

        return $name !== ''
            && strlen($name) <= 120
            && !str_contains($name, chr(0))
            && !str_contains($name, '/')
            && !str_contains($name, '\\')
            && !in_array($name, ['.', '..'], true);
    }

    private function isSystemTheme(string $theme): bool
    {
        foreach (self::SYSTEM_THEMES as $systemTheme) {
            if (strcasecmp($theme, $systemTheme) === 0) {
                return true;
            }
        }

        return false;
    }

}
