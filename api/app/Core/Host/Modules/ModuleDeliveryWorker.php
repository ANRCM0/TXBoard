<?php

namespace App\Core\Host\Modules;

use App\Core\Host\Execution\InvocationScope;
use App\Core\Host\Contracts\TransportSchema;
use App\Domains\Installation\Contracts\InstallationAdmission;
use App\Extensions\Contracts\DomainEvent;
use App\Extensions\Contracts\DeliveryResult;
use App\Extensions\Contracts\ExtensionFailure;
use App\Extensions\Contracts\NotificationChannel;
use App\Extensions\Contracts\VersionedModule;
use App\Models\Plugin;
use App\Services\Plugin\PluginManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class ModuleDeliveryWorker
{
    public function __construct(private readonly ExtensionRuntime $runtime, private readonly PluginManager $plugins,
        private readonly InstallationAdmission $installation, private readonly InvocationScope $scope) {}

    public function run(int $limit): array
    {
        if ($limit < 1 || $limit > 1000 || DB::transactionLevel() !== 0) throw new ExtensionFailure('MODULE_WORK_LIMIT_INVALID');
        $counts = ['accepted' => 0, 'refused' => 0, 'unknown' => 0, 'paused' => 0];
        $ids = DB::table('tx_module_delivery')->useWritePdo()->whereIn('status', ['pending', 'unknown', 'sending'])
            ->where('next_attempt_at', '<=', time())->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', time()))
            ->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $claim = null;
            try {
                $claim = $this->claim((int) $id);
                if ($claim === null) { ++$counts['paused']; continue; }
                $result = $this->scope->run('worker', function () use ($claim): DeliveryResult {
                    $this->scope->actor(new \App\Core\Kernel\Execution\ActorRef('system', 'module-delivery'));
                    $this->scope->correlate($claim['event']->correlation_id, time() + 60);
                    $this->scope->installation($claim['installation']);
                    // Revalidate epoch immediately before dispatch. The durable lease pins the original operation.
                    $now = $this->installation->assertNativeRuntime();
                    if ($now['installation_id'] !== $claim['installation']['installation_id']
                        || (string) $now['authority_epoch'] !== (string) $claim['installation']['authority_epoch']) throw new ExtensionFailure('MODULE_EPOCH_CHANGED');
                    $this->runtime->admission($claim['delivery']->module_code);
                    $plugin = $this->plugins->moduleImplementation($claim['delivery']->module_code);
                    if (!$plugin instanceof NotificationChannel || !$plugin instanceof VersionedModule
                        || $plugin->packageVersion() !== $claim['delivery']->package_version) throw new ExtensionFailure('MODULE_LOADED_VERSION_MISMATCH');
                    $plugin->setConfig($claim['configuration']);
                    $event = $claim['event'];
                    return $plugin->deliver(new DomainEvent($event->event_key, $event->installation_id, $event->kind,
                        $event->business_ref, json_decode($event->body, true, 32, JSON_THROW_ON_ERROR), (int) $event->occurred_at, $event->correlation_id));
                });
            } catch (\Throwable) {
                $result = new DeliveryResult('unknown', 'MODULE_DELIVERY_UNAVAILABLE');
                if ($claim === null) {
                    DB::table('tx_module_delivery')->where('id', $id)->whereIn('status', ['pending', 'unknown', 'sending'])
                        ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', time()))
                        ->update(['status' => 'unknown', 'error_code' => 'MODULE_DELIVERY_UNAVAILABLE', 'next_attempt_at' => time() + 60]);
                    ++$counts['unknown']; continue;
                }
            }
            $delivery = $claim['delivery'];
            $terminal = in_array($result->status, ['accepted', 'refused'], true);
            DB::table('tx_module_delivery')->where('id', $delivery->id)->where('lease_token', $delivery->lease_token)->update([
                'status' => $result->status, 'error_code' => $result->code, 'lease_token' => null, 'lease_until' => null,
                'next_attempt_at' => time() + min(3600, 5 * (2 ** min(9, (int) $delivery->attempts))),
                'completed_at' => $terminal ? time() : null]);
            ++$counts[$result->status];
        }
        return $counts;
    }

    private function claim(int $id): ?array
    {
        $locator = DB::table('tx_module_delivery')->useWritePdo()->find($id);
        if ($locator === null) return null;
        $package = $this->runtime->package($locator->module_code);
        return DB::transaction(function () use ($id, $locator, $package): ?array {
            $installation = $this->installation->assertNativeMutation();
            $plugin = Plugin::query()->where('code', $locator->module_code)->sharedLock()->first();
            if ($plugin === null || !$plugin->is_enabled || $plugin->native_payment_mutation_token !== null
                || $plugin->version !== $package['version'] || (int) $plugin->module_schema_version !== $package['manifest']['schema_version']) return null;
            $delivery = DB::table('tx_module_delivery')->where('id', $id)->lockForUpdate()->first();
            if ($delivery === null || !in_array($delivery->status, ['pending', 'unknown', 'sending'], true)
                || (int) $delivery->next_attempt_at > time() || (int) ($delivery->lease_until ?? 0) > time()) return null;
            if ($delivery->package_version !== $package['version'] || $delivery->package_hash !== $package['fingerprint']) throw new ExtensionFailure('MODULE_DELIVERY_PACKAGE_CHANGED');
            $event = DB::table('tx_module_event')->where('event_key', $delivery->event_key)->first();
            if ($event === null || $event->installation_id !== $installation['installation_id']) throw new ExtensionFailure('MODULE_EVENT_BINDING_INVALID');
            $payload = ['event_key' => $event->event_key, 'installation_id' => $event->installation_id, 'kind' => $event->kind,
                'business_ref' => $event->business_ref, 'body' => $event->body, 'occurred_at' => (int) $event->occurred_at];
            if (!hash_equals(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), $event->content_hash)) throw new ExtensionFailure('MODULE_EVENT_BINDING_INVALID');
            $snapshot = json_decode(Crypt::decryptString($delivery->configuration_ciphertext), true, 32, JSON_THROW_ON_ERROR);
            foreach (['event_key', 'module_code', 'package_hash', 'configuration_version'] as $field) {
                if (($snapshot[$field] ?? null) !== (string) $delivery->$field) throw new ExtensionFailure('MODULE_DELIVERY_BINDING_INVALID');
            }
            if (!is_array($snapshot['configuration'] ?? null)) throw new ExtensionFailure('MODULE_CONFIGURATION_INVALID');
            if (!$snapshot['resolved']) {
                if (!(new TransportSchema)->accepts($snapshot['configuration'], $package['manifest']['config_schema'])) throw new ExtensionFailure('MODULE_CONFIGURATION_INVALID');
                $snapshot['configuration'] = (new ModuleSecrets)->resolve($snapshot['configuration'], $package['manifest']['secrets'] ?? []);
                $snapshot['resolved'] = true;
            }
            $delivery->lease_token = bin2hex(random_bytes(32));
            $delivery->attempts = min(2147483646, (int) $delivery->attempts) + 1;
            DB::table('tx_module_delivery')->where('id', $id)->update(['status' => 'sending', 'attempts' => $delivery->attempts,
                'lease_token' => $delivery->lease_token, 'lease_until' => time() + 120,
                'configuration_ciphertext' => Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR))]);
            return ['delivery' => $delivery, 'event' => $event, 'installation' => $installation, 'configuration' => $snapshot['configuration']];
        }, 3);
    }
}
