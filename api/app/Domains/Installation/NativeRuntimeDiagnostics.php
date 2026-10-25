<?php

namespace App\Domains\Installation;

use App\Core\Host\Modules\ModuleRuntime;
use App\Core\Kernel\Modules\ModuleError;
use App\Domains\CredentialProvider\Contracts\ProviderClient;
use App\Domains\CredentialProvider\ProviderError;
use App\Domains\Subscription\Services\NativeCredentialProviderService;
use App\Models\Setting;
use Closure;
use DomainException;
use Throwable;

/** Recovery-only report. Its command is deliberately outside the activated runtime digest. */
final class NativeRuntimeDiagnostics
{
    public const SCHEMA = 'core-diagnostics/v1';

    public function report(bool $showAdminPath = false): array
    {
        $sections = [];
        $sections['configuration'] = $this->section(fn (): array => $this->configuration(), 'CONFIGURATION_UNAVAILABLE');
        $sections['installation'] = $this->section(fn (): array => app(InstallationAuthority::class)->inspect(), 'INSTALLATION_UNAVAILABLE');
        $native = config('core_installation.mode') === 'native' || ($sections['installation']['data']['mode'] ?? null) === 'native';
        $sections['runtime'] = $native
            ? $this->section(function (): array {
                $report = app(InstallationAuthority::class)->runtimeReport();
                return ['ready' => $report['ready'], 'installation_id' => $report['installation_id'],
                    'authority_epoch' => $report['authority_epoch'], 'composition_fingerprint' => $report['composition_fingerprint'],
                    'release_id' => $report['release_id'], 'diagnostic_count' => count($report['diagnostics'])];
            }, 'RUNTIME_UNAVAILABLE')
            : ['status' => isset($sections['installation']['data']) ? 'not_applicable' : 'error',
                'error_code' => isset($sections['installation']['data']) ? null : 'INSTALLATION_UNAVAILABLE'];
        $sections['administrator_entry'] = $this->section(fn (): array => $this->adminEntry($showAdminPath), 'ADMINISTRATOR_ENTRY_UNAVAILABLE');
        $sections['providers'] = $this->section(fn (): array => $this->providers(), 'PROVIDER_DIAGNOSTICS_UNAVAILABLE');
        $sections['modules'] = $this->section(function (): array {
            $inventory = app(ModuleRuntime::class)->inventory();
            return ['composition_fingerprint' => $inventory['composition'], 'kernel_api' => $inventory['kernel_api'],
                'module_count' => count($inventory['modules']), 'unavailable_count' => count($inventory['unavailable'])];
        }, 'MODULE_DIAGNOSTICS_UNAVAILABLE');

        return ['schema' => self::SCHEMA, 'read_only' => true, 'external_services_probed' => false,
            'healthy' => !in_array('error', array_column($sections, 'status'), true), 'sections' => $sections];
    }

    private function section(Closure $read, string $fallback): array
    {
        try {
            return ['status' => 'ok', 'data' => $read()];
        } catch (Throwable $error) {
            $code = $fallback;
            if (($error instanceof InstallationAuthorityException || $error instanceof ProviderError
                || $error instanceof ModuleError || $error instanceof DomainException)
                && preg_match('/\A(?:INSTALLATION_AUTHORITY|PROVIDER|MODULE|NATIVE_CREDENTIAL|DIAGNOSTIC)_[A-Z0-9_]{1,96}\z/D', $error->getMessage()) === 1) {
                $code = $error->getMessage();
            }
            return ['status' => 'error', 'error_code' => $code];
        }
    }

