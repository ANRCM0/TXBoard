<?php

namespace App\Http\Middleware;

use App\Core\Http\TxapiResponse;
use App\Domains\InternalApi\InternalAudit;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\InstallationAuthorityException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NativeServiceIdentity
{
    public function handle(Request $request, Closure $next)
    {
        $audit = app(InternalAudit::class);
        try {
            $audit->begin($request);
        } catch (\Throwable) {
            return TxapiResponse::error($request, 'AUDIT_UNAVAILABLE', 'Core operation unavailable', 503)
                ->header('Cache-Control', 'private, no-store');
        }
        try {
            $response = $this->authenticate($request, $next, $audit);
        } catch (\Throwable) {
            $response = TxapiResponse::error($request, 'DEPENDENCY_UNAVAILABLE', 'Core operation unavailable', 503);
        }
        try {
            return $audit->finish($request, $response);
        } catch (\Throwable) {
            // Business may already have committed. Never turn a lost audit
            // completion into success or claim that the domain rolled back.
            return TxapiResponse::error($request, 'AUDIT_UNAVAILABLE', 'Core result requires original-key recovery', 503)
                ->header('X-Core-Execution-Id', $request->attributes->get('native_internal_execution'))
                ->header('Cache-Control', 'private, no-store');
        }
    }

    private function authenticate(Request $request, Closure $next, InternalAudit $audit)
    {
        $proxies = config('internal_api.trusted_tls_proxies', []);
        // Never consult isSecure()/forwarded headers: those use the broad
        // public TrustProxies list. Only the actual TLS socket and exact peer
        // are authoritative for this separately configured service boundary.
        $https = strtolower((string) $request->server('HTTPS', ''));
        if (!config('internal_api.enabled') || !is_array($proxies) || $proxies === []
            || !in_array((string) $request->server('REMOTE_ADDR'), $proxies, true)
            || !in_array($https, ['on', '1'], true)
            || $request->header('X-Core-Tls-Verified') !== 'SUCCESS') {
            return TxapiResponse::error($request, 'SERVICE_UNAUTHORIZED', 'Service authentication required', 403);
        }
        try {
            DB::transaction(static fn () => app(InstallationAuthority::class)->assertNativeRead());
        } catch (InstallationAuthorityException) {
            return TxapiResponse::error($request, 'INSTALLATION_NATIVE_NOT_READY', 'Core installation is not ready', 503);
        }
        $id = $request->header('X-Core-Service-Id', '');
        $secret = $request->header('X-Core-Service-Credential', '');
        $epoch = $request->header('X-Core-Service-Epoch', '');
        $fingerprint = $request->header('X-Core-Tls-Certificate-Sha256', '');
        if (!preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $id)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $secret)
            || !preg_match('/\A[1-9][0-9]{0,18}\z/D', $epoch)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint)) {
            return TxapiResponse::error($request, 'SERVICE_UNAUTHORIZED', 'Service authentication required', 401);
        }
        $service = DB::table('tx_internal_service')->useWritePdo()->where('service_id', $id)->first();
        if (!$service || $service->revoked_at !== null || (int) $service->expires_at <= time()
            || $service->security_domain !== $request->route('domain')
            || !hash_equals((string) $service->epoch, $epoch)
            || !hash_equals($service->token_hash, hash('sha256', $secret))
            || !hash_equals($service->certificate_sha256, $fingerprint)) {
            return TxapiResponse::error($request, 'SERVICE_UNAUTHORIZED', 'Service authentication required', 401);
        }
        $request->attributes->set('native_internal_service', $service);
        $audit->service($request, $service);
        return $next($request);
    }
}
