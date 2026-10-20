<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Domains\Platform\NativeModuleAdministration;
use App\Domains\Platform\NativeModulePresentation;
use App\Domains\Platform\NativeThemeDeployment;
use App\Extensions\Contracts\ExtensionFailure;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Fixed Core browser bindings; these methods never expose the legacy plugin list/config endpoints. */
final class NativeModuleAdminController
{
    public function index(Request $request): JsonResponse
    {
        return $this->respond($request, fn () => app(NativeModuleAdministration::class)->index($this->actor($request)));
    }

    public function show(Request $request): JsonResponse
    {
        return $this->respond($request, fn () => app(NativeModuleAdministration::class)->detail($this->actor($request), $request->input('module_id')));
    }

    public function configuration(Request $request): JsonResponse
    {
        return $this->respond($request, fn () => app(NativeModuleAdministration::class)->configuration($this->actor($request), $request->input('module_id')));
    }

    public function saveConfiguration(Request $request): JsonResponse
    {
        return $this->respond($request, fn () => app(NativeModuleAdministration::class)->saveConfiguration($this->actor($request), $request->all(), (string) $request->header('Idempotency-Key')));
    }

    public function lifecycle(Request $request): JsonResponse
    {
        return $this->respond($request, fn () => app(NativeModuleAdministration::class)->lifecycle($this->actor($request), $request->all(), (string) $request->header('Idempotency-Key')));
    }

    public function presentation(Request $request): JsonResponse
    {
        return $this->respond($request, fn () => app(NativeModulePresentation::class)->describe($this->actor($request), $request->input('module_id'), $request->input('fingerprint')));
    }

    public function query(Request $request): JsonResponse
    {
        return $this->respond($request, fn () => app(NativeModulePresentation::class)->invoke($this->actor($request), $request, $request->all(), 'read'));
    }

    public function invoke(Request $request): JsonResponse
    {
        return $this->respond($request, fn () => app(NativeModulePresentation::class)->invoke($this->actor($request), $request, $request->all(), 'domain'));
    }

    public function themePackages(Request $request): JsonResponse
    {
        return $this->respond($request, fn () => app(NativeThemeDeployment::class)->catalog($this->actor($request)));
    }

    public function deployTheme(Request $request): JsonResponse
    {
        return $this->respond($request, fn () => app(NativeThemeDeployment::class)->deploy($this->actor($request), $request->all(), (string) $request->header('Idempotency-Key')));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (!$actor instanceof User) abort(401);
        return $actor;
    }

    private function respond(Request $request, \Closure $work): JsonResponse
    {
        try { $response = TxapiResponse::success($request, $work()); }
        catch (ExtensionFailure $error) { $response = TxapiResponse::error($request, $error->errorCode, 'Module action unavailable', $error->status); }
        catch (\JsonException) { $response = TxapiResponse::error($request, 'MODULE_INPUT_INVALID', 'Module action unavailable', 422); }
        return $response->header('Cache-Control', 'private, no-store');
    }
}