    private function configuration(): array
    {
        $appOrigin = $this->origin(config('app.url'));
        $browserOrigin = $this->origin(config('browser.origin'));
        if ($appOrigin === null || $browserOrigin === null) { throw new DomainException('DIAGNOSTIC_ORIGIN_INVALID'); }
        if (!is_string(config('app.key')) || config('app.key') === '') { throw new DomainException('DIAGNOSTIC_APP_KEY_MISSING'); }
        if (!in_array(config('core_installation.mode'), ['native', 'legacy'], true)
            || $this->decimal(config('core_installation.worker_epoch')) === null) { throw new DomainException('DIAGNOSTIC_INSTALLATION_CONFIGURATION_INVALID'); }
        return ['php_version' => PHP_VERSION, 'configuration_cached' => app()->configurationIsCached(),
            'app_origin' => $appOrigin, 'browser_origin' => $browserOrigin,
            'browser_enabled' => (bool) config('browser.enabled'),
            'browser_admin_enabled' => (bool) config('browser.admin_enabled'),
            'expected_mode' => in_array(config('core_installation.mode'), ['native', 'legacy'], true) ? config('core_installation.mode') : 'invalid',
            'worker_epoch' => $this->decimal(config('core_installation.worker_epoch')),
            'app_key_configured' => is_string(config('app.key')) && config('app.key') !== '',
            'database_driver' => in_array(config('database.connections.'.config('database.default').'.driver'),
                ['mysql', 'pgsql', 'sqlite', 'sqlsrv'], true) ? config('database.connections.'.config('database.default').'.driver') : 'unknown',
            'cache_driver' => $this->identifier(config('cache.default')), 'queue_driver' => $this->identifier(config('queue.default'))];
    }

    private function adminEntry(bool $show): array
    {
        // Read only these model-owned settings, without populating or clearing shared Redis caches.
        $settings = Setting::query()->whereIn('name', ['secure_path', 'frontend_admin_path'])->limit(3)->get(['name', 'value'])->keyBy('name');
        $raw = $settings->get('secure_path')?->value ?? $settings->get('frontend_admin_path')?->value ?? hash('crc32b', (string) config('app.key'));
        if (!is_string($raw)) { throw new DomainException('DIAGNOSTIC_ADMINISTRATOR_PATH_INVALID'); }
        $path = trim($raw);
        if (preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $path) !== 1) { throw new DomainException('DIAGNOSTIC_ADMINISTRATOR_PATH_INVALID'); }
        return ['source' => 'persisted_settings_with_runtime_fallback', 'path_disclosed' => $show,
            'path' => $show ? '/'.$path : '[redacted]', 'shared_setting_cache_probed' => false];
    }

    private function providers(): array
    {
        $configured = config('credential_provider.providers', []);
        if (!is_array($configured) || count($configured) > 100) { throw new DomainException('DIAGNOSTIC_PROVIDER_LIMIT'); }
        $registrations = app(NativeCredentialProviderService::class)->diagnosticRegistrations();
        $entries = [];
        foreach (array_keys($configured) as $id) {
            if (!is_string($id) || preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $id) !== 1) { throw new DomainException('DIAGNOSTIC_PROVIDER_ID_INVALID'); }
            $configuration = app(ProviderClient::class)->configuration($id);
            $registered = array_values(array_filter($registrations, static fn (array $row): bool => $row['provider_id'] === $id));
            $current = count($registered) === 1
                && $registered[0]['provider_instance_epoch'] === $configuration['provider_instance_epoch']
                && hash_equals($registered[0]['config_digest'], $configuration['config_digest']);
            $entries[] = ['provider_id' => $id, 'instance_epoch' => $configuration['provider_instance_epoch'],
                'profiles' => $configuration['profiles'], 'configuration_digest' => $configuration['config_digest'],
                'registered' => $registered !== [], 'registration_matches_configuration' => $current];
        }
        return ['configured_count' => count($configured), 'registered_count' => count($registrations), 'providers' => $entries,
            'unconfigured_registration_count' => count(array_filter($registrations, static fn (array $row): bool => !array_key_exists($row['provider_id'], $configured)))];
    }

    private function origin(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > 2048 || preg_match('/[^\x21-\x7e]/', $value) || str_contains($value, '\\')) { return null; }
        $url = parse_url($value);
        if (!is_array($url) || !in_array($url['scheme'] ?? null, ['http', 'https'], true) || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || !in_array($url['path'] ?? '', ['', '/'], true)) { return null; }
        return strtolower($url['scheme'].'://'.$url['host']).(isset($url['port']) ? ':'.$url['port'] : '');
    }

    private function decimal(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $value) === 1 ? $value : null;
    }

    private function identifier(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $value) === 1 ? $value : null;
    }
}
