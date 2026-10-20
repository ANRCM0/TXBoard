<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Domains\CredentialProvider\ProviderCodec;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\InternalApi\OperationSchemas;
use App\Http\Requests\Admin\ConfigSave;
use App\Models\Setting;
use App\Services\NativeSettingsStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\InputBag;

final class NativeSettingsAdminController
{
    public function show(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            app(InstallationAuthority::class)->assertNativeRead();
            return TxapiResponse::success($request, $this->snapshot());
        });
    }

    public function save(Request $request): JsonResponse
    {
        if (DB::transactionLevel() === 0) throw new \LogicException('ADMIN_TRANSACTION_REQUIRED');
        app(NativeSettingsStorage::class)->lock();
        if (!hash_equals($this->snapshot()['version'], $request->input('expected_version'))) {
            return TxapiResponse::error($request, 'SETTINGS_VERSION_CONFLICT', 'Settings changed; read them again', 409);
        }
        $values = $request->input('values');
        if ($values === []) return TxapiResponse::error($request, 'SETTINGS_UPDATE_EMPTY', 'No settings supplied', 422);
        $fields = $this->fields();
        $native = [];
        foreach ($values as $name => $value) $native[$fields[$name]['setting']] = $value;
        $form = ConfigSave::createFrom($request, new ConfigSave);
        $form->request->replace($native);
        $form->setJson(new InputBag($native));
        $form->setContainer(app())->setRedirector(app('redirect'));
        $form->validateResolved();
        app(NativeSettingsStorage::class)->persist($form->validated());
        return TxapiResponse::success($request, ['ok' => true]);
    }

    private function fields(): array
    {
        return json_decode(file_get_contents(resource_path('contracts/admin-settings.v1.json')),
            true, 64, JSON_THROW_ON_ERROR)['fields'];
    }

    public function snapshot(): array
    {
        $fields = $this->fields();
        $rows = Setting::query()->useWritePdo()->whereIn('name', array_column($fields, 'setting'))
            ->orderBy('name')->sharedLock()->get()->keyBy('name');
        $values = $credentials = $raw = [];
        foreach ($fields as $name => $field) {
            $value = $rows->get($field['setting'])?->value ?? $field['default'];
            $raw[$name] = $value;
            if ($field['write_only']) {
                $credentials[] = ['name' => $name, 'configured' => $value !== null && $value !== ''];
                continue;
            }
            $type = $field['schema']['type'];
            $value = match ($type) {
                'boolean' => (bool) $value, 'integer' => (int) $value,
                'array' => is_array($value) ? array_values($value) : $field['default'],
                default => (string) $value,
            };
            if (!OperationSchemas::validator()->accepts($value, $field['schema'])) {
                throw new \DomainException('SETTINGS_EVIDENCE_INVALID');
            }
            $values[$name] = $value;
        }
        return ['version' => hash_hmac('sha256', ProviderCodec::canonical($raw), (string) config('app.key')),
            'values' => $values, 'credentials' => $credentials];
    }
}
