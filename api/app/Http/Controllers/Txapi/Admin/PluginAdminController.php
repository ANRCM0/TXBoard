<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Models\Plugin;
use App\Services\Plugin\PluginConfigService;
use App\Services\Plugin\PluginManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

final class PluginAdminController
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly PluginConfigService $configs
    ) {}

    public function types(Request $request): JsonResponse
    {
        return TxapiResponse::success($request, [
            ['value' => Plugin::TYPE_FEATURE, 'label' => '功能'],
            ['value' => Plugin::TYPE_PAYMENT, 'label' => '支付方式'],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['sometimes', 'in:feature,payment'],
        ]);
        $type = $filters['type'] ?? null;
        $installed = Plugin::query()
            ->when($type, static fn ($q) => $q->where('type', $type))
            ->get(['code', 'version', 'is_enabled', 'type'])->keyBy('code');
        $items = [];
        $seen = [];

        foreach ($this->plugins->getPluginPaths() as $path) {
            if (!File::isDirectory($path)) continue;
            foreach (File::directories($path) as $directory) {
                $manifestPath = $directory . '/config.json';
                if (!File::isFile($manifestPath)) continue;
                $manifest = json_decode((string) File::get($manifestPath), true);
                if (!is_array($manifest)) continue;
                $code = $manifest['code'] ?? null;
                if (!is_string($code) || !$this->validCode($code) || isset($seen[$code])) continue;
                $seen[$code] = true;
                $pluginType = $manifest['type'] ?? Plugin::TYPE_FEATURE;
                if ($type && $type !== $pluginType) continue;
                $row = $installed->get($code);
                $core = $this->plugins->isCorePlugin($code);
                $readmePath = File::isFile($directory . '/README.md') ? $directory . '/README.md'
                    : $directory . '/readme.md';
                $readme = File::isFile($readmePath) && File::size($readmePath) <= 65536
                    ? File::get($readmePath) : '';
                $items[] = [
                    'code' => $code,
                    'name' => $manifest['name'] ?? $code,
                    'version' => $manifest['version'] ?? null,
                    'description' => $manifest['description'] ?? '',
                    'author' => $manifest['author'] ?? '',
                    'type' => $pluginType,
                    'is_installed' => $row !== null,
                    'is_enabled' => $row?->is_enabled ?? false,
                    'is_protected' => $core,
                    'can_be_deleted' => !$core,
                    'config' => $row ? $this->configs->getConfig($code) : ($manifest['config'] ?? []),
                    'readme' => $readme,
                    'need_upgrade' => $row !== null && version_compare(
                        (string) ($manifest['version'] ?? '0.0.0'),
                        (string) ($row->version ?? '0.0.0'), '>'),
                    'admin_menus' => $manifest['admin_menus'] ?? null,
                    'admin_crud' => $manifest['admin_crud'] ?? null,
                    'package' => $manifest['package'] ?? null,
                    'asset_base' => $this->plugins->getPublicAssetBase($code),
                ];
            }
        }
        usort($items, static fn (array $a, array $b) => strcmp($a['code'], $b['code']));
        return TxapiResponse::success($request, $items)->header('Cache-Control', 'no-store');
    }

    public function config(Request $request): JsonResponse
    {
        $code = $this->code($request);
        if (!Plugin::query()->where('code', $code)->exists()) return $this->missing($request);
        return TxapiResponse::success($request, $this->configs->getConfig($code))
            ->header('Cache-Control', 'private, no-store');
    }

    public function saveConfig(Request $request): JsonResponse
    {
        $code = $this->code($request);
        if (!Plugin::query()->where('code', $code)->exists()) return $this->missing($request);
        $payload = $request->validate(['config' => ['required', 'array', 'max:200']]);
        try {
            $this->configs->updateConfig($code, $payload['config']);
        } catch (\Throwable $e) {
            return TxapiResponse::error($request, 'PLUGIN_CONFIG_FAILED',
                'Plugin configuration could not be saved', 422);
        }
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function action(Request $request): JsonResponse
    {
        $code = $this->code($request);
        $action = (string) $request->route('action');
        if (!in_array($action, ['install', 'uninstall', 'enable', 'disable', 'upgrade'], true)) {
            return TxapiResponse::error($request, 'PLUGIN_ACTION_UNSUPPORTED', 'Unknown plugin action', 404);
        }
        if ($this->plugins->resolvePluginPath($code) === null) return $this->missing($request);
        if ($action === 'uninstall' &&
            Plugin::query()->where('code', $code)->where('is_enabled', true)->exists()) {
            return TxapiResponse::error($request, 'PLUGIN_ENABLED',
                'Disable this plugin before uninstalling', 409);
        }
        try {
            $result = match ($action) {
                'install' => $this->plugins->install($code),
                'uninstall' => $this->plugins->uninstall($code),
                'enable' => $this->plugins->enable($code),
                'disable' => $this->plugins->disable($code),
                'upgrade' => $this->plugins->update($code),
            };
            if ($result !== true) {
                return TxapiResponse::error($request, 'PLUGIN_ACTION_FAILED',
                    'Plugin lifecycle operation did not complete', 409);
            }
        } catch (\Throwable $e) {
            return TxapiResponse::error($request, 'PLUGIN_ACTION_FAILED',
                'Plugin lifecycle operation failed', 409);
        }
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:zip', 'max:10240'],
        ]);
        try {
            // PluginManager enforces archive path/symlink rules, package manifest,
            // dependency validation and staged rollback-safe promotion.
            $this->plugins->upload($data['file']);
        } catch (\Throwable $e) {
            return TxapiResponse::error($request, 'PLUGIN_PACKAGE_REJECTED',
                'Plugin package rejected or installation failed', 422);
        }
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function delete(Request $request): JsonResponse
    {
        $code = $this->code($request);
        if ($this->plugins->isCorePlugin($code)) {
            return TxapiResponse::error($request, 'PLUGIN_PROTECTED',
                'Core plugins cannot be removed', 403);
        }
        if ($this->plugins->resolvePluginPath($code) === null) return $this->missing($request);
        try {
            $this->plugins->delete($code);
        } catch (\Throwable $e) {
            return TxapiResponse::error($request, 'PLUGIN_DELETE_FAILED',
                'Plugin removal failed', 409);
        }
        return TxapiResponse::success($request, ['ok' => true]);
    }

    private function code(Request $request): string
    {
        $code = (string) $request->route('code');
        if (!$this->validCode($code)) {
            throw ValidationException::withMessages(['code' => 'Invalid plugin identifier']);
        }
        return $code;
    }

    private function validCode(string $code): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/D', $code);
    }

    private function missing(Request $request): JsonResponse
    {
        return TxapiResponse::error($request, 'PLUGIN_NOT_FOUND', 'Plugin not found', 404);
    }
}
