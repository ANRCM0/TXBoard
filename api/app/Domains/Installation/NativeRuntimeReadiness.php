<?php

namespace App\Domains\Installation;

use App\Domains\CredentialProvider\ProviderCodec;
use App\Domains\CredentialProvider\ProviderCapabilityCodec;
use App\Domains\CredentialProvider\ProviderError;
use App\Domains\CredentialProvider\ProviderTransport;
use App\Domains\Network\NativeNodeAuthorization;
use App\Models\Server;
use App\Domains\Subscription\Services\NativeSubscriptionDelivery;
use App\Domains\Subscription\Services\PlanPublicationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NativeRuntimeReadiness
{
    public function configuration(string $installationId): array
    {
        $providerId = config('native_provider_node.provider_id');
        $this->require(is_string($providerId), 'PROVIDER_REQUIRED');
        $provider = app(ProviderTransport::class)->configuration($providerId);
        $trust = app(NativeNodeAuthorization::class)->publicTrust();
        $this->require($trust['policy_version'] === NativeNodeAuthorization::PROVIDER_USAGE_POLICY_VERSION
            && $trust['core_instance_id'] === $installationId, 'SIGNER_INVALID');
        $this->require(config('core_usage_v2.core_instance_id') === $installationId
            && (string) config('core_usage_v2.receipt_generation') === '1', 'USAGE_IDENTITY_INVALID');
        $this->require(extension_loaded('bcmath') && extension_loaded('curl') && extension_loaded('sodium'), 'RUNTIME_EXTENSION_MISSING');
        $usage = ['core_instance_id' => $installationId, 'receipt_generation' => '1'];
        foreach (['retention_seconds', 'max_late_report_seconds', 'receipt_retention_seconds'] as $field) {
            $value = config('core_usage_v2.'.$field);
            $this->require(is_int($value) && $value > 0 && $value <= 9007199254740991, 'USAGE_RETENTION_INVALID');
            $usage[$field] = $value;
        }
        $this->require($usage['max_late_report_seconds'] <= $usage['retention_seconds']
            && $usage['retention_seconds'] <= $usage['receipt_retention_seconds'], 'USAGE_RETENTION_INVALID');
        $recipients = config('native_provider_node.recipients');
        $this->require(is_array($recipients) && count($recipients) > 0 && count($recipients) <= 128, 'RECIPIENTS_INVALID');
        foreach ($recipients as $nodeId => $recipient) {
            $this->require(preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $nodeId) === 1
                && is_array($recipient) && count($recipient) === 2 && array_key_exists('machine_id', $recipient)
                && ($recipient['machine_id'] === null || (is_int($recipient['machine_id']) && $recipient['machine_id'] > 0))
                && is_string($recipient['recipient_sha256'] ?? null)
                && preg_match('/\A[0-9a-f]{64}\z/D', $recipient['recipient_sha256']) === 1, 'RECIPIENTS_INVALID');
        }
        return ['runtime_version' => NativeRuntimeAdmission::VERSION, 'installation_id' => $installationId,
            'admission_digest' => NativeRuntimeAdmission::digest(), 'provider' => $provider,
            'subscription_render_endpoint' => NativeSubscriptionDelivery::endpoint($providerId),
            'signer' => $trust, 'recipients' => $recipients, 'usage' => $usage];
    }

    public function preflightLocked(string $installationId): array
    {
        $this->require(DB::transactionLevel() > 0, 'TRANSACTION_REQUIRED');
        foreach ([
            'tx_native_runtime_activation' => ['installation_id', 'business_key', 'runtime_version', 'request_hash',
                'configuration_snapshot', 'configuration_hash', 'authority_epoch', 'activated_at'],
            'tx_native_usage_grant' => ['grant_id', 'installation_id', 'binding_snapshot', 'binding_hash', 'authorization_digest'],
            'tx_native_usage_reservation' => ['batch_id', 'usage_period_id', 'reserved_bytes', 'created_at'],
            'tx_native_runtime_outbox' => ['event_key', 'kind', 'user_id', 'subscription_id', 'subscription_version', 'status', 'attempts'],
            'tx_usage_batch' => ['source_id', 'node_id', 'source_epoch', 'batch_id', 'canonical_payload',
                'payload_digest', 'frozen_entries', 'frozen_hash', 'status', 'fencing_token', 'processing_lease_until'],
            'tx_usage_outbox' => ['batch_id', 'event_key', 'kind', 'status', 'body'],
            'tx_node_usage_source' => ['node_id', 'source_epoch', 'installation_ref', 'core_instance_id', 'receipt_generation', 'current'],
            'tx_auth_snapshot' => ['source_id', 'user_id', 'access_subject_id', 'subscription_id', 'terms_id', 'usage_period_id',
                'tariff_revision_id', 'auth_version', 'revocation_version', 'issued_at', 'lease_expires_at', 'revoked_at', 'content_hash'],
            'tx_node_authorization_state' => ['node_id', 'machine_id', 'key_id', 'key_epoch', 'revision', 'snapshot_digest'],
        ] as $table => $columns) {
            $this->require(Schema::hasTable($table) && Schema::hasColumns($table, $columns), 'SCHEMA_REQUIRED');
        }
        $this->require(Schema::hasColumn('tx_access_subject', 'current_credential_operation_ref'), 'SCHEMA_REQUIRED');
        $this->require(Schema::hasIndex('tx_auth_snapshot', 'tx_native_auth_sub_idx')
            && Schema::hasIndex('tx_native_runtime_activation', ['installation_id'], 'unique')
            && Schema::hasIndex('tx_usage_batch', ['node_id', 'source_epoch', 'batch_id'], 'unique'), 'SCHEMA_REQUIRED');
        foreach ([
            ['tx_native_usage_grant', ['grant_id'], 'tx_auth_snapshot', ['id']],
            ['tx_native_usage_reservation', ['batch_id'], 'tx_usage_batch', ['id']],
            ['tx_native_usage_reservation', ['usage_period_id'], 'tx_usage_period', ['id']],
            ['tx_native_runtime_outbox', ['subscription_id', 'user_id'], 'tx_subscription', ['id', 'user_id']],
            ['tx_access_subject', ['current_credential_operation_ref', 'id', 'subscription_id', 'provider_registration_id'],
                'tx_credential_provider_operation', ['operation_ref', 'access_subject_id', 'subscription_id', 'provider_registration_id']],
            ['tx_auth_snapshot', ['usage_period_id', 'subscription_id'], 'tx_usage_period', ['id', 'subscription_id']],
            ['tx_auth_snapshot', ['access_subject_id', 'subscription_id'], 'tx_access_subject', ['id', 'subscription_id']],
            ['tx_auth_snapshot', ['terms_id', 'subscription_id'], 'tx_subscription_terms', ['id', 'subscription_id']],
        ] as [$table, $columns, $foreign, $references]) {
            $found = false;
            foreach (Schema::getForeignKeys($table) as $key) {
                if ($key['columns'] === $columns && $key['foreign_table'] === $foreign && $key['foreign_columns'] === $references
                    && in_array($key['on_delete'], ['restrict', 'no action'], true)) { $found = true; }
            }
            $this->require($found, 'SCHEMA_OWNERSHIP_REQUIRED');
        }
        $configuration = $this->configuration($installationId);
        foreach (['tx_subscription', 'tx_access_subject', 'tx_order', 'tx_auth_snapshot', 'tx_node_usage_source',
            'tx_usage_batch', 'tx_native_usage_grant', 'tx_native_usage_reservation', 'tx_credential_provider_operation'] as $table) {
            $this->require(!DB::table($table)->sharedLock()->exists(), 'FRESH_NATIVE_REQUIRED');
        }
        $registration = DB::table('tx_credential_provider_registration')
            ->where('provider_id', $configuration['provider']['provider_id'])->sharedLock()->first();
        $this->require($registration !== null && $registration->installation_id === $installationId
            && (string) $registration->provider_instance_epoch === $configuration['provider']['provider_instance_epoch']
            && $registration->config_digest === $configuration['provider']['config_digest'], 'PROVIDER_REGISTRATION_INVALID');
        $registered = ['installation_id' => $registration->installation_id, 'provider_id' => $registration->provider_id,
            'provider_instance_epoch' => (string) $registration->provider_instance_epoch, 'config_digest' => $registration->config_digest,
            'profiles' => json_decode($registration->profiles, true, 16, JSON_THROW_ON_ERROR), 'business_key' => $registration->business_key];
        $this->require(hash_equals($registration->content_hash, self::hash($registered)), 'PROVIDER_REGISTRATION_INVALID');
        $this->require($registered['profiles'] === $configuration['provider']['profiles'], 'PROVIDER_REGISTRATION_INVALID');
        $offers = DB::table('tx_plan_offer_availability')->where('status', 'published')->orderBy('offer_id')->limit(1025)->sharedLock()->get();
        $this->require($offers->isNotEmpty() && $offers->count() <= 1024, 'CATALOG_REQUIRED');
        $catalog = [];
        foreach ($offers as $offer) {
            $verified = app(PlanPublicationService::class)->verifiedOffer((int) $offer->offer_id, true, true);
            $catalog[] = ['offer_id' => (string) $offer->offer_id,
                'revision_hash' => $verified['revision']->content_hash, 'offer_hash' => $verified['offer']->content_hash,
                'availability' => (array) $verified['availability']];
        }
        $nodes = Server::query()->where('enabled', true)->orderBy('id')->limit(129)->sharedLock()->get();
        $this->require($nodes->isNotEmpty() && $nodes->count() <= 128
            && $nodes->count() === count($configuration['recipients']), 'NODE_CONFIGURATION_INVALID');
        $targets = [];
        foreach ($nodes as $node) {
            self::assertSupportedNode($node);
            $recipient = $configuration['recipients'][$node->id] ?? null;
            $this->require($recipient !== null && $recipient['machine_id'] === $node->machine_id, 'NODE_RECIPIENT_INVALID');
            $this->require(in_array($node->type.'-uuid/v1', $configuration['provider']['profiles'], true), 'NODE_PROFILE_INVALID');
            if ($node->machine_id !== null) {
                $machine = DB::table('tx_server_machine')->where('id', $node->machine_id)->sharedLock()->first();
                $this->require($machine !== null && (bool) $machine->is_active && is_string($machine->token)
                    && strlen($machine->token) >= 16, 'NODE_MACHINE_INVALID');
                $token = $machine->token;
            } else {
                $token = \App\Models\Setting::query()->where('name', 'server_token')->sharedLock()->first()?->value;
                $this->require(is_string($token) && strlen($token) >= 16, 'NODE_CREDENTIAL_INVALID');
            }
            foreach ($node->group_ids as $groupId) {
                $this->require(DB::table('tx_server_group')->where('id', $groupId)->sharedLock()->exists(), 'NODE_GROUP_INVALID');
            }
            $targets[] = ['connection' => NativeSubscriptionDelivery::connection($node),
                'groups' => $node->group_ids, 'rate' => (string) $node->getRawOriginal('rate'),
                'credential_sha256' => hash('sha256', $token)];
        }
        return ['configuration' => $configuration, 'provider_registration' => $registered,
            'catalog' => $catalog, 'nodes' => $targets];
    }

    public function probe(array $preflight): array
    {
        $this->require(DB::transactionLevel() === 0, 'PROBE_TRANSACTION_FORBIDDEN');
        $registration = $preflight['provider_registration'];
        try {
            $capabilities = app(ProviderTransport::class)->capabilities($registration);
        } catch (ProviderError) {
            throw new InstallationAuthorityException('INSTALLATION_AUTHORITY_PROVIDER_CAPABILITY_UNAVAILABLE');
        }
        $this->require(($capabilities['contract_version'] ?? null) === ProviderCapabilityCodec::VERSION
            && ($capabilities['provider_id'] ?? null) === $registration['provider_id']
            && ($capabilities['provider_instance_epoch'] ?? null) === $registration['provider_instance_epoch']
            && ($capabilities['store_version'] ?? null) === 2
            && ($capabilities['operations'] ?? null) === ['issue', 'query', 'revoke', 'rotate']
            && ($capabilities['node_delivery'] ?? null) === true && ($capabilities['subscription_render'] ?? null) === true
            && is_array($capabilities['profiles'] ?? null)
            && array_diff($registration['profiles'], $capabilities['profiles']) === [], 'PROVIDER_CAPABILITY_REQUIRED');
        // Request nonces identify each wire exchange but cannot change the activation configuration digest.
        unset($capabilities['request_id']);
        return $preflight + ['provider_capabilities' => $capabilities];
    }

    public static function assertSupportedNode(Server $node): void
    {
        NativeSubscriptionDelivery::connection($node);
        $settings = $node->protocol_settings;
        if (!in_array($node->type, ['vless', 'vmess'], true) || !$node->enabled
            || !is_array($node->group_ids) || $node->group_ids === []
            || ($settings['network'] ?? 'tcp') !== 'tcp' || (int) ($settings['tls'] ?? 0) !== 0
            || ($settings['flow'] ?? '') !== '' || !empty($settings['encryption']['enabled'])
            || !empty($settings['multiplex']['enabled']) || $node->rate_time_enable
            || preg_match('/\A(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,2})?\z/D', (string) $node->getRawOriginal('rate')) !== 1) {
            throw new InstallationAuthorityException('INSTALLATION_AUTHORITY_NODE_PROFILE_UNSUPPORTED');
        }
    }

    public static function hash(array $value): string
    {
        return hash('sha256', ProviderCodec::canonical($value));
    }

    private function require(bool $valid, string $code): void
    {
        if (!$valid) { throw new InstallationAuthorityException('INSTALLATION_AUTHORITY_'.$code); }
    }
}
