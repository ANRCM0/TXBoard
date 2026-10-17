<?php

namespace App\Core\Host\Modules;

use App\Core\Kernel\Modules\CapabilityDefinition;
use App\Core\Kernel\Modules\CapabilityRequirement;
use App\Core\Kernel\Modules\ModuleDefinition;
use App\Core\Kernel\Modules\ModuleError;
use App\Extensions\Contracts\ExtensionModule;
use App\Extensions\Contracts\NotificationChannel;
use App\Contracts\NativePaymentProviderInterface;
use Illuminate\Support\Str;

/** Fixed, trusted deployment input. No package discovery, installation or actor state in request dispatch. */
final class ModulePackageLock
{
    private array $packages = [];
    private array $unavailable = [];
    private string $fingerprint;

    public function __construct(string $applicationRoot, array $lock)
    {
        if (($lock['schema'] ?? null) !== 1 || !is_array($lock['packages'] ?? null)
            || !array_is_list($lock['packages']) || count($lock['packages']) > 64
            || array_diff(array_keys($lock), ['schema', 'packages'])) throw new ModuleError('MODULE_PACKAGE_LOCK_INVALID');
        $seen = [];
        foreach ($lock['packages'] as $entry) {
            $code = $entry['code'] ?? null;
            if (!is_string($code) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $code) !== 1
                || isset($seen[$code]) || !is_bool($entry['required'] ?? null)) throw new ModuleError('MODULE_PACKAGE_LOCK_INVALID');
            $seen[$code] = true;
            try { $this->packages[$code] = $this->load($applicationRoot, $entry); }
            catch (\Throwable) {
                if ($entry['required']) throw new ModuleError('MODULE_PACKAGE_VERIFICATION_FAILED', 'plugin.'.$code);
                $this->unavailable['plugin.'.$code] = 'MODULE_PACKAGE_VERIFICATION_FAILED';
            }
        }
        ksort($this->packages, SORT_STRING);
        ksort($this->unavailable, SORT_STRING);
        $this->fingerprint = hash('sha256', json_encode($lock, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public static function fromFile(string $applicationRoot, string $file): self
    {
        if (!is_file($file) || filesize($file) > 2097152) throw new ModuleError('MODULE_PACKAGE_LOCK_INVALID');
        return new self($applicationRoot, json_decode(file_get_contents($file), true, 48, JSON_THROW_ON_ERROR));
    }

    public function packages(): array { return $this->packages; }
    public function package(string $code): ?array { return $this->packages[$code] ?? null; }
    public function unavailable(): array { return $this->unavailable; }
    public function fingerprint(): string { return $this->fingerprint; }
    public function providers(): array
    {
        return array_map(static fn (array $package) => new PackageModuleProvider($package['definition']), array_values($this->packages));
    }

    private function load(string $root, array $entry): array
    {
        $code = $entry['code'];
        if (array_diff(array_keys($entry), ['code', 'root', 'version', 'required', 'files', 'grants'])
            || !in_array($entry['root'] ?? null, ['plugins/'.Str::studly($code), 'plugins-core/'.Str::studly($code)], true)
            || !is_array($entry['files'] ?? null) || array_is_list($entry['files'])
            || count($entry['files']) > 2000 || !isset($entry['files']['Plugin.php'], $entry['files']['config.json'], $entry['files']['module.json'])
            || !is_array($entry['grants'] ?? null) || !array_is_list($entry['grants']) || count($entry['grants']) > 64) {
            throw new ModuleError('MODULE_PACKAGE_LOCK_INVALID');
        }
        $path = rtrim($root, '/\\').'/'.$entry['root'];
        $total = 0;
        foreach ($entry['files'] as $relative => $hash) {
            if (!is_string($relative) || strlen($relative) > 240
                || preg_match('~\A[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*\z~D', $relative) !== 1
                || array_intersect(explode('/', $relative), ['.', '..'])
                || !is_string($hash) || preg_match('/\A[0-9a-f]{64}\z/D', $hash) !== 1) throw new ModuleError('MODULE_PACKAGE_LOCK_INVALID');
            $file = rtrim($root, '/\\');
            foreach (explode('/', $entry['root'].'/'.$relative) as $segment) {
                $file .= '/'.$segment;
                if (is_link($file)) throw new ModuleError('MODULE_PACKAGE_LINK_FORBIDDEN');
            }
            if (!is_file($file) || ($total += filesize($file)) > 52428800 || !hash_equals($hash, hash_file('sha256', $file))) {
                throw new ModuleError('MODULE_PACKAGE_HASH_MISMATCH');
            }
        }
        $inventory = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        $count = 0;
        foreach ($inventory as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($path) + 1));
            if (++$count > 2000 || $file->isLink() || !$file->isFile() || !isset($entry['files'][$relative])) {
                throw new ModuleError('MODULE_PACKAGE_UNLISTED_FILE');
            }
        }
        if ($count !== count($entry['files'])) throw new ModuleError('MODULE_PACKAGE_HASH_MISMATCH');
        $manifest = json_decode(file_get_contents($path.'/module.json'), true, 32, JSON_THROW_ON_ERROR);
        $config = json_decode(file_get_contents($path.'/config.json'), true, 32, JSON_THROW_ON_ERROR);
        if (($manifest['schema'] ?? null) !== 'core-module/v1' || ($manifest['id'] ?? null) !== $code
            || ($manifest['version'] ?? null) !== ($entry['version'] ?? null)
            || ($config['code'] ?? null) !== $code || ($config['version'] ?? null) !== $entry['version']
            || ($manifest['execution'] ?? null) !== 'in-process'
            || array_diff(array_keys($manifest), ['schema', 'id', 'version', 'kind', 'execution', 'kernel_api',
                'requires', 'boot_after', 'operations', 'subscriptions', 'config_schema', 'schema_version', 'presentation', 'connector', 'secrets', 'lifecycle'])
            || !is_array($manifest['kernel_api'] ?? null) || !is_int($manifest['schema_version'] ?? null) || $manifest['schema_version'] < 0) {
            throw new ModuleError('MODULE_PACKAGE_MANIFEST_INVALID');
        }
        $this->validateSurface($manifest, $entry['grants']);
        foreach ($manifest['presentation']['assets'] ?? [] as $asset) {
            if (!isset($entry['files'][$asset]) || filesize($path.'/'.$asset) > 2097152) throw new ModuleError('MODULE_PRESENTATION_INVALID');
        }
        $lifecycle = $manifest['lifecycle'] ?? 'managed';
        if (!in_array($lifecycle, ['managed', 'payment-owner'], true)
            || $lifecycle === 'managed' && $manifest['schema_version'] < 1
            || $lifecycle === 'payment-owner' && (($manifest['connector'] ?? null) !== 'payment'
                || $manifest['operations'] !== [] || $manifest['subscriptions'] !== [] || $manifest['schema_version'] !== 0)) {
            throw new ModuleError('MODULE_PACKAGE_MANIFEST_INVALID');
        }
        $provides = [];
        if (($manifest['operations'] ?? []) !== []) $provides[] = new CapabilityDefinition('extension.operations', ExtensionModule::class, multiple: true);
        if (($manifest['subscriptions'] ?? []) !== []) $provides[] = new CapabilityDefinition('notification.channel', NotificationChannel::class, multiple: true);
        if (($manifest['connector'] ?? null) === 'payment') $provides[] = new CapabilityDefinition('payment.channel', NativePaymentProviderInterface::class, multiple: true);
        $requirements = [];
        foreach ($manifest['requires'] ?? [] as $requirement) $requirements[] = new CapabilityRequirement($requirement['id'], $requirement['min'], $requirement['max']);
        $definition = new ModuleDefinition('plugin.'.$code, $entry['version'], $manifest['kind'], $provides, $requirements,
            $manifest['boot_after'] ?? [], $entry['required'], $manifest['kernel_api']['min'], $manifest['kernel_api']['max']);
        return $entry + ['path' => $path, 'manifest' => $manifest, 'definition' => $definition,
            'class' => 'Plugin\\'.Str::studly($code).'\\Plugin',
            'fingerprint' => hash('sha256', json_encode($entry['files'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
    }

    private function validateSurface(array $manifest, array $grants): void
    {
        $code = $manifest['id'];
        if (!in_array($manifest['kind'] ?? null, ['optional-business', 'connector', 'presentation'], true)
            || !is_array($manifest['operations'] ?? null) || count($manifest['operations']) > 64
            || !is_array($manifest['subscriptions'] ?? null) || !array_is_list($manifest['subscriptions']) || count($manifest['subscriptions']) > 32
            || !is_array($manifest['requires'] ?? null) || !array_is_list($manifest['requires']) || count($manifest['requires']) > 64
            || !is_array($manifest['config_schema'] ?? null)) throw new ModuleError('MODULE_PACKAGE_MANIFEST_INVALID');
        foreach ($grants as $grant) {
            if (!is_string($grant) || strlen($grant) > 128 || preg_match('/\A(?:capability|event|permission):[a-z][a-z0-9._-]*\z/D', $grant) !== 1) {
                throw new ModuleError('MODULE_PACKAGE_GRANT_INVALID');
            }
        }
        foreach ($manifest['requires'] as $requirement) {
            if (!is_array($requirement) || array_diff(array_keys($requirement), ['id', 'min', 'max'])
                || !is_string($requirement['id'] ?? null) || !is_int($requirement['min'] ?? null) || !is_int($requirement['max'] ?? null)
                || !in_array('capability:'.$requirement['id'], $grants, true)) throw new ModuleError('MODULE_PACKAGE_GRANT_REQUIRED');
        }
        foreach ($manifest['subscriptions'] as $kind) {
            if (!is_string($kind) || strlen($kind) > 96 || preg_match('/\A[a-z][a-z0-9.-]*\.v[1-9][0-9]*\z/D', $kind) !== 1
                || !in_array('event:'.$kind, $grants, true)) throw new ModuleError('MODULE_PACKAGE_GRANT_REQUIRED');
        }
        \App\Core\Host\Contracts\TransportSchema::assertDefinition($manifest['config_schema']);
        if (!is_array($manifest['secrets'] ?? []) || !array_is_list($manifest['secrets'] ?? []) || count($manifest['secrets'] ?? []) > 16) {
            throw new ModuleError('MODULE_PACKAGE_MANIFEST_INVALID');
        }
        foreach ($manifest['secrets'] ?? [] as $field) {
            if (!is_string($field) || ($manifest['config_schema']['properties'][$field]['type'] ?? null) !== 'string') {
                throw new ModuleError('MODULE_PACKAGE_MANIFEST_INVALID');
            }
        }
        if (isset($manifest['presentation'])) {
            $view = $manifest['presentation'];
            if (!is_array($view) || array_diff(array_keys($view), ['actor', 'permission', 'configuration_permission', 'title', 'entry', 'assets', 'operations'])
                || !in_array($view['actor'] ?? null, ['user', 'admin'], true) || !array_key_exists('permission', $view)
                || !is_string($view['title'] ?? null) || strlen($view['title']) < 1 || strlen($view['title']) > 80
                || !is_array($view['assets'] ?? null) || !array_is_list($view['assets']) || count($view['assets']) > 32
                || !in_array($view['entry'] ?? null, $view['assets'], true)
                || !is_array($view['operations'] ?? null) || !array_is_list($view['operations'])
                || array_diff($view['operations'], array_keys($manifest['operations']))) throw new ModuleError('MODULE_PRESENTATION_INVALID');
            foreach ($view['assets'] as $asset) {
                if (!is_string($asset) || preg_match('~\Aui/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\.(?:html|js|css|svg|png|woff2)\z~D', $asset) !== 1) throw new ModuleError('MODULE_PRESENTATION_INVALID');
            }
            foreach (['permission', 'configuration_permission'] as $field) {
                $permission = $view[$field] ?? null;
                if ($view['actor'] === 'user' && $permission !== null || $view['actor'] === 'admin' && ($field === 'permission' || $permission !== null)
                    && (!is_string($permission) || !str_starts_with($permission, 'extensions.'.$code.'.') || !in_array('permission:'.$permission, $grants, true))) {
                    throw new ModuleError('MODULE_PACKAGE_GRANT_INVALID');
                }
            }
        }
        foreach ($manifest['operations'] as $id => $operation) {
            if (!is_string($id) || preg_match('/\A[a-z][a-z0-9.-]{0,63}\z/D', $id) !== 1 || !is_array($operation)
                || array_diff(array_keys($operation), ['actor', 'permission', 'idempotency', 'input', 'output', 'recovery'])
                || !in_array($operation['actor'] ?? null, ['user', 'admin'], true)
                || !in_array($operation['idempotency'] ?? null, ['read', 'domain'], true)
                || isset($operation['recovery']) && !is_bool($operation['recovery'])
                || !array_key_exists('permission', $operation) || !is_array($operation['input'] ?? null) || !is_array($operation['output'] ?? null)) {
                throw new ModuleError('MODULE_PACKAGE_OPERATION_INVALID');
            }
            $permission = $operation['permission'];
            if ($operation['actor'] === 'admin' && (!is_string($permission) || !str_starts_with($permission, 'extensions.'.$code.'.')
                || !in_array('permission:'.$permission, $grants, true))
                || $operation['actor'] === 'user' && $permission !== null) throw new ModuleError('MODULE_PACKAGE_GRANT_INVALID');
            \App\Core\Host\Contracts\TransportSchema::assertDefinition($operation['input']);
            \App\Core\Host\Contracts\TransportSchema::assertDefinition($operation['output']);
        }
    }
}
