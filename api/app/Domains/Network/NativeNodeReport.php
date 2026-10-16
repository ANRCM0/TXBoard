<?php

namespace App\Domains\Network;

use App\Models\Server;
use App\Domains\Installation\InstallationAuthority;
use App\Services\ServerService;
use App\Services\TrafficUsage;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * One ingress for native HTTP and WebSocket usage reports.
 * A nonempty ACK follows durable SQL reception, never certifies billing.
 */
final class NativeNodeReport
{
    public function submit(Server $node, array $body): array
    {
        $validator = Validator::make($body, [
            'protocol_version' => ['required', 'integer', 'in:1'],
            'traffic_batch_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9:_-]{8,80}$/D'],
            'traffic' => ['sometimes', 'array', 'max:10000'],
            'traffic_segments' => ['sometimes', 'array', 'max:10000'],
            'alive' => ['sometimes', 'array', 'max:10000'],
            'online' => ['sometimes', 'array', 'max:10000'],
            'status' => ['sometimes', 'array'],
            'metrics' => ['sometimes', 'array'],
        ]);
        if ($validator->fails()) {
            throw new NodeReportError('INVALID_REPORT', 'Invalid report payload');
        }
        $params = $validator->validated();
        $traffic = $params['traffic'] ?? [];
        if ($traffic !== [] && (config('core_installation.mode') === 'native'
            || app(InstallationAuthority::class)->inspect()['mode'] === 'native')) {
            throw new NodeReportError('NATIVE_TRAFFIC_SETTLEMENT_UNAVAILABLE', 'Native subscription traffic settlement is not enabled');
        }
        $batch = $params['traffic_batch_id'] ?? null;
        if ($traffic !== [] && !$batch) {
            throw new NodeReportError('BATCH_ID_REQUIRED', 'Traffic reports require a stable batch ID');
        }
        if ($traffic !== [] && count(TrafficUsage::normalize($traffic, 1)) !== count($traffic)) {
            throw new NodeReportError('INVALID_TRAFFIC', 'Traffic entries must be unsigned byte pairs');
        }

        $segments = array_key_exists('traffic_segments', $params) ? $params['traffic_segments'] : null;
        if ($segments !== null) {
            if ($traffic === []) throw new NodeReportError('INVALID_TRAFFIC_SEGMENTS', 'Segments require nonempty traffic');
            $segments = NativeTrafficSegments::canonical($segments, TrafficUsage::normalize($traffic, 1));
            if (config('native_traffic_receipts.enabled') !== true) throw new NodeReportError('TRAFFIC_DURABILITY_UNAVAILABLE', 'Period traffic requires durable acceptance');
        }

        // Durable acceptance precedes every nonempty report ACK and ancillary
        // status effects. Redis dispatch is only a recoverable wakeup.
        $receipt = null;
        if ($traffic !== []) {
            if (config('native_traffic_receipts.enabled') === true) {
                $receipt = app(NativeTrafficReceipt::class)->accept($node, $batch, $traffic, $segments);
                // Preserve native processTraffic's operational metadata without
                // dispatching again or invoking mutable billing hooks twice.
                $type = strtoupper((string) $node->type);
                Cache::put(CacheKey::get("SERVER_{$type}_ONLINE_USER", (int) $node->id), count($traffic), 3600);
                Cache::put(CacheKey::get("SERVER_{$type}_LAST_PUSH_AT", (int) $node->id), time(), 3600);
            } elseif (config('native_traffic_receipts.enabled') === false) {
                // Explicit compatibility/testing mode advertises queued-only
                // reception. It is never certified as durable acceptance.
                ServerService::processTraffic($node, $traffic, $batch);
            } else {
                throw new NodeReportError('TRAFFIC_DURABILITY_UNAVAILABLE', 'Durable traffic acceptance unavailable');
            }
        }
        ServerService::touchNode($node);
        if (!empty($params['alive'])) {
            ServerService::processAlive((int) $node->id, $params['alive']);
        }
        if (!empty($params['online'])) {
            ServerService::processOnline($node, $params['online']);
        }
        if (!empty($params['status'])) {
            ServerService::processStatus($node, $params['status']);
        }
        if (!empty($params['metrics'])) {
            ServerService::updateMetrics($node, $params['metrics']);
        }
        return [
            'protocol_version' => 1,
            'accepted' => true,
            'traffic_batch_id' => $batch,
            'settlement' => $traffic === [] ? 'none' : 'queued',
            'receipt_status' => $receipt === null ? ($traffic === [] ? 'none' : 'queued_only') : 'persisted',
            'receipt_id' => $receipt === null ? null : (string) $receipt->id,
        ];
    }
}

