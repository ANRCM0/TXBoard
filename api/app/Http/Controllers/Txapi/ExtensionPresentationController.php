<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Host\Modules\ExtensionActors;
use App\Core\Host\Modules\ExtensionRuntime;
use App\Core\Host\Modules\ModuleDeployment;
use App\Core\Host\Contracts\TransportSchema;
use App\Core\Http\TxapiResponse;
use App\Domains\InternalApi\StrictJson;
use App\Extensions\Contracts\DeploymentModule;
use App\Extensions\Contracts\ExtensionFailure;
use App\Models\Plugin;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ExtensionPresentationController
{
    public function user(Request $request, string $code): Response { return $this->describe($request, $code, 'user'); }
    public function admin(Request $request, string $code): Response { return $this->describe($request, $code, 'admin'); }
    public function userResource(Request $request, string $code, string $asset): Response { return $this->resource($request, $code, $asset, 'user'); }
    public function adminResource(Request $request, string $code, string $asset): Response { return $this->resource($request, $code, $asset, 'admin'); }

    public function configure(Request $request, string $code): Response
    {
        return $this->respond($request, function () use ($request, $code): Response {
            [$package, $view] = $this->authorize($request, $code, 'admin', true);
            $permission = $view['configuration_permission'] ?? null;
            if ($permission === null) throw new ExtensionFailure('MODULE_CONFIGURATION_PERMISSION_REQUIRED', 403);
            app(ExtensionActors::class)->authorize('admin', $permission);
            if (!$request->isJson() || strlen($request->getContent()) > 32768 || $request->query->count() !== 0) throw new ExtensionFailure('MODULE_INPUT_INVALID', 422);
            $input = StrictJson::object($request->getContent(), ['configuration']);
            $key = $request->header('Idempotency-Key');
            if (array_keys($input) !== ['configuration'] || !is_array($input['configuration']) || !is_string($key) || !Str::isUuid($key)) {
                throw new ExtensionFailure('MODULE_INPUT_INVALID', 422);
            }
            $wire = json_decode($request->getContent(), false, 32, JSON_THROW_ON_ERROR);
            if (!(new TransportSchema)->acceptsWire($wire->configuration, $package['manifest']['config_schema'])) throw new ExtensionFailure('MODULE_INPUT_INVALID', 422);
            $result = app(ModuleDeployment::class)->execute($code, 'configure', $key, $input['configuration']);
            return TxapiResponse::success($request, $result);
        });
    }

    private function describe(Request $request, string $code, string $kind): Response
    {
        return $this->respond($request, function () use ($request, $code, $kind): Response {
            [$package, $view] = $this->authorize($request, $code, $kind);
            $operations = array_intersect_key($package['manifest']['operations'], array_flip($view['operations']));
            foreach ($operations as &$operation) {
                $operation['input'] = TransportSchema::wireDefinition($operation['input']);
                $operation['output'] = TransportSchema::wireDefinition($operation['output']);
            }
            unset($operation);
            $data = ['schema' => 'module-presentation/v1', 'module' => $code, 'version' => $package['version'], 'fingerprint' => $package['fingerprint'],
                'actor' => $kind, 'title' => $view['title'], 'entry' => $view['entry'], 'assets' => $view['assets'], 'operations' => (object) $operations];
            if ($kind === 'admin' && isset($view['configuration_permission'])) {
                $row = Plugin::query()->useWritePdo()->where('code', $code)->firstOrFail();
                $configuration = json_decode($row->config ?: '{}', true, 32, JSON_THROW_ON_ERROR);
                // Managed configurations contain named secret references, never resolved secret values.
                app(ExtensionRuntime::class)->validateConfiguration($package, $configuration);
                $data += ['configuration_schema' => TransportSchema::wireDefinition($package['manifest']['config_schema']), 'configuration' => (object) $configuration,
                    'configuration_version' => (string) $row->module_config_version];
            }
            return TxapiResponse::success($request, $data);
        });
    }

    private function resource(Request $request, string $code, string $asset, string $kind): Response
    {
        return $this->respond($request, function () use ($request, $code, $asset, $kind): Response {
            [$package, $view] = $this->authorize($request, $code, $kind);
            if (!in_array($asset, $view['assets'], true) || !isset($package['files'][$asset])) throw new ExtensionFailure('MODULE_RESOURCE_NOT_FOUND', 404);
            $path = $package['path'].'/'.$asset;
            if (!is_file($path) || is_link($path) || filesize($path) > 2097152 || !hash_equals($package['files'][$asset], hash_file('sha256', $path))) {
                throw new ExtensionFailure('MODULE_RESOURCE_NOT_FOUND', 404);
            }
            $type = match (pathinfo($asset, PATHINFO_EXTENSION)) {
                'html' => 'text/html; charset=UTF-8', 'js' => 'text/javascript; charset=UTF-8', 'css' => 'text/css; charset=UTF-8',
                'svg' => 'image/svg+xml', 'png' => 'image/png', 'woff2' => 'font/woff2', default => 'application/octet-stream',
            };
            return response(file_get_contents($path), 200, ['Content-Type' => $type, 'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; frame-ancestors 'none'",
                'Cross-Origin-Resource-Policy' => 'same-origin']);
        });
    }

    private function authorize(Request $request, string $code, string $kind, bool $configure = false): array
    {
        $package = app(ExtensionRuntime::class)->package($code, $request->header('X-Core-Module-Fingerprint'));
        $view = $package['manifest']['presentation'] ?? null;
        if ($view === null || $view['actor'] !== $kind) throw new ExtensionFailure('MODULE_PRESENTATION_NOT_FOUND', 404);
        app(ExtensionActors::class)->authorize($kind, $configure ? ($view['configuration_permission'] ?? null) : $view['permission']);
        if ($configure) app(ExtensionRuntime::class)->admission($code, true);
        else app(ExtensionRuntime::class)->instance($code, DeploymentModule::class);
        return [$package, $view];
    }

    private function respond(Request $request, \Closure $work): Response
    {
        try { $response = $work(); }
        catch (ExtensionFailure $error) { $response = TxapiResponse::error($request, $error->errorCode, 'Module presentation unavailable', $error->status); }
        catch (\JsonException) { $response = TxapiResponse::error($request, 'MODULE_INPUT_INVALID', 'Module presentation unavailable', 422); }
        catch (\Throwable) { $response = TxapiResponse::error($request, 'MODULE_PRESENTATION_UNAVAILABLE', 'Module presentation unavailable', 503); }
        return $response->header('Cache-Control', 'private, no-store');
    }
}
