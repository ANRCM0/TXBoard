<?php

namespace App\Core\Host\Modules;

use App\Domains\Notifications\Internal\EventJournal;
use App\Extensions\Contracts\DomainEvent;
use App\Extensions\Contracts\ExtensionFailure;
use App\Models\Plugin;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class ModuleEventJournal implements EventJournal
{
    public function __construct(private readonly ModulePackageLock $packages, private readonly ModuleRuntime $runtime) {}

    public function appendLocked(DomainEvent $event): void
    {
        if (DB::transactionLevel() < 1) throw new ExtensionFailure('MODULE_EVENT_TRANSACTION_REQUIRED');
        $body = json_encode($event->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($body) > 32768 || preg_match('/\A[0-9a-f]{64}\z/D', $event->eventId) !== 1
            || preg_match('/\A[a-z][a-z0-9.-]{0,88}\.v[1-9][0-9]*\z/D', $event->kind) !== 1
            || strlen($event->businessRef) > 128) throw new ExtensionFailure('MODULE_EVENT_INVALID');
        $payload = ['event_key' => $event->eventId, 'installation_id' => $event->installationId,
            'kind' => $event->kind, 'business_ref' => $event->businessRef, 'body' => $body, 'occurred_at' => $event->occurredAt];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $inserted = DB::table('tx_module_event')->insertOrIgnore($payload + ['correlation_id' => $event->correlationId, 'content_hash' => $hash]);
        $existing = DB::table('tx_module_event')->where('event_key', $event->eventId)->lockForUpdate()->first();
        if ($existing === null || !hash_equals($hash, $existing->content_hash)) throw new ExtensionFailure('MODULE_EVENT_CONFLICT');
        if (!$inserted) return;
        $active = array_column($this->runtime->plan()->modules, 'id');
        // Financial locks precede plugin snapshot locks. Lifecycle takes the same plugin lock before reference checks.
        foreach ($this->packages->packages() as $code => $package) {
            if (!in_array('plugin.'.$code, $active, true) || !in_array($event->kind, $package['manifest']['subscriptions'], true)) continue;
            $row = Plugin::query()->where('code', $code)->sharedLock()->first();
            if ($row === null || !$row->is_enabled || $row->native_payment_mutation_token !== null || $row->version !== $package['version']
                || (int) $row->module_schema_version !== $package['manifest']['schema_version']) continue;
            $configuration = json_decode($row->config ?: '{}', true);
            $resolved = false;
            try {
                if (!is_array($configuration) || !(new \App\Core\Host\Contracts\TransportSchema)->accepts($configuration, $package['manifest']['config_schema'])) {
                    throw new ExtensionFailure('MODULE_CONFIGURATION_INVALID');
                }
                $configuration = (new ModuleSecrets)->resolve($configuration, $package['manifest']['secrets'] ?? []);
                $resolved = true;
            } catch (\Throwable) {
                // A missing notification secret cannot undo verified money. Retry is still tied to the frozen reference.
            }
            $snapshot = ['event_key' => $event->eventId, 'module_code' => $code, 'package_hash' => $package['fingerprint'],
                'configuration_version' => (string) $row->module_config_version,
                'configuration' => is_array($configuration) ? $configuration : null, 'resolved' => $resolved];
            DB::table('tx_module_delivery')->insert(['event_key' => $event->eventId, 'module_code' => $code,
                'package_version' => $package['version'], 'package_hash' => $package['fingerprint'],
                'configuration_ciphertext' => Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR)),
                'configuration_version' => (int) $row->module_config_version, 'status' => 'pending', 'attempts' => 0,
                'next_attempt_at' => 0, 'created_at' => time()]);
        }
    }
}
