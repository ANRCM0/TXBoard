<?php

namespace App\Services\Theme;

use InvalidArgumentException;
use JsonException;
use ZipArchive;

/**
 * Current static Theme Package admission validator.
 *
 * This class does not install, execute, publish, or authorize a theme.
 */
final class ThemeV2Package
{
    private const MANIFEST = 'txboard-theme.json';
    private const MAX_MANIFEST_BYTES = 65536;
    private const NAME_PATTERN = '/\A[a-z0-9][a-z0-9-]{0,62}[a-z0-9]\z/D';
    private const SEMVER = '/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?\z/D';

    private const MAX_ARCHIVE_ENTRIES = 2000;
    private const MAX_UNCOMPRESSED_BYTES = 50 * 1024 * 1024;

    public function assertSafeArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            throw new InvalidArgumentException('Theme package contains too many files');
        }

        $uncompressed = 0;
        $seenEntries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');
            if (str_contains($name, '\\')) {
                throw new InvalidArgumentException('Theme package contains an invalid file path');
            }
            $normalized = $name;

            if (
                $normalized === ''
                || str_contains($normalized, chr(0))
                || str_starts_with($normalized, '/')
                || preg_match('/^[A-Za-z]:\//', $normalized)
            ) {
                throw new InvalidArgumentException('Theme package contains an invalid file path');
            }

            foreach (explode('/', rtrim($normalized, '/')) as $segment) {
                if ($segment === '..') {
                    throw new InvalidArgumentException('Theme package contains path traversal');
                }
                if ($segment === '' || $segment === '.') {
                    throw new InvalidArgumentException('Theme archive contains unsafe path segments');
                }
            }
            $entryId = strtolower(rtrim($normalized, '/'));
            if (isset($seenEntries[$entryId])) {
                throw new InvalidArgumentException('Theme archive contains duplicate paths');
            }
            $seenEntries[$entryId] = true;

            $uncompressed += (int) ($stat['size'] ?? 0);
            if ($uncompressed > self::MAX_UNCOMPRESSED_BYTES) {
                throw new InvalidArgumentException('Theme package uncompressed size exceeds 50MB');
            }

            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)) {
                $mode = ($attributes >> 16) & 0170000;
                if ($mode === 0120000) {
                    throw new InvalidArgumentException('Theme package must not contain symbolic links');
                }
            }
        }
    }


    /**
     * Validate a v2 ZIP without extracting files or modifying settings.
     * Versions must come from trusted deployed runtime metadata, never the ZIP.
     *
     * @return array<string, mixed> validated v2 manifest
     */
    public function validate(ZipArchive $zip, string $txboardVersion, string $gatewayVersion, string $sdkVersion): array
    {
        $this->assertSafeArchive($zip);
        $entry = $this->manifestEntry($zip);
        $prefix = substr($entry, 0, -strlen(self::MANIFEST));
        $files = [];
        $manifestCount = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $path = (string) $zip->getNameIndex($i);
            $relative = $prefix !== '' && str_starts_with($path, $prefix)
                ? substr($path, strlen($prefix))
                : ($prefix === '' ? $path : '');
            if ($relative === '' && $path !== $prefix) {
                throw new InvalidArgumentException('Theme v2 ZIP must have one package root');
            }
            if ($relative === '' || str_ends_with($relative, '/')) {
                continue;
            }
            if ($relative === self::MANIFEST) {
                $manifestCount++;
                continue;
            }
            $base = strtolower(basename($relative));
            if (
                $base === 'config.json' || $base === 'dashboard.blade.php'
                || $base === '.env' || str_starts_with($base, '.env.')
                || preg_match('/\.(?:php|phtml|phar|sh|exe|bat|cmd|ps1|pem|key|p12|pfx)\z/iD', $base)
                || in_array($base, ['id_rsa', 'id_ed25519', 'authorized_keys'], true)
            ) {
                throw new InvalidArgumentException('Theme v2 package contains forbidden executable or secret file');
            }
            // No hidden web-server directives (.htaccess/.user.ini), hidden
            // executables or dotfiles may be published as theme assets.
            if (preg_match('~(?:\\A|/)\\.[^/]+~', $relative)) {
                throw new InvalidArgumentException('Theme v2 contains a forbidden hidden file');
            }
            if (
                $relative !== 'README.md' && $relative !== 'theme.config.schema.json'
                && !preg_match('~\A(?:dist|assets)/[A-Za-z0-9._/-]+\z~D', $relative)
            ) {
                throw new InvalidArgumentException('Theme v2 package contains a file outside the permitted directories');
            }
            if ((str_starts_with($relative, 'dist/') || str_starts_with($relative, 'assets/'))
                && !preg_match('/\\.(?:js|mjs|css|json|map|svg|png|jpe?g|webp|avif|gif|ico|woff2?|ttf|otf|txt|html)\\z/iD', $relative)) {
                throw new InvalidArgumentException('Theme v2 contains an unsupported static asset type');
            }
            if (isset($files[$relative])) {
                throw new InvalidArgumentException('Duplicate Theme v2 asset path');
            }
            $files[$relative] = $path;
        }

        if ($manifestCount !== 1) {
            throw new InvalidArgumentException('Theme v2 ZIP must contain exactly one manifest');
        }
        $raw = $zip->getFromName($entry);
        if (!is_string($raw) || strlen($raw) > self::MAX_MANIFEST_BYTES) {
            throw new InvalidArgumentException('Theme v2 manifest is missing or exceeds 64 KiB');
        }
        try {
            $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Theme v2 manifest must be valid JSON');
        }
        $this->assertObject($manifest, 'manifest');
        $this->known($manifest, ['format','name','title','version','entry','engines','gateway',
            'assets','build','config','dependencies','security','author'], 'manifest');

        if (($manifest['format'] ?? null) !== 2) {
            throw new InvalidArgumentException('Unsupported Theme Package format');
        }
        $name = $manifest['name'] ?? null;
        if (!is_string($name) || !preg_match(self::NAME_PATTERN, $name) || strcasecmp($name, 'TXBoard') === 0) {
            throw new InvalidArgumentException('Invalid or reserved v2 theme name');
        }
        $this->semver($manifest['version'] ?? null, 'theme version');

        $entryConfig = $manifest['entry'] ?? null;
        $this->assertObject($entryConfig, 'entry');
        $this->known($entryConfig, ['type','module'], 'entry');
        if (($entryConfig['type'] ?? null) !== 'spa') {
            // SSR execution isolation and request-scoped credentials are not yet
            // accepted; never execute uploaded server-side JavaScript in PHP.
            throw new InvalidArgumentException('Theme v2 SSR is not enabled');
        }
        $module = $entryConfig['module'] ?? null;
        if (!is_string($module) || !preg_match('~\Adist/[A-Za-z0-9._/-]+\.m?js\z~D', $module)
            || str_contains($module, '/./') || str_contains($module, '/../')
            || !isset($files[$module])) {
            throw new InvalidArgumentException('Theme v2 SPA entry must be a bundled JavaScript file');
        }

        $engines = $manifest['engines'] ?? null;
        $gateway = $manifest['gateway'] ?? null;
        $this->assertObject($engines, 'engines');
        $this->assertObject($gateway, 'gateway');
        $this->known($engines, ['txboard','gateway','contract'], 'engines');
        $this->known($gateway, ['contract','sdk','capabilities'], 'gateway');
        $sdk = $gateway['sdk'] ?? null;
        $this->assertObject($sdk, 'gateway.sdk');
        $this->known($sdk, ['package','version'], 'gateway.sdk');
        if (($sdk['package'] ?? null) !== '@txboard/theme-sdk') {
            throw new InvalidArgumentException('Unsupported theme SDK package');
        }
        if (($engines['contract'] ?? null) !== '1.x' || ($gateway['contract'] ?? null) !== '1.x') {
            throw new InvalidArgumentException('Unsupported theme Gateway contract');
        }
        $this->compatible($txboardVersion, $engines['txboard'] ?? null, 'TXBoard');
        $this->compatible($gatewayVersion, $engines['gateway'] ?? null, 'Gateway');
        $this->compatible($sdkVersion, $sdk['version'] ?? null, 'SDK');
        if (!is_array($gateway['capabilities'] ?? null) || !array_is_list($gateway['capabilities'])) {
            throw new InvalidArgumentException('Theme capability hints must be an array');
        }
        foreach ($gateway['capabilities'] as $capability) {
            if (!is_string($capability) || !preg_match('/\A[a-z][a-z0-9._-]{0,79}\z/D', $capability)) {
                throw new InvalidArgumentException('Invalid theme capability hint');
            }
        }

        $security = $manifest['security'] ?? null;
        $this->assertObject($security, 'security');
        $this->known($security, ['csp','remoteCode','tokenStorage'], 'security');
        if (($security['csp'] ?? null) !== 'strict' || ($security['remoteCode'] ?? null) !== false) {
            throw new InvalidArgumentException('Theme v2 requires strict CSP and remoteCode=false');
        }
        $dependencies = $manifest['dependencies'] ?? null;
        $this->assertObject($dependencies, 'dependencies');
        $this->known($dependencies, ['runtime'], 'dependencies');
        if (($dependencies['runtime'] ?? null) !== []) {
            throw new InvalidArgumentException('Runtime dependencies must be fully bundled');
        }

        $assets = $manifest['assets'] ?? null;
        if (!is_array($assets) || !array_is_list($assets) || count($assets) > 2000) {
            throw new InvalidArgumentException('Invalid theme asset inventory');
        }
        $declared = [];
        foreach ($assets as $asset) {
            $this->assertObject($asset, 'asset');
            $this->known($asset, ['path','integrity'], 'asset');
            $path = $asset['path'] ?? null;
            $integrity = $asset['integrity'] ?? null;
            if (!is_string($path) || !isset($files[$path]) || isset($declared[$path])
                || !preg_match('/\Asha256-([A-Za-z0-9+\/]{43}=)\z/D', (string) $integrity, $match)) {
                throw new InvalidArgumentException('Invalid, missing, or duplicate theme asset integrity');
            }
            $data = $zip->getFromName($files[$path]);
            if (!is_string($data) || !hash_equals($match[1], base64_encode(hash('sha256', $data, true)))) {
                throw new InvalidArgumentException('Theme asset SHA-256 integrity mismatch');
            }
            // Reject readily identifiable active remote-code loaders. This
            // static check is defense-in-depth, not a JavaScript sandbox.
            if (preg_match('/\.m?js\z/iD', $path)
                && preg_match('~(?:\beval\s*\(|\bnew\s+Function\s*\(|\bimportScripts\s*\(|\bimport\s*\(\s*["\x27]https?://|\bnew\s+Worker\s*\(\s*["\x27]https?://)~i', $data)) {
                throw new InvalidArgumentException('Theme v2 contains a forbidden JavaScript loader');
            }
            $declared[$path] = true;
        }
        // The entry module is executable code: it MUST be covered by SRI,
        // even when third-party example manifests only show CSS assets.
        foreach ($files as $path => $_) {
            if ((str_starts_with($path, 'dist/') || str_starts_with($path, 'assets/'))
                && !isset($declared[$path])) {
                throw new InvalidArgumentException('Theme v2 contains an undeclared asset');
            }
        }

        if (array_key_exists('config', $manifest)) {
            $config = $manifest['config'];
            $this->assertObject($config, 'config');
            $this->known($config, ['schema','defaults','public'], 'config');
            if (isset($config['schema']) && $config['schema'] !== 'theme.config.schema.json') {
                throw new InvalidArgumentException('Invalid theme config schema path');
            }
            if (isset($config['schema']) && !isset($files['theme.config.schema.json'])) {
                throw new InvalidArgumentException('Missing theme config schema file');
            }
            if (array_key_exists('defaults', $config)) {
                $defaults = $config['defaults'];
                if (!is_array($defaults) || (array_is_list($defaults) && $defaults !== [])
                    || count($defaults) > 100) {
                    throw new InvalidArgumentException('Theme config defaults must be a bounded object');
                }
                foreach ($defaults as $field => $value) {
                    if (!is_string($field) ||
                        !preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,79}\z/D', $field) ||
                        !(is_scalar($value) || $value === null) ||
                        (is_string($value) && strlen($value) > 4096)) {
                        throw new InvalidArgumentException('Invalid theme configuration default');
                    }
                }
            }
            if (isset($config['public'])) {
                if (!is_array($config['public']) || !array_is_list($config['public'])
                    || count($config['public']) > 100) {
                    throw new InvalidArgumentException('Invalid public config candidates');
                }
                foreach ($config['public'] as $key) {
                    if (!is_string($key) || !preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,79}\z/D', $key)) {
                        throw new InvalidArgumentException('Invalid public config candidate');
                    }
                }
            }
        }

        // Manifest claims are metadata, never permissions or a public-config
        // allowlist. A separate server-owned policy must decide disclosure.
        return $manifest;
    }

    /**
     * Recheck installed theme compatibility against server-owned version
     * metadata on activation and rendering. Package hints are never grants.
     */
    public function assertDeployedCompatibility(
        array $manifest,
        string $txboardVersion,
        string $gatewayVersion,
        string $sdkVersion
    ): void {
        $this->compatible($txboardVersion, $manifest['engines']['txboard'] ?? null, 'TXBoard');
        $this->compatible($gatewayVersion, $manifest['engines']['gateway'] ?? null, 'Gateway');
        $this->compatible($sdkVersion, $manifest['gateway']['sdk']['version'] ?? null, 'SDK');
        if (($manifest['format'] ?? null) !== 2 ||
            ($manifest['entry']['type'] ?? null) !== 'spa' ||
            ($manifest['gateway']['contract'] ?? null) !== '1.x' ||
            ($manifest['security']['csp'] ?? null) !== 'strict' ||
            ($manifest['security']['remoteCode'] ?? null) !== false) {
            throw new InvalidArgumentException('Incompatible installed Theme v2 manifest');
        }
    }

    public function manifestEntry(ZipArchive $zip): string
    {
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $path = (string) $zip->getNameIndex($i);
            if (basename($path) === self::MANIFEST && !str_ends_with($path, '/')) {
                $entries[] = $path;
            }
        }
        if (count($entries) !== 1 || count(explode('/', $entries[0])) > 2) {
            throw new InvalidArgumentException('Theme v2 requires exactly one root manifest');
        }
        return $entries[0];
    }

    private function assertObject(mixed $value, string $label): void
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException("Theme v2 {$label} must be an object");
        }
    }

    private function known(array $value, array $keys, string $label): void
    {
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $keys, true)) {
                throw new InvalidArgumentException("Unknown Theme v2 {$label} field");
            }
        }
    }

    private function semver(mixed $version, string $label): void
    {
        if (!is_string($version) || !preg_match(self::SEMVER, $version)) {
            throw new InvalidArgumentException("Invalid {$label}");
        }
    }

    private function compatible(string $installed, mixed $range, string $label): void
    {
        $this->semver($installed, "{$label} deployed version");
        if (!is_string($range) || trim($range) === '') {
            throw new InvalidArgumentException("Missing {$label} version range");
        }
        foreach (preg_split('/\s+/', trim($range)) as $part) {
            if ($part === '*') {
                continue;
            }
            if (!preg_match('/\A(>=|<=|>|<|=)?(0|[1-9]\d*)(?:\.(0|[1-9]\d*|x|\*))?(?:\.(0|[1-9]\d*|x|\*))?\z/D', $part, $match)) {
                throw new InvalidArgumentException("Unsupported {$label} version constraint");
            }
            $operator = $match[1] ?: '=';
            $major = (int) $match[2];
            $minor = $match[3] ?? '0';
            $patch = $match[4] ?? '0';
            if (in_array($minor, ['x','*'], true) || in_array($patch, ['x','*'], true)) {
                if ($operator !== '=') {
                    throw new InvalidArgumentException("Invalid {$label} wildcard constraint");
                }
                $parts = explode('.', $installed);
                if ((int) $parts[0] !== $major ||
                    (!in_array($minor, ['x','*'], true) && (int) $parts[1] !== (int) $minor)) {
                    throw new InvalidArgumentException("Incompatible {$label} version");
                }
                continue;
            }
            $expected = "{$major}.{$minor}.{$patch}";
            if (!version_compare($installed, $expected, $operator)) {
                throw new InvalidArgumentException("Incompatible {$label} version");
            }
        }
    }
}
