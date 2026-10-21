<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Domains\CredentialProvider\Contracts\ProviderClient;
use App\Domains\CredentialProvider\Contracts\TemplateValidationClient;
use App\Domains\CredentialProvider\RenderGrantCodec;
use App\Domains\CredentialProvider\ProviderError;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\InternalApi\AdminMutationBoundary;
use App\Domains\Subscription\Services\NativeSubscriptionTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NativeSubscriptionTemplateController
{
    public function index(Request $request, NativeSubscriptionTemplates $templates): JsonResponse
    {
        return DB::transaction(function () use ($request, $templates): JsonResponse {
            app(InstallationAuthority::class)->assertNativeRead();
            return TxapiResponse::success($request, ['templates' => array_map(fn ($format) => $templates->snapshot($format),
                array_keys(RenderGrantCodec::CLIENT_FORMATS)), 'providers' => $this->providers()]);
        });
    }

    public function save(Request $request, NativeSubscriptionTemplates $templates, ProviderClient $providers,
        TemplateValidationClient $validator): JsonResponse
    {
        $format = (string) $request->input('format_id');
        $source = (string) $request->input('source');
        NativeSubscriptionTemplates::assertSource($source);
        $input = $request->only(['format_id', 'expected_version', 'source', 'provider_id']);
        $key = (string) $request->header('Idempotency-Key');
        $receipt = fn () => $templates->receipt((int) $request->user()->id, 'save', $key, $input);
        $read = function () use ($request, $templates, $providers, $format): array {
            abort_unless(in_array($request->input('provider_id'), $this->providers(), true), 422, 'SUBSCRIPTION_TEMPLATE_PROVIDER_INVALID');
            abort_unless(hash_equals($templates->snapshot($format)['version'], (string) $request->input('expected_version')), 409, 'SUBSCRIPTION_TEMPLATE_VERSION_CONFLICT');
            return $providers->configuration((string) $request->input('provider_id'));
        };
        $before = app(AdminMutationBoundary::class)->run($request->user(), 'settings.write', function () use ($receipt, $read): ?array {
            return $receipt() ? null : $read();
        });
        if ($before === null) return TxapiResponse::success($request, ['ok' => true]);
        // The actual provider parser validates synthetic data outside SQL locks.
        try { $validator->validateTemplate($before, $format, $source); }
        catch (ProviderError $failure) {
            if ($failure->codeName === 'PROVIDER_TEMPLATE_SOURCE_INVALID') {
                return TxapiResponse::error($request, 'SUBSCRIPTION_TEMPLATE_SOURCE_INVALID', 'Template parser rejected the source', 422);
            }
            if ($failure->codeName === 'PROVIDER_TEMPLATE_ENGINE_UNSUPPORTED') {
                return TxapiResponse::error($request, 'SUBSCRIPTION_TEMPLATE_ENGINE_UNSUPPORTED', 'The selected provider does not support this template engine', 409);
            }
            throw $failure;
        }
        app(AdminMutationBoundary::class)->run($request->user(), 'settings.write', function () use ($read, $receipt, $before, $templates, $format, $request, $source, $key, $input): void {
            if ($receipt()) return;
            abort_unless($read() === $before, 409, 'SUBSCRIPTION_TEMPLATE_PROVIDER_CHANGED');
            $templates->replace($format, (string) $request->input('expected_version'), $source);
            $templates->remember((int) $request->user()->id, 'save', $key, $input);
        });
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function reset(Request $request, NativeSubscriptionTemplates $templates): JsonResponse
    {
        // The operation executor supplies the shared mutation boundary.
        $input = $request->only(['format_id', 'expected_version']);
        $key = (string) $request->header('Idempotency-Key');
        if ($templates->receipt((int) $request->user()->id, 'reset', $key, $input)) return TxapiResponse::success($request, ['ok' => true]);
        $templates->replace((string) $request->input('format_id'), (string) $request->input('expected_version'), null);
        $templates->remember((int) $request->user()->id, 'reset', $key, $input);
        return TxapiResponse::success($request, ['ok' => true]);
    }

    private function providers(): array
    {
        $ids = array_keys((array) config('credential_provider.providers', []));
        sort($ids, SORT_STRING);
        abort_if(count($ids) > 32, 503, 'SUBSCRIPTION_TEMPLATE_PROVIDER_LIMIT');
        return $ids;
    }
}
