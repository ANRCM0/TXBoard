<?php

namespace App\Domains\InternalApi;

use App\Core\Http\TxapiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Boundary audit accepts only trusted IDs and compiled vocabulary, never input. */
final class InternalAudit
{
    public function begin(Request $request): void
    {
        $execution = (string) Str::uuid();
        $operation = (string) $request->route('operation', '');
        $domain = (string) $request->route('domain', '');
        DB::table('tx_internal_audit')->insert([
            'execution_id' => $execution, 'request_id' => TxapiResponse::requestId($request),
            'service_id' => 'unauthenticated', 'service_epoch' => 0, 'actor_id' => null,
            'operation' => array_key_exists($operation, OperationRegistry::all()) ? $operation : 'unknown',
            'security_domain' => in_array($domain, ['user', 'admin'], true) ? $domain : 'unknown',
            'state' => 'started', 'created_at' => time(),
        ]);
        $request->attributes->set('native_internal_execution', $execution);
    }

    public function service(Request $request, object $service): void
    {
        $this->update($request, ['service_id' => $service->service_id, 'service_epoch' => $service->epoch]);
    }

    public function browser(Request $request): void
    {
        // Source marker is compiled application vocabulary, not an authenticated service grant.
        $this->update($request, ['service_id' => 'browser', 'service_epoch' => 0]);
    }

    public function actor(Request $request, ?int $actorId, string $clientTrace): void
    {
        $this->update($request, ['actor_id' => $actorId, 'request_id' => $clientTrace]);
    }

    public function trace(Request $request, string $clientTrace): void
    {
        $this->update($request, ['request_id' => $clientTrace]);
    }

    public function identifiedActor(Request $request, int $actorId): void
    {
        $this->update($request, ['actor_id' => $actorId]);
    }

    public function finish(Request $request, Response $response): Response
    {
        $code = null;
        if ($response instanceof JsonResponse) {
            $body = $response->getData(true);
            $candidate = $body['error']['code'] ?? null;
            if (is_string($candidate) && preg_match('/\A[A-Z][A-Z0-9_]{0,95}\z/D', $candidate)) $code = $candidate;
        }
        $this->update($request, [
            'state' => $response->getStatusCode() >= 500 || $code === 'IDEMPOTENCY_IN_PROGRESS'
                ? 'inconclusive' : ($response->getStatusCode() < 400 ? 'completed' : 'rejected'),
            'http_status' => $response->getStatusCode(), 'error_code' => $code, 'completed_at' => time(),
        ]);
        $response->headers->set('X-Core-Execution-Id', $request->attributes->get('native_internal_execution'));
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    private function update(Request $request, array $changes): void
    {
        $execution = $request->attributes->get('native_internal_execution');
        if (!is_string($execution) || !Str::isUuid($execution)) {
            throw new \RuntimeException('INTERNAL_AUDIT_UNAVAILABLE');
        }
        $query = DB::table('tx_internal_audit')->where('execution_id', $execution);
        if ($query->update($changes) === 0 && !(clone $query)->useWritePdo()->exists()) {
            throw new \RuntimeException('INTERNAL_AUDIT_UNAVAILABLE');
        }
    }
}
