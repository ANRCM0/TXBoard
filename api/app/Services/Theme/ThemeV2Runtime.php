<?php

namespace App\Services\Theme;

use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Theme v2 SPA runtime. Never executes untrusted PHP or server-side JS.
 * Existing ThemeService is the sole owner of frontend_theme.
 */
final class ThemeV2Runtime
{
    private const MANIFEST = 'txboard-theme.json';
    private const NAME = '/\A[a-z0-9][a-z0-9-]{0,62}[a-z0-9]\z/D';

    public function __construct(private readonly ThemeV2Package $package)
    {
    }

    private function root(): string
    {
        return base_path('storage/theme');
    }

    private function path(string $name): string
    {
        if (!preg_match(self::NAME, $name) || strcasecmp($name, 'TXBoard') === 0) {
            throw new InvalidArgumentException('Invalid v2 theme name');
        }
        return $this->root().'/'.$name;
    }

    public function isInstalled(string $name): bool
    {
        try {
            return File::isFile($this->path($name).'/'.self::MANIFEST);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function manifest(string $name): array
    {
        if (!$this->isInstalled($name)) {
            throw new InvalidArgumentException('Theme v2 is not installed');
        }
        $value = json_decode((string) File::get($this->path($name).'/'.self::MANIFEST), true);
        if (!is_array($value) || ($value['name'] ?? null) !== $name ||
            ($value['format'] ?? null) !== 2 || ($value['entry']['type'] ?? null) !== 'spa') {
            throw new InvalidArgumentException('Invalid installed v2 manifest');
        }
        return $value;
    }

    /** @return array<string, mixed> */
    public function inventory(string $name): array
    {
        $manifest = $this->manifest($name);
        return [
            'name' => $name,
            'title' => $manifest['title'] ?? $name,
            'description' => 'Standalone SPA Theme Package v2',
            'version' => $manifest['version'],
            'format' => 2,
            'runtime' => 'spa',
            'compatibility' => ['txboard' => $manifest['engines']['txboard']],
            'configs' => $this->configurationFields($manifest),
            'can_rollback' => File::isDirectory($this->root().'/.rollback/'.$name),
        ];
    }

    /**
     * Validated package promotion. One rollback slot per theme.
     * Upload does NOT activate a previously inactive theme.
     */
    public function install(UploadedFile $file): string
    {
        return $this->locked(function () use ($file): string {
            $zip = new ZipArchive;
            if ($zip->open($file->path()) !== true) {
                throw new InvalidArgumentException('Invalid Theme v2 ZIP');
            }
            $unpack = $this->root().'/.unpack-'.bin2hex(random_bytes(8));
            $stage = $this->root().'/.stage-'.bin2hex(random_bytes(8));
            $backup = $this->root().'/.backup-'.bin2hex(random_bytes(8));
            $ownedBackup = false;
            $promoted = false;
            try {
                $manifest = $this->package->validate($zip, ...$this->trustedVersions());
                $name = $manifest['name'];
                $active = (string) admin_setting('frontend_theme', '') === $name;
                $target = $this->path($name);
                if (File::exists($target)) {
                    if (!$this->isInstalled($name)) {
                        throw new InvalidArgumentException('Theme name already belongs to a v1 theme');
                    }
                    if (!version_compare($manifest['version'], $this->manifest($name)['version'], '>')) {
                        throw new InvalidArgumentException('Theme v2 upgrade must have a newer version');
                    }
                }
                $entry = $this->package->manifestEntry($zip);
                if (!$zip->extractTo($unpack)) {
                    throw new RuntimeException('Theme v2 extraction failed');
                }
                $source = substr($entry, 0, -strlen(self::MANIFEST));
                $source = $unpack.($source === '' ? '' : '/'.rtrim($source, '/'));
                if (!File::copyDirectory($source, $stage)) {
                    throw new RuntimeException('Theme v2 staging failed');
                }

                if (File::isDirectory($target)) {
                    if (!rename($target, $backup)) {
                        throw new RuntimeException('Cannot stage prior theme version');
                    }
                    $ownedBackup = true;
                }
                if (!rename($stage, $target)) {
                    throw new RuntimeException('Cannot promote theme version');
                }
                $promoted = true;

                // Refresh public files only for an already active theme. New
                // uploads remain inactive until explicit admin selection.
                if ($active) {
                    $this->publish($name, $target);
                }
                if ($ownedBackup) {
                    $rollbackRoot = $this->root().'/.rollback';
                    File::ensureDirectoryExists($rollbackRoot);
                    $slot = $rollbackRoot.'/'.$name;
                    File::deleteDirectory($slot);
                    if (!rename($backup, $slot)) {
                        throw new RuntimeException('Failed to persist previous theme version');
                    }
                    $ownedBackup = false;
                }
                return $name;
            } catch (Throwable $e) {
                if ($promoted) {
                    // If publication was changed, publish() itself restores
                    // the prior publication on failure.
                    File::deleteDirectory($target);
                }
                if ($ownedBackup && !rename($backup, $target)) {
                    \Illuminate\Support\Facades\Log::critical('Theme v2 previous version requires manual recovery', [
                        'theme' => $name ?? null, 'backup' => $backup,
                    ]);
                }
                throw $e;
            } finally {
                $zip->close();
                File::deleteDirectory($unpack);
                File::deleteDirectory($stage);
            }
        });
    }

    /** Publish only allowlisted static assets, never manifest or schema. */
    private function publish(string $name, string $source): void
    {
        $this->assertAssetIntegrity($name, $source);
        $parent = public_path('theme');
        File::ensureDirectoryExists($parent);
        $stage = $parent.'/.stage-'.bin2hex(random_bytes(8));
        $backup = $parent.'/.backup-'.bin2hex(random_bytes(8));
        $target = $parent.'/'.$name;
        $saved = false;
        $promoted = false;
        try {
            File::ensureDirectoryExists($stage);
            foreach (['dist', 'assets'] as $folder) {
                if (File::isDirectory($source.'/'.$folder) &&
                    !File::copyDirectory($source.'/'.$folder, $stage.'/'.$folder)) {
                    throw new RuntimeException('Theme static asset staging failed');
                }
            }
            if (File::isDirectory($target)) {
                if (!rename($target, $backup)) {
                    throw new RuntimeException('Theme static asset backup failed');
                }
                $saved = true;
            }
            if (!rename($stage, $target)) {
                throw new RuntimeException('Theme static asset promotion failed');
            }
            $promoted = true;
        } catch (Throwable $e) {
            File::deleteDirectory($stage);
            if ($promoted) {
                File::deleteDirectory($target);
            }
            if ($saved && !rename($backup, $target)) {
                \Illuminate\Support\Facades\Log::critical('Theme v2 public asset rollback needs manual recovery', [
                    'theme' => $name, 'backup' => $backup,
                ]);
            }
            throw $e;
        }
        if ($saved) {
            File::deleteDirectory($backup);
        }
    }

    private function assertAssetIntegrity(string $name, string $source): void
    {
        $manifest = json_decode((string) File::get($source.'/'.self::MANIFEST), true);
        if (!is_array($manifest) || ($manifest['name'] ?? null) !== $name) {
            throw new InvalidArgumentException('Invalid theme v2 version snapshot');
        }
        foreach ($manifest['assets'] ?? [] as $asset) {
            if (!is_array($asset) || !is_string($asset['path'] ?? null) ||
                !preg_match('~\A(?:dist|assets)/[A-Za-z0-9._/-]+\z~D', $asset['path']) ||
                str_contains($asset['path'], '/../') || str_contains($asset['path'], '/./')) {
                throw new InvalidArgumentException('Invalid installed theme asset');
            }
            $assetPath = $source.'/'.$asset['path'];
            if (!File::isFile($assetPath) || File::isLink($assetPath) ||
                !is_string($digest = hash_file('sha256', $assetPath, true)) ||
                !hash_equals((string) ($asset['integrity'] ?? ''), 'sha256-'.base64_encode($digest))) {
                throw new InvalidArgumentException('Theme asset integrity mismatch');
            }
        }
    }

    public function republish(string $name): void
    {
        $this->locked(function () use ($name): void {
            $manifest = $this->manifest($name);
            $this->package->assertDeployedCompatibility($manifest, ...$this->trustedVersions());
            $this->publish($name, $this->path($name));
        });
    }

    public function activate(string $name, callable $setActive): void
    {
        $this->locked(function () use ($name, $setActive): void {
            $manifest = $this->manifest($name);
            $this->package->assertDeployedCompatibility($manifest, ...$this->trustedVersions());
            $this->publish($name, $this->path($name));
            // After files publish, only the canonical ThemeService switches.
            $setActive();
        });
    }

    /**
     * Restore the immediately preceding installed version. The replaced
     * version becomes the new rollback slot, allowing an explicit undo.
     */
    public function rollback(string $name, bool $active): void
    {
        $this->locked(function () use ($name, $active): void {
            $target = $this->path($name);
            $slot = $this->root().'/.rollback/'.$name;
            if (!$this->isInstalled($name) || !File::isDirectory($slot)) {
                throw new InvalidArgumentException('No previous theme version available');
            }
            $old = $this->root().'/.rollback-work-'.bin2hex(random_bytes(8));
            if (!rename($target, $old)) {
                throw new RuntimeException('Cannot stage current theme version');
            }
            try {
                if (!rename($slot, $target)) {
                    throw new RuntimeException('Cannot restore previous theme version');
                }
                $manifest = $this->manifest($name);
                $this->package->assertDeployedCompatibility($manifest, ...$this->trustedVersions());
                $this->assertAssetIntegrity($name, $target);
                if ($active) {
                    $this->publish($name, $target);
                }
                if (!rename($old, $slot)) {
                    throw new RuntimeException('Cannot retain replaced theme version');
                }
            } catch (Throwable $e) {
                // Move a restored candidate out of the way and bring back the
                // version that was active when rollback started.
                if (File::isDirectory($target)) {
                    if (!File::isDirectory($slot)) {
                        rename($target, $slot);
                    } else {
                        File::deleteDirectory($target);
                    }
                }
                if (!rename($old, $target)) {
                    \Illuminate\Support\Facades\Log::critical('Theme v2 rollback needs manual recovery', [
                        'theme' => $name, 'staged' => $old,
                    ]);
                }
                throw $e;
            }
        });
    }

    public function removeHistory(string $name): void
    {
        if ($this->isInstalled($name)) {
            return;
        }
        $this->locked(fn () => File::deleteDirectory($this->root().'/.rollback/'.$name));
    }

    public function getConfig(string $name): array
    {
        $manifest = $this->manifest($name);
        $defaults = $manifest['config']['defaults'] ?? [];
        $stored = admin_setting('theme_'.$name);
        return array_replace(is_array($defaults) ? $defaults : [], is_array($stored) ? $stored : []);
    }

    public function updateConfig(string $name, array $settings): void
    {
        $manifest = $this->manifest($name);
        $allowed = array_keys($manifest['config']['defaults'] ?? []);
        $filtered = array_intersect_key($settings, array_flip($allowed));
        admin_setting(['theme_'.$name => array_replace($this->getConfig($name), $filtered)]);
    }

    public function getPublicConfig(string $name): array
    {
        $manifest = $this->manifest($name);
        $candidates = $manifest['config']['public'] ?? [];
        $authoritative = config('theme_v2.public_fields', []);
        if (!is_array($candidates) || !is_array($authoritative)) {
            return [];
        }
        $allowed = array_intersect($candidates, $authoritative);
        return array_intersect_key($this->getConfig($name), array_flip($allowed));
    }

    private function configurationFields(array $manifest): array
    {
        $fields = [];
        foreach ($manifest['config']['defaults'] ?? [] as $key => $value) {
            $fields[] = ['field_name' => $key, 'label' => $key, 'field_type' => 'input',
                'default_value' => $value];
        }
        return $fields;
    }

    public function render(string $name): Response
    {
        $manifest = $this->manifest($name);
        $this->package->assertDeployedCompatibility($manifest, ...$this->trustedVersions());
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $url = static fn (string $asset): string => '/theme/'.$name.'/'.$asset;
        $styles = '';
        foreach ($manifest['assets'] as $asset) {
            if (str_ends_with($asset['path'], '.css')) {
                $styles .= '<link rel="stylesheet" href="'.$e($url($asset['path'])).'" integrity="'.
                    $e($asset['integrity']).'" crossorigin="anonymous">';
            }
        }
        $entry = $manifest['entry']['module'];
        $integrity = null;
        foreach ($manifest['assets'] as $asset) {
            if ($asset['path'] === $entry) {
                $integrity = $asset['integrity'];
                break;
            }
        }
        if ($integrity === null) {
            throw new InvalidArgumentException('Missing theme SPA entry integrity');
        }
        $title = $e((string) ($manifest['title'] ?? $name));
        $html = '<!doctype html><html lang="zh"><head><meta charset="utf-8">'.
            '<meta name="viewport" content="width=device-width, initial-scale=1">'.
            '<title>'.$title.'</title>'.$styles.'</head><body><div id="app"></div>'.
            '<script type="module" src="'.$e($url($entry)).'" integrity="'.$e($integrity).
            '" crossorigin="anonymous"></script></body></html>';

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' =>
                "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; ".
                "font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; ".
                "form-action 'self'; frame-ancestors 'none'",
        ]);
    }

    /** @return array{string,string,string} */
    private function trustedVersions(): array
    {
        return [
            (string) config('theme_v2.txboard_version', ''),
            (string) config('theme_v2.gateway_version', ''),
            (string) config('theme_v2.sdk_version', ''),
        ];
    }

    private function locked(callable $callback): mixed
    {
        File::ensureDirectoryExists($this->root());
        $handle = fopen($this->root().'/.runtime.lock', 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('Cannot lock Theme v2 runtime');
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
