<?php

namespace App\Domains\Network;

use App\Models\Server;
use App\Services\ServerService;
use App\Services\TrafficUsage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * One ingress for native HTTP and WebSocket usage reports.
 * ACK means dispatched to the queue, never committed to the SQL ledger.
 */
final class NativeNodeReport
{
    public function submit(Server $node, array $body): array
    {
        $validator = Validator::make($body, [
            'protocol_version' => ['required', 'integer', 'in:1'],
            'traffic_batch_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9:_-]{8,80}$/D'],
            'traffic' => ['sometimes', 'array', 'max:10000'],
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
        $batch = $params['traffic_batch_id'] ?? null;
        if ($traffic !== [] && !$batch) {
            throw new NodeReportError('BATCH_ID_REQUIRED', 'Traffic reports require a stable batch ID');
        }
        if ($traffic !== [] && count(TrafficUsage::normalize($traffic, $node->getCurrentRate())) !== count($traffic)) {
            throw new NodeReportError('INVALID_TRAFFIC', 'Traffic entries must be unsigned byte pairs');
        }

        // No side effects until the complete input has passed validation.
        ServerService::touchNode($node);
        if ($traffic !== []) {
            ServerService::processTraffic($node, $traffic, $batch);
        }
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
        ];
    }
}

