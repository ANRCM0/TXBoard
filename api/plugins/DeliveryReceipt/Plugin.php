<?php

namespace Plugin\DeliveryReceipt;

use App\Extensions\Contracts\DeploymentModule;
use App\Extensions\Contracts\ExtensionModule;
use App\Extensions\Contracts\ExtensionRequest;
use App\Extensions\Contracts\ExtensionResponse;
use App\Extensions\Contracts\ExtensionFailure;
use App\Extensions\Contracts\DeliveryResult;
use App\Extensions\Contracts\DomainEvent;
use App\Extensions\Contracts\NotificationChannel;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

final class Plugin extends AbstractPlugin implements DeploymentModule, ExtensionModule, NotificationChannel
{
    public function packageVersion(): string { return '1.0.0'; }

    public function prepareSchema(string $deploymentId, int $fromVersion, int $toVersion): void
    {
        if ($toVersion !== 1 || !in_array($fromVersion, [0, 1], true)) throw new ExtensionFailure('MODULE_SCHEMA_VERSION_CONFLICT');
        if (!Schema::hasTable('tx_ext_delivery_receipt')) Schema::create('tx_ext_delivery_receipt', function (Blueprint $table): void {
            $table->char('event_key', 64)->primary();
            $table->char('body_hash', 64);
            $table->unsignedBigInteger('accepted_at');
        });
        if (!Schema::hasColumns('tx_ext_delivery_receipt', ['event_key', 'body_hash', 'accepted_at'])) throw new ExtensionFailure('MODULE_SCHEMA_VERSION_CONFLICT');
    }

    public function deliver(DomainEvent $event): DeliveryResult
    {
        $body = ['event_id' => $event->eventId, 'kind' => $event->kind, 'business_ref' => $event->businessRef,
            'installation_id' => $event->installationId, 'occurred_at' => (string) $event->occurredAt, 'data' => $event->data];
        $hash = hash('sha256', json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $known = DB::table('tx_ext_delivery_receipt')->useWritePdo()->where('event_key', $event->eventId)->first();
        if ($known !== null) return new DeliveryResult(hash_equals($known->body_hash, $hash) ? 'accepted' : 'refused',
            hash_equals($known->body_hash, $hash) ? 'RECEIPT_REPLAY' : 'RECEIPT_CONFLICT');
        $url = $this->getConfig('endpoint');
        $parts = is_string($url) ? parse_url($url) : false;
        $hosts = array_filter(explode(',', (string) env('DELIVERY_RECEIPT_ALLOWED_HOSTS', '')));
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || !in_array($parts['host'] ?? null, $hosts, true)) return new DeliveryResult('refused', 'RECEIVER_CONFIGURATION_REJECTED');
        try {
            // The receiver persists event_id before acknowledging it. Unknown results retain that ID for retry.
            $response = Http::withToken($this->getConfig('token'))->withHeaders(['Idempotency-Key' => $event->eventId])
                ->connectTimeout(3)->timeout(20)->withOptions(['allow_redirects' => false, 'stream' => true])->post($url, $body);
            $stream = $response->toPsrResponse()->getBody();
            try { $text = $stream->read(8193); }
            finally { $stream->close(); }
            if (strlen($text) > 8192) return new DeliveryResult('unknown', 'RECEIVER_RESPONSE_INVALID');
            $receipt = json_decode($text, true);
            if ($response->status() === 200 && ($receipt['event_id'] ?? null) === $event->eventId && ($receipt['status'] ?? null) === 'accepted') {
                DB::table('tx_ext_delivery_receipt')->insertOrIgnore(['event_key' => $event->eventId, 'body_hash' => $hash, 'accepted_at' => time()]);
                $stored = DB::table('tx_ext_delivery_receipt')->useWritePdo()->where('event_key', $event->eventId)->first();
                return new DeliveryResult($stored !== null && hash_equals($stored->body_hash, $hash) ? 'accepted' : 'unknown', 'RECEIPT_ACCEPTED');
            }
            return new DeliveryResult(in_array($response->status(), [400, 401, 403, 409], true) ? 'refused' : 'unknown', 'RECEIVER_NOT_ACCEPTED');
        } catch (\Throwable) { return new DeliveryResult('unknown', 'RECEIVER_RESULT_UNKNOWN'); }
    }

    public function handle(ExtensionRequest $request): ExtensionResponse
    {
        if ($request->operation !== 'receipts.list' || $request->actorKind !== 'admin') throw new ExtensionFailure('MODULE_OPERATION_NOT_FOUND', 404);
        $items = DB::table('tx_ext_delivery_receipt')->useWritePdo()->orderByDesc('accepted_at')->orderBy('event_key')->limit(50)->get()
            ->map(static fn (object $row): array => ['event_id' => $row->event_key, 'accepted_at' => (string) $row->accepted_at])->all();
        return new ExtensionResponse(['receipts' => $items]);
    }
}
