<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\Module\ModuleLifecycle;
use App\Services\Module\ModuleLifecycleErrorCode;
use App\Services\Module\ModuleLifecycleOperation;
use App\Services\Module\ModuleLifecycleResult;
use App\Services\Module\ModuleRegistry;
use Illuminate\Http\Request;

class ModuleController extends Controller
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly ModuleLifecycle $lifecycle,
    ) {
    }

    public function index()
    {
        return $this->success($this->modules->snapshot()->toArray());
    }

    public function show(Request $request)
    {
        // The enclosing Admin route also has {admin_path}; read named route
        // parameters rather than receiving that prefix as the first argument.
        $id = (string) $request->route('id');
        $module = $this->modules->find($id);
        if (!$module) {
            return $this->fail([404000, 'Module not found']);
        }

        return $this->success($module->toArray());
    }

    public function lifecycle(Request $request)
    {
        $id = (string) $request->route('id');
        $operations = $this->lifecycle->supportedOperations($id);
        if ($operations === null) {
            return $this->fail([404000, 'Module not found']);
        }

        return $this->success([
            'module_id' => $id,
            'operations' => array_map(
                fn (ModuleLifecycleOperation $operation): string => $operation->value,
                $operations,
            ),
        ]);
    }

    public function executeLifecycle(Request $request)
    {
        $id = (string) $request->route('id');
        $operation = (string) $request->route('operation');
        $lifecycleOperation = ModuleLifecycleOperation::tryFrom($operation);
        if (!$lifecycleOperation) {
            return $this->fail(
                [422000, 'Invalid module lifecycle operation'],
                [
                    'operation' => $operation,
                    'allowed' => array_map(
                        fn (ModuleLifecycleOperation $value): string => $value->value,
                        ModuleLifecycleOperation::cases(),
                    ),
                ],
            );
        }

        $result = $this->lifecycle->execute($id, $lifecycleOperation);
        if ($result->success) {
            return $this->success($result->toArray());
        }

        return $this->lifecycleFailure($result);
    }

    private function lifecycleFailure(ModuleLifecycleResult $result)
    {
        $code = $result->error?->code;

        $response = match ($code) {
            ModuleLifecycleErrorCode::MODULE_NOT_FOUND =>
                [404000, 'Module not found'],
            ModuleLifecycleErrorCode::UNSUPPORTED_MODULE_TYPE =>
                [409000, 'Module lifecycle is not supported for this module type'],
            ModuleLifecycleErrorCode::UNSUPPORTED_OPERATION =>
                [409001, 'Module lifecycle operation is not supported'],
            ModuleLifecycleErrorCode::RUNTIME_ERROR =>
                [500100, 'Module lifecycle runtime failed'],
            ModuleLifecycleErrorCode::STATE_REFRESH_FAILED =>
                [500101, 'Module state refresh failed'],
            default =>
                [500102, 'Module lifecycle failed'],
        };

        return $this->fail($response, $result->toArray());
    }
}
