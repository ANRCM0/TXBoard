<?php

namespace App\Http\Middleware;

use App\Core\Http\TxapiResponse;
use App\Domains\Browser\BrowserResponse;
use App\Domains\Browser\BrowserRateLimiter;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\InternalApi\InternalAudit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class BrowserIngress
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($rejection = $this->preflight($request)) return $rejection;
        $audit = app(InternalAudit::class);
        try {
            $audit->begin($request);
            $audit->browser($request);
            $request->attributes->remove('native_internal_service');
            $request->attributes->set('core_browser_admitted', true);
            DB::transaction(static fn () => app(InstallationAuthority::class)->assertNativeRead());
            $response = $next($request);
            if (strlen($response->getContent()) > (int) config('browser.max_response_bytes')) $response = BrowserResponse::error($request, 'UPSTREAM_ERROR', 502);
            return $audit->finish($request, $response);
        } catch (\Throwable) {
            $response = BrowserResponse::error($request, 'UPSTREAM_UNAVAILABLE', 503);
            try { return $audit->finish($request, $response); } catch (\Throwable) { return $response; }
        }
    }

    public function preflight(Request $request): ?Response
    {
        if ($request->attributes->get('core_browser_preflight') === true) return null;
        try {
            if ($rejection = $this->guard($request)) return $rejection;
            if (!app(BrowserRateLimiter::class)->ingress($request->attributes->get('core_browser_client_ip'))) {
                return BrowserResponse::error($request, 'RATE_LIMITED', 429)->header('Retry-After', '60');
            }
            $request->attributes->set('core_browser_preflight', true);
            return null;
        } catch (\Throwable) { return BrowserResponse::error($request, 'UPSTREAM_UNAVAILABLE', 503); }
    }

    private function guard(Request $request): ?Response
    {
        if (!config('browser.enabled')) return BrowserResponse::error($request, 'NOT_FOUND', 404);
        if ($request->query->count() !== 0) return BrowserResponse::error($request, 'VALIDATION_ERROR', 400);
        foreach ($request->headers->keys() as $name) {
            if (str_starts_with($name, 'x-core-service-') || str_starts_with($name, 'x-core-tls-')
                || in_array($name, ['x-http-method-override', 'x-method-override', 'x-original-url', 'x-rewrite-url'], true)) {
                return BrowserResponse::error($request, 'FORBIDDEN', 403);
            }
        }
        $origin = $request->header('Origin');
        $configured = rtrim((string) config('browser.origin'), '/');
        $parts = parse_url($configured);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['path']) && $parts['path'] !== ''
            || !in_array($parts['scheme'], ['http', 'https'], true)
            || $parts['scheme'] === 'http' && !in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true)) {
            return BrowserResponse::error($request, 'UPSTREAM_UNAVAILABLE', 503);
        }
        if ($origin !== null && ($origin === 'null' || !hash_equals($configured, $origin))
            || $request->header('Sec-Fetch-Site') === 'cross-site') return BrowserResponse::error($request, 'FORBIDDEN', 403);
        $host = strtolower((string) $request->header('Host'));
        $expected = strtolower($parts['host']);
        $port = (int) ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80));
        $defaultPort = $parts['scheme'] === 'https' ? 443 : 80;
        $hosts = $port === $defaultPort ? [$expected, $expected.':'.$port] : [$expected.':'.$port];
        if (!in_array($host, $hosts, true)) return BrowserResponse::error($request, 'FORBIDDEN', 403);
        $ip = (string) $request->server('REMOTE_ADDR', 'unknown');
        if (in_array($ip, config('browser.trusted_proxies', []), true) && $request->hasHeader('X-Core-Browser-Client-IP')) {
            $forwarded = (string) $request->header('X-Core-Browser-Client-IP');
            if (!filter_var($forwarded, FILTER_VALIDATE_IP) || inet_ntop(inet_pton($forwarded)) !== $forwarded) {
                return BrowserResponse::error($request, 'VALIDATION_ERROR', 400);
            }
            $ip = $forwarded;
        }
        $request->attributes->set('core_browser_client_ip', $ip);
        if (strlen($request->getContent()) > (int) config('browser.max_request_bytes')) return BrowserResponse::error($request, 'PAYLOAD_TOO_LARGE', 413);
        if (in_array($request->method(), ['GET', 'HEAD'], true) && $request->getContent() !== '') return BrowserResponse::error($request, 'VALIDATION_ERROR', 400);
        return null;
    }
}
