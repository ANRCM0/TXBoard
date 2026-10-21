<?php

namespace App\Domains\Platform;

use App\Core\Host\Contracts\TransportSchema;
use App\Core\Host\Modules\ExtensionRuntime;
use App\Domains\Installation\Contracts\InstallationAdmission;
use App\Domains\InternalApi\StrictJson;
use App\Extensions\Contracts\ExtensionFailure;
use App\Http\Controllers\Txapi\ExtensionController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A native operation console for the fixed package's declared admin presentation. */
final class NativeModulePresentation
{
    public function __construct(private readonly NativeModuleAdministration $modules, private readonly ExtensionRuntime $runtime,
        private readonly InstallationAdmission $installation) {}

    public function describe(User $actor, string $id, string $fingerprint): array
    {
        [$package, $view] = $this->authorize($actor, $id, $fingerprint);
        $operations = [];
        foreach ($view['operations'] as $id) {
            $spec = $package['manifest']['operations'][$id];
            if (!NativeModuleActor::permitted($actor, $spec['permission'])) continue;
            $operations[] = ['id' => $id, 'mode' => $spec['idempotency'],
                'input_schema_json' => $this->json(TransportSchema::wireDefinition($spec['input']), 32768),
                'output_schema_json' => $this->json(TransportSchema::wireDefinition(ModulePublicProjection::schema($spec['output'])), 32768)];
        }
        return ['module_id' => $package['code'], 'title' => $view['title'], 'fingerprint' => $package['fingerprint'],
            'entry' => $view['entry'], 'operations' => $operations];
    }

    public function invoke(User $actor, Request $request, array $input, string $mode): array
    {
        [$package, $view] = $this->authorize($actor, $input['module_id'], $input['fingerprint']);
        $id = $input['operation_id'];
        $spec = $package['manifest']['operations'][$id] ?? null;
        if (!in_array($id, $view['operations'], true) || $spec === null || $spec['actor'] !== 'admin') throw new ExtensionFailure('MODULE_OPERATION_NOT_FOUND', 404);
        if ($spec['idempotency'] !== $mode) throw new ExtensionFailure('MODULE_OPERATION_MODE_CONFLICT', 422);
        DB::transaction(static fn () => NativeModuleActor::assertLocked($actor, ['platform.modules.read', $view['permission'], $spec['permission']]));
        $payload = StrictJson::object($input['input_json']);
        $wire = json_decode($input['input_json'], false, 32, JSON_THROW_ON_ERROR);
        if (!(new TransportSchema)->acceptsWire($wire, $spec['input'])) throw new ExtensionFailure('MODULE_INPUT_INVALID', 422);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => $request->header('Authorization'),
            'HTTP_X_CORE_MODULE_FINGERPRINT' => $package['fingerprint']];
        if ($mode === 'domain') $headers['HTTP_IDEMPOTENCY_KEY'] = $request->header('Idempotency-Key');
        $delegated = Request::create('/native-module-operation', 'POST', [], [], [], $headers, $this->json(['input' => (object) $payload], 33000));
        $delegated->setUserResolver(static fn () => $actor);
        $response = app(ExtensionController::class)->admin($delegated, '', $package['code'], $id, $this->runtime);
        $body = $response->getData(true);
        if ($response->getStatusCode() >= 400) {
            $code = $body['error']['code'] ?? 'MODULE_DEPENDENCY_UNAVAILABLE';
            $status = $response->getStatusCode();
            if (!is_string($code) || preg_match('/\AMODULE_[A-Z0-9_]{1,64}\z/D', $code) !== 1) $code = 'MODULE_DEPENDENCY_UNAVAILABLE';
            throw new ExtensionFailure($code, in_array($status, [400, 403, 404, 409, 422, 503], true) ? $status : 503);
        }
        // The existing owner already applied its declared output schema. The host then removes credential fields and values.
        $public = ModulePublicProjection::data($response->getData()->data);
        return ['module_id' => $package['code'], 'operation_id' => $id,
            'result_json' => $this->json($public, 65536),
            'status' => $response->getStatusCode()];
    }

    private function authorize(User $actor, string $id, string $fingerprint): array
    {
        $package = $this->modules->managed($id, $fingerprint);
        $view = $package['manifest']['presentation'] ?? null;
        if (($view['actor'] ?? null) !== 'admin') throw new ExtensionFailure('MODULE_PRESENTATION_NOT_FOUND', 404);
        DB::transaction(function () use ($actor, $view): void {
            $this->installation->assertNativeRead();
            NativeModuleActor::assertLocked($actor, ['platform.modules.read', $view['permission']]);
        });
        $this->runtime->admission($id);
        return [$package, $view];
    }

    private function json(mixed $data, int $limit): string
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > $limit) throw new ExtensionFailure('MODULE_DISPLAY_LIMIT', 422);
        return $encoded;
    }
}
