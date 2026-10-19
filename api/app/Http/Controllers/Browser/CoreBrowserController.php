<?php

namespace App\Http\Controllers\Browser;

use App\Domains\Browser\BrowserRateLimiter;
use App\Domains\Browser\BrowserResponse;
use App\Domains\Browser\BrowserSurface;
use App\Domains\InternalApi\OperationExecutor;
use App\Domains\InternalApi\OperationSchemas;
use App\Domains\InternalApi\StrictJson;
use App\Services\SiteConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class CoreBrowserController
{
    public function capabilities(Request $request, string $domain, BrowserSurface $surface): JsonResponse
    {
        $operations = [];
        foreach ($surface->operations($domain) as $id) {
            $spec = OperationSchemas::operation($id);
            $item = ['id' => $id, 'version' => '1', 'actor' => $spec['actor'], 'idempotency' => $spec['idempotency']];
            if ($domain === 'admin') $item['permission'] = $spec['permission'];
            $operations[] = $item;
        }
        return BrowserResponse::success($request, ['contract' => 'core-'.$domain.'/v1', 'operations' => $operations]);
    }

    public function operation(Request $request, string $domain, string $operation, BrowserSurface $surface,
        OperationExecutor $executor, BrowserRateLimiter $limiter): JsonResponse
    {
        if (!in_array($operation, $surface->operations($domain), true)) return BrowserResponse::error($request, 'NOT_FOUND', 404);
        if (!$request->isJson()) return BrowserResponse::error($request, 'VALIDATION_ERROR', 400);
        try { $envelope = StrictJson::object($request->getContent(), ['input']); }
        catch (\JsonException) { return BrowserResponse::error($request, 'VALIDATION_ERROR', 400); }
        $spec = OperationSchemas::operation($operation);
        $wire = json_decode($request->getContent(), false, 32, JSON_THROW_ON_ERROR);
        if (array_keys($envelope) !== ['input'] || !OperationSchemas::validator()->acceptsWire($wire->input, $spec['input'])) {
            return BrowserResponse::error($request, 'VALIDATION_ERROR', 422);
        }
        if ($spec['idempotency'] !== 'read' && !Str::isUuid((string) $request->header('Idempotency-Key'))) {
            return BrowserResponse::error($request, 'VALIDATION_ERROR', 400);
        }
        if ($spec['actor'] !== 'anonymous') {
            if (!preg_match('/\ABearer [^\s]{8,4096}\z/D', (string) $request->header('Authorization'))) return BrowserResponse::error($request, 'UNAUTHORIZED', 401);
            if ($domain === 'admin' && !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', (string) $request->header('X-Core-Admin-Path'))) return BrowserResponse::error($request, 'UNAUTHORIZED', 401);
        }
        if (in_array($operation, ['identity.session.create', 'identity.admin-session.create'], true)
            && !$limiter->check(mb_strtolower(trim($envelope['input']['email'])), $request->attributes->get('core_browser_client_ip'))) {
            return BrowserResponse::error($request, 'RATE_LIMITED', 429);
        }
        return BrowserResponse::core($request, $executor->executeBrowser($request, $domain, $operation, $envelope['input']), $operation);
    }

    public function bootstrap(Request $request, SiteConfigService $settings): JsonResponse
    {
        $data = $settings->guest();
        $text = static fn (string $key, string $default = ''): string => is_string($data[$key] ?? null) ? $data[$key] : $default;
        $enabled = in_array($data['is_captcha'] ?? null, [true, 1, '1'], true);
        $type = $enabled && in_array($data['captcha_type'] ?? null, ['turnstile', 'recaptcha', 'recaptcha-v3'], true) ? $data['captcha_type'] : null;
        $field = match ($type) { 'turnstile' => 'turnstile_site_key', 'recaptcha-v3' => 'recaptcha_v3_site_key', default => 'recaptcha_site_key' };
        $key = $type !== null && trim($text($field)) !== '' ? $text($field) : null;
        return BrowserResponse::success($request, [
            'site' => ['name' => $text('app_name', 'TXBoard'), 'description' => $text('app_description'), 'url' => $text('app_url'), 'logo' => $text('logo')],
            'theme' => ['name' => $text('frontend_theme', 'TXBoard'), 'config' => (object) (is_array($data['theme_config'] ?? null) ? $data['theme_config'] : [])],
            'security' => ['captcha' => ['enabled' => $enabled, 'type' => $type, 'siteKey' => $key]],
            // Advertise only the canonical surface actually implemented in this process.
            'capabilities' => array_values(array_filter([
                config('browser.user_enabled') ? 'core.user' : null,
                config('browser.admin_enabled') ? 'core.admin' : null,
            ])),
        ]);
    }
}
