<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\InternalApi\AdminMutationBoundary;
use App\Models\Setting;
use App\Services\NativeTelegramConfiguration;
use App\Services\NativeSettingsStorage;
use App\Services\NativeSmtpDiagnostic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NativeConfigurationDiagnosticsController
{
    public function smtpInspect(Request $request, NativeSmtpDiagnostic $smtp): JsonResponse
    {
        $read = function () use ($request): array {
            app(NativeSettingsStorage::class)->lock();
            $snapshot = app(NativeSettingsAdminController::class)->snapshot();
            abort_unless(hash_equals($snapshot['version'], (string) $request->input('expected_version')), 409, 'SETTINGS_VERSION_CONFLICT');
            return array_intersect_key($snapshot['values'], array_flip(['email_host', 'email_port', 'email_username', 'email_encryption']))
                + ['email_password' => (string) Setting::query()->useWritePdo()->where('name', 'email_password')->sharedLock()->value('value')];
        };
        $configuration = app(AdminMutationBoundary::class)->run($request->user(), 'settings.write', $read);
        try { $result = $smtp->inspect($configuration); }
        catch (\DomainException) { return TxapiResponse::error($request, 'SMTP_DIAGNOSTIC_FAILED', 'SMTP connection or authentication could not be confirmed', 503); }
        app(AdminMutationBoundary::class)->run($request->user(), 'settings.write', $read);
        return TxapiResponse::success($request, $result)->header('Cache-Control', 'private, no-store');
    }

    public function telegramInspect(Request $request, NativeTelegramConfiguration $telegram): JsonResponse
    {
        $saved = $this->saved($request, false);
        try { $result = $telegram->inspect($saved['token'], $saved['callback']); }
        catch (\DomainException) { return TxapiResponse::error($request, 'TELEGRAM_DIAGNOSTIC_FAILED', 'Telegram diagnostic failed', 503); }
        $this->saved($request, false);
        return TxapiResponse::success($request, $result)->header('Cache-Control', 'private, no-store');
    }

    public function telegramRegister(Request $request, NativeTelegramConfiguration $telegram): JsonResponse
    {
        $saved = $this->saved($request, true);
        try { $telegram->register($saved['token'], $saved['callback']); }
        catch (\DomainException) { return TxapiResponse::error($request, 'TELEGRAM_REGISTRATION_UNCONFIRMED', 'Read webhook diagnostics before retrying registration', 503); }
        $this->saved($request, true);
        return TxapiResponse::success($request, ['ok' => true])->header('Cache-Control', 'private, no-store');
    }

    private function saved(Request $request, bool $write): array
    {
        $read = function () use ($request, $write): array {
            if (!$write) app(InstallationAuthority::class)->assertNativeRead();
            else app(NativeSettingsStorage::class)->lock();
            $snapshot = app(NativeSettingsAdminController::class)->snapshot();
            abort_unless(hash_equals($snapshot['version'], (string) $request->input('expected_version')), 409, 'SETTINGS_VERSION_CONFLICT');
            $token = (string) Setting::query()->useWritePdo()->where('name', 'telegram_bot_token')->sharedLock()->value('value');
            abort_if($token === '', 422, 'TELEGRAM_TOKEN_NOT_CONFIGURED');
            $base = $snapshot['values']['telegram_webhook_url'] ?: $snapshot['values']['app_url'];
            try { $callback = NativeTelegramConfiguration::callback($base, $token); }
            catch (\DomainException) { abort(422, 'TELEGRAM_WEBHOOK_URL_INVALID'); }
            return ['token' => $token, 'callback' => $callback];
        };
        // Release SQL locks before contacting Telegram; verify the same saved revision afterward.
        return $write ? app(AdminMutationBoundary::class)->run($request->user(), 'settings.write', $read) : DB::transaction($read);
    }
}
