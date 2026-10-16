<?php

namespace App\Services;

use App\Services\Theme\ThemeV2Runtime;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ThemeService
{
    private const DEFAULT_THEME = 'TXBoard';

    public function __construct(private readonly ThemeV2Runtime $runtime)
    {
    }

    /** Only frontend_theme selects the active theme. Registry reads never persist it. */
    public function getActiveTheme(): string
    {
        $theme = trim((string) admin_setting('frontend_theme', ''));
        return $theme !== '' && $this->exists($theme) ? $theme : self::DEFAULT_THEME;
    }

    public function getList(): array
    {
        $themes = [self::DEFAULT_THEME => array_merge($this->builtinConfig(), [
            'runtime' => 'builtin-spa', 'can_delete' => false, 'is_system' => true,
        ])];
        $root = base_path('storage/theme');
        if (!File::isDirectory($root)) return $themes;
        $active = $this->getActiveTheme();
        foreach (File::directories($root) as $directory) {
            $name = basename($directory);
            if (!$this->runtime->isInstalled($name)) continue;
            try {
                $themes[$name] = array_merge($this->runtime->inventory($name), [
                    'can_delete' => $name !== $active, 'is_system' => false,
                ]);
            } catch (Throwable) {
                Log::warning('Invalid installed static theme package', ['theme' => $name]);
            }
        }
        return $themes;
    }

    public function exists(string $theme): bool
    {
        return $theme === self::DEFAULT_THEME || $this->runtime->isInstalled($theme);
    }

    public function switch(?string $theme): bool
    {
        if ($theme === null) return true;
        if (!$this->exists($theme)) throw new InvalidArgumentException('Theme not found');
        $current = $this->getActiveTheme();
        if ($theme === self::DEFAULT_THEME) {
            admin_setting(['frontend_theme' => $theme]);
        } else {
            $this->runtime->activate($theme, fn () => admin_setting(['frontend_theme' => $theme]));
        }
        if ($current !== $theme) $this->cleanupThemeFiles($current);
        return true;
    }

    public function delete(string $theme): bool
    {
        if (!$this->isSafeThemeName($theme)) throw new InvalidArgumentException('Invalid theme name');
        if (strcasecmp($theme, self::DEFAULT_THEME) === 0) throw new InvalidArgumentException('System theme cannot be deleted');
        if ($theme === $this->getActiveTheme()) throw new InvalidArgumentException('Current theme cannot be deleted');
        if (!$this->runtime->isInstalled($theme)) throw new InvalidArgumentException('Theme not found');
        $this->cleanupThemeFiles($theme);
        if (!File::deleteDirectory(base_path('storage/theme/'.$theme))) {
            throw new RuntimeException('Failed to delete theme files');
        }
        $this->runtime->removeHistory($theme);
        admin_setting(['theme_'.$theme => null]);
        return true;
    }

    public function getConfig(string $theme): ?array
    {
        if ($theme !== self::DEFAULT_THEME) return $this->runtime->getConfig($theme);
        $defaults = [];
        foreach ($this->builtinConfig()['configs'] as $field) {
            $defaults[$field['field_name']] = $field['default_value'] ?? '';
        }
        $stored = admin_setting('theme_TXBoard');
        return array_replace($defaults, is_array($stored) ? $stored : []);
    }

    public function getPublicConfig(string $theme): array
    {
        if ($theme !== self::DEFAULT_THEME) return $this->runtime->getPublicConfig($theme);
        $values = $this->getConfig($theme) ?? [];
        $public = [];
        foreach ($this->builtinConfig()['configs'] as $field) {
            if (($field['public'] ?? true) === false ||
                in_array(strtolower((string) ($field['field_type'] ?? '')), ['password', 'secret', 'hidden'], true)) continue;
            $key = $field['field_name'];
            if (array_key_exists($key, $values)) $public[$key] = $values[$key];
        }
        return $public;
    }

    public function updateConfig(string $theme, array $config): bool
    {
        if ($theme !== self::DEFAULT_THEME) {
            $this->runtime->updateConfig($theme, $config);
        } else {
            $keys = array_column($this->builtinConfig()['configs'], 'field_name');
            admin_setting(['theme_TXBoard' => array_replace($this->getConfig($theme) ?? [],
                array_intersect_key($config, array_flip($keys)))]);
        }
        return true;
    }

    public function cleanupThemeFiles(string $theme): void
    {
        if (!$this->isSafeThemeName($theme)) throw new InvalidArgumentException('Invalid theme name');
        $path = public_path('theme/'.$theme);
        if (File::isDirectory($path) && !File::deleteDirectory($path)) {
            throw new RuntimeException('Failed to remove theme publication');
        }
    }

    public function refreshCurrentTheme(): bool
    {
        try {
            $theme = $this->getActiveTheme();
            if ($theme !== self::DEFAULT_THEME) $this->runtime->republish($theme);
            return true;
        } catch (Throwable) {
            Log::error('Failed to refresh current theme');
            return false;
        }
    }

    private function builtinConfig(): array
    {
        return json_decode((string) File::get(base_path('theme/TXBoard/config.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function isSafeThemeName(string $theme): bool
    {
        return $theme === self::DEFAULT_THEME || preg_match('/\A[a-z0-9][a-z0-9-]{0,62}[a-z0-9]\z/D', $theme) === 1;
    }
}
