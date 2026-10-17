<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Host\Contracts\TransportSchema;
use App\Core\Host\Modules\ExtensionRuntime;
use App\Core\Http\TxapiResponse;
use App\Core\Kernel\Execution\CurrentInvocation;
use App\Domains\InternalApi\StrictJson;
use App\Extensions\Contracts\ExtensionFailure;
use App\Extensions\Contracts\ExtensionModule;
use App\Extensions\Contracts\ExtensionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ExtensionController
{
    public function user(Request $request, string $code, string $operation, ExtensionRuntime $runtime): JsonResponse
    {
        return $this->invoke($request, $code, $operation, 'user', $runtime);
    }

    public function admin(Request $request, string $code, string $operation, ExtensionRuntime $runtime): JsonResponse
    {
        return $this->invoke($request, $code, $operation, 'admin', $runtime);
    }

    private function invoke(Request $request, string $code, string $operation, string $kind, ExtensionRuntime $runtime): JsonResponse
    {
        try {
            $package = $runtime->package($code);
            $spec = $package['manifest']['operations'][$operation] ?? null;
            if ($spec === null || $spec['actor'] !== $kind) throw new ExtensionFailure('MODULE_OPERATION_NOT_FOUND', 404);
            if (!$request->isJson() || $request->query->count() !== 0 || strlen($request->getContent()) > 32768) {
                throw new ExtensionFailure('MODULE_INPUT_INVALID', 422);
            }
            $envelope = StrictJson::object($request->getContent(), ['input']);
            $wire = json_decode($request->getContent(), false, 32, JSON_THROW_ON_ERROR);
            if (array_keys($envelope) !== ['input'] || !(new TransportSchema)->acceptsWire($wire->input, $spec['input'])) {
                throw new ExtensionFailure('MODULE_INPUT_INVALID', 422);
            }
            $current = app(\App\Core\Host\Modules\ExtensionActors::class)->authorize($kind, $spec['permission']);
            $key = $request->header('Idempotency-Key');
            if ($spec['idempotency'] !== 'read' && (!is_string($key) || !Str::isUuid($key))) {
                throw new ExtensionFailure('MODULE_IDEMPOTENCY_KEY_REQUIRED', 400);
            }
            $context = app(CurrentInvocation::class)->current();
            if ($context === null) throw new ExtensionFailure('MODULE_CONTEXT_UNAVAILABLE', 503);
            $plugin = $runtime->instance($code, ExtensionModule::class, (bool) ($spec['recovery'] ?? false));
            $result = $plugin->handle(new ExtensionRequest($code, $operation, (int) $current->id, $kind,
                $envelope['input'], is_string($key) ? $key : null, $context));
            if (!in_array($result->status, [200, 201, 202], true)) throw new \LogicException('MODULE_RESPONSE_INVALID');
            $data = (new TransportSchema)->project($result->data, $spec['output']);
            $response = TxapiResponse::success($request, $data, status: $result->status);
            if (strlen($response->getContent()) > 1048576) throw new \LogicException('MODULE_RESPONSE_LIMIT');
        } catch (ExtensionFailure $error) {
            $response = TxapiResponse::error($request, $error->errorCode, 'Module operation rejected', $error->status);
        } catch (\JsonException) {
            $response = TxapiResponse::error($request, 'MODULE_INPUT_INVALID', 'Module operation rejected', 422);
        } catch (\Throwable) {
            $response = TxapiResponse::error($request, 'MODULE_DEPENDENCY_UNAVAILABLE', 'Module operation result unavailable', 503);
        }
        return $response->header('Cache-Control', 'private, no-store');
    }
}
