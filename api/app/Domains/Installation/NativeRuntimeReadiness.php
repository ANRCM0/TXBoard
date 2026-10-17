<?php

namespace App\Domains\Installation;

use App\Domains\Billing\OrderIdempotency;
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
            'tx_money_account' => ['id', 'installation_id', 'owner_id', 'user_id', 'purpose', 'currency', 'normal_side', 'balance_minor', 'created_at'],
            'tx_money_journal' => ['id', 'installation_id', 'user_id', 'business_type', 'business_ref',
                'idempotency_key', 'request_hash', 'currency', 'occurred_at', 'reverses_journal_id', 'created_at'],
            'tx_money_posting' => ['id', 'journal_id', 'account_id', 'position', 'side', 'amount_minor'],
            'tx_payment_attempt' => ['id', 'installation_id', 'user_id', 'intent_type', 'recharge_id', 'order_id', 'payment_id',
                'purchase_terms_id', 'provider', 'provider_account_ref', 'order_trade_no', 'amount_minor', 'currency',
                'purchase_content_hash', 'request_key', 'request_hash', 'key_hash', 'attempt_hash', 'status', 'created_at', 'updated_at'],
            'tx_payment_receipt' => ['id', 'installation_id', 'user_id', 'intent_type', 'recharge_id', 'order_id', 'payment_id', 'attempt_id',
                'provider', 'provider_account_ref', 'transaction_ref', 'identity_hash', 'amount_minor',
                'currency', 'occurred_at', 'content_hash', 'created_at'],
            'tx_payment_dispatch' => ['id', 'attempt_id', 'installation_id', 'user_id', 'intent_type', 'recharge_id',
                'order_id', 'payment_id', 'plugin_code', 'plugin_version', 'contract_version', 'provider',
                'provider_order_ref', 'callback_uuid', 'snapshot_ciphertext', 'content_hash',
                'created_at', 'last_queried_at'],
            'tx_plugins' => ['id', 'code', 'version', 'is_enabled', 'native_payment_mutation_token'],
            'tx_plugin_package_fence' => ['plugin_code', 'mutation_token'],
            'idempotency_keys' => ['id', 'user_id', 'operation', 'key_hash', 'request_hash', 'status',
                'trade_no', 'result_snapshot', 'expires_at', 'created_at', 'updated_at'],
            'tx_payment_settlement' => ['id', 'installation_id', 'user_id', 'order_id', 'payment_id', 'attempt_id',
                'receipt_id', 'journal_id', 'decision', 'amount_minor', 'currency', 'occurred_at', 'content_hash', 'created_at'],
            'tx_order_payment' => ['id', 'installation_id', 'user_id', 'order_id', 'settlement_id', 'receipt_id',
                'wallet_reservation_id', 'amount_minor', 'currency', 'content_hash', 'created_at'],
            'tx_billing_work' => ['id', 'event_key', 'kind', 'installation_id', 'user_id', 'order_id', 'receipt_id',
                'settlement_id', 'wallet_reservation_id', 'content_hash', 'status', 'attempts', 'error_code', 'created_at', 'completed_at'],
            'tx_native_recharge' => ['id', 'installation_id', 'user_id', 'payment_id', 'trade_no', 'request_key',
                'key_hash', 'amount_minor', 'currency', 'content_hash', 'status', 'receipt_id', 'journal_id', 'created_at', 'paid_at'],
            'tx_wallet_order_reservation' => ['id', 'installation_id', 'user_id', 'order_id', 'purchase_terms_id',
                'purchase_content_hash', 'request_key', 'key_hash', 'amount_minor', 'currency', 'content_hash', 'status',
                'reserve_journal_id', 'capture_journal_id', 'release_journal_id', 'created_at', 'captured_at', 'released_at'],
            'tx_native_refund' => ['id', 'installation_id', 'user_id', 'actor_id', 'source_type', 'source_id',
                'receipt_id', 'order_payment_id', 'order_id', 'source_journal_id', 'journal_id', 'request_key',
                'key_hash', 'request_hash', 'amount_minor', 'cumulative_minor', 'currency', 'destination', 'reason',
                'entitlement_effect', 'subscription_id', 'content_hash', 'created_at'],
            'tx_internal_service' => ['service_id', 'security_domain', 'token_hash', 'certificate_sha256', 'epoch',
                'operations_json', 'expires_at', 'revoked_at', 'created_at', 'updated_at'],
            'tx_internal_actor_grant' => ['user_id', 'permission', 'created_at'],
            'tx_internal_request' => ['key_hash', 'input_hash', 'operation', 'actor_id', 'state', 'response_status',
                'response_json', 'created_at', 'updated_at'],
            'tx_internal_audit' => ['id', 'execution_id', 'request_id', 'service_id', 'service_epoch', 'actor_id',
                'operation', 'security_domain', 'state', 'http_status', 'error_code', 'created_at', 'completed_at'],
            'tx_native_runtime_trust_transition' => ['id', 'installation_id', 'version', 'business_key', 'request_hash',
                'previous_digest', 'configuration_digest', 'configuration_snapshot', 'provider_capabilities', 'content_hash', 'created_at'],
            'tx_subscription_link' => ['id', 'installation_id', 'user_id', 'subscription_id', 'access_subject_id',
                'link_ref', 'bearer_hash', 'business_key', 'request_hash', 'status', 'row_version', 'expires_at',
                'revoked_at', 'content_hash', 'created_at'],
            'tx_native_plan_request' => ['id', 'installation_id', 'actor_id', 'plan_id', 'business_key',
                'request_hash', 'result_snapshot', 'result_hash', 'created_at'],
            'tx_native_free_checkout' => ['id', 'installation_id', 'user_id', 'order_id', 'request_key',
                'key_hash', 'request_hash', 'purchase_hash', 'currency', 'result_json', 'content_hash',
                'status', 'created_at', 'completed_at'],
            'tx_order_upgrade_credit' => ['id', 'installation_id', 'user_id', 'order_id', 'subscription_id',
                'terms_id', 'period_id', 'offer_id', 'currency', 'list_minor', 'credit_minor', 'net_minor',
                'quoted_at', 'expires_at', 'quote_json', 'content_hash'],
            'tx_upgrade_credit_source' => ['id', 'quote_id', 'order_payment_id', 'amount_minor'],
        ] as $table => $columns) {
            $this->require(Schema::hasTable($table) && Schema::hasColumns($table, $columns), 'SCHEMA_REQUIRED');
        }
        $this->require(Schema::hasColumn('tx_access_subject', 'current_credential_operation_ref'), 'SCHEMA_REQUIRED');
        $this->require(Schema::hasIndex('tx_auth_snapshot', 'tx_native_auth_sub_idx')
            && Schema::hasIndex('tx_usage_entry', 'tx_usage_entry_period_batch_idx')
            && Schema::hasIndex('tx_native_runtime_activation', ['installation_id'], 'unique')
            && Schema::hasIndex('tx_usage_batch', ['node_id', 'source_epoch', 'batch_id'], 'unique')
            && Schema::hasIndex('tx_internal_service', ['service_id'], 'primary')
            && Schema::hasIndex('tx_internal_actor_grant', ['user_id', 'permission'], 'primary')
            && Schema::hasIndex('tx_internal_request', ['key_hash'], 'primary')
            && Schema::hasIndex('tx_plugin_package_fence', ['plugin_code'], 'primary'), 'SCHEMA_REQUIRED');
        foreach ([
            ['idempotency_keys', ['user_id', 'operation', 'key_hash']],
            ['tx_money_account', ['installation_id', 'owner_id', 'purpose', 'currency']],
            ['tx_money_journal', ['installation_id', 'user_id', 'business_type', 'business_ref', 'idempotency_key']],
            ['tx_money_journal', ['reverses_journal_id']],
            ['tx_money_posting', ['journal_id', 'position']],
            ['tx_payment_attempt', ['installation_id', 'user_id', 'key_hash']],
            ['tx_payment_attempt', ['id', 'installation_id', 'user_id', 'order_id', 'payment_id']],
            ['tx_payment_attempt', ['id', 'installation_id', 'user_id', 'payment_id']],
            ['tx_order', ['id', 'user_id']],
            ['tx_order_purchase_terms', ['id', 'order_id', 'user_id']],
            ['tx_payment_receipt', ['installation_id', 'identity_hash']],
            ['tx_payment_receipt', ['attempt_id']],
            ['tx_payment_receipt', ['id', 'installation_id', 'user_id', 'order_id', 'payment_id', 'attempt_id']],
            ['tx_money_journal', ['id', 'installation_id', 'user_id']],
            ['tx_payment_dispatch', ['attempt_id']],
            ['tx_payment_dispatch', ['installation_id', 'provider_order_ref']],
            ['tx_payment_settlement', ['receipt_id']],
            ['tx_payment_settlement', ['attempt_id']],
            ['tx_payment_settlement', ['journal_id']],
            ['tx_payment_settlement', ['id', 'installation_id', 'user_id', 'order_id', 'receipt_id']],
            ['tx_order_payment', ['order_id']],
            ['tx_order_payment', ['settlement_id']],
            ['tx_order_payment', ['receipt_id']],
            ['tx_order_payment', ['wallet_reservation_id']],
            ['tx_billing_work', ['event_key']],
            ['tx_billing_work', ['settlement_id']],
            ['tx_billing_work', ['wallet_reservation_id']],
            ['tx_native_recharge', ['trade_no']],
            ['tx_native_recharge', ['installation_id', 'user_id', 'key_hash']],
            ['tx_native_recharge', ['id', 'installation_id', 'user_id']],
            ['tx_wallet_order_reservation', ['order_id']],
            ['tx_wallet_order_reservation', ['installation_id', 'user_id', 'key_hash']],
            ['tx_wallet_order_reservation', ['id', 'installation_id', 'user_id', 'order_id']],
            ['tx_native_refund', ['installation_id', 'user_id', 'key_hash']],
            ['tx_native_refund', ['journal_id']],
            ['tx_internal_audit', ['execution_id']],
            ['tx_native_runtime_trust_transition', ['installation_id', 'version']],
            ['tx_native_runtime_trust_transition', ['installation_id', 'business_key']],
            ['tx_native_runtime_trust_transition', ['installation_id', 'configuration_digest']],
            ['tx_subscription_link', ['link_ref']],
            ['tx_subscription_link', ['installation_id', 'user_id', 'business_key']],
            ['tx_native_plan_request', ['installation_id', 'actor_id', 'business_key']],
            ['tx_native_free_checkout', ['installation_id', 'user_id', 'key_hash']],
            ['tx_native_free_checkout', ['order_id']],
            ['tx_order_upgrade_credit', ['order_id']],
            ['tx_upgrade_credit_source', ['quote_id', 'order_payment_id']],
        ] as [$table, $columns]) {
            $this->require(Schema::hasIndex($table, $columns, 'unique'), 'SCHEMA_REQUIRED');
        }
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
            ['tx_money_account', ['user_id'], 'tx_user', ['id']],
            ['tx_money_journal', ['user_id'], 'tx_user', ['id']],
            ['tx_money_journal', ['reverses_journal_id'], 'tx_money_journal', ['id']],
            ['tx_money_posting', ['journal_id'], 'tx_money_journal', ['id']],
            ['tx_money_posting', ['account_id'], 'tx_money_account', ['id']],
            ['tx_payment_attempt', ['user_id'], 'tx_user', ['id']],
            ['tx_payment_attempt', ['payment_id'], 'tx_payment', ['id']],
            ['tx_payment_attempt', ['order_id', 'user_id'], 'tx_order', ['id', 'user_id']],
            ['tx_payment_attempt', ['purchase_terms_id', 'order_id', 'user_id'], 'tx_order_purchase_terms', ['id', 'order_id', 'user_id']],
            ['tx_payment_receipt', ['user_id'], 'tx_user', ['id']],
            ['tx_payment_receipt', ['payment_id'], 'tx_payment', ['id']],
            ['tx_payment_receipt', ['attempt_id', 'installation_id', 'user_id', 'order_id', 'payment_id'],
                'tx_payment_attempt', ['id', 'installation_id', 'user_id', 'order_id', 'payment_id']],
            ['tx_payment_receipt', ['attempt_id', 'installation_id', 'user_id', 'payment_id'],
                'tx_payment_attempt', ['id', 'installation_id', 'user_id', 'payment_id']],
            ['tx_payment_dispatch', ['attempt_id', 'installation_id', 'user_id', 'order_id', 'payment_id'],
                'tx_payment_attempt', ['id', 'installation_id', 'user_id', 'order_id', 'payment_id']],
            ['tx_payment_dispatch', ['attempt_id', 'installation_id', 'user_id', 'payment_id'],
                'tx_payment_attempt', ['id', 'installation_id', 'user_id', 'payment_id']],
            ['tx_payment_dispatch', ['plugin_code'], 'tx_plugins', ['code']],
            ['tx_payment_attempt', ['recharge_id', 'installation_id', 'user_id'],
                'tx_native_recharge', ['id', 'installation_id', 'user_id']],
            ['tx_payment_receipt', ['recharge_id', 'installation_id', 'user_id'],
                'tx_native_recharge', ['id', 'installation_id', 'user_id']],
            ['tx_payment_dispatch', ['recharge_id', 'installation_id', 'user_id'],
                'tx_native_recharge', ['id', 'installation_id', 'user_id']],
            ['tx_payment_settlement', ['receipt_id', 'installation_id', 'user_id', 'order_id', 'payment_id', 'attempt_id'],
                'tx_payment_receipt', ['id', 'installation_id', 'user_id', 'order_id', 'payment_id', 'attempt_id']],
            ['tx_payment_settlement', ['journal_id', 'installation_id', 'user_id'],
                'tx_money_journal', ['id', 'installation_id', 'user_id']],
            ['tx_order_payment', ['settlement_id', 'installation_id', 'user_id', 'order_id', 'receipt_id'],
                'tx_payment_settlement', ['id', 'installation_id', 'user_id', 'order_id', 'receipt_id']],
            ['tx_order_payment', ['order_id', 'user_id'], 'tx_order', ['id', 'user_id']],
            ['tx_order_payment', ['wallet_reservation_id', 'installation_id', 'user_id', 'order_id'],
                'tx_wallet_order_reservation', ['id', 'installation_id', 'user_id', 'order_id']],
            ['tx_billing_work', ['settlement_id', 'installation_id', 'user_id', 'order_id', 'receipt_id'],
                'tx_payment_settlement', ['id', 'installation_id', 'user_id', 'order_id', 'receipt_id']],
            ['tx_billing_work', ['order_id', 'user_id'], 'tx_order', ['id', 'user_id']],
            ['tx_billing_work', ['wallet_reservation_id', 'installation_id', 'user_id', 'order_id'],
                'tx_wallet_order_reservation', ['id', 'installation_id', 'user_id', 'order_id']],
            ['tx_native_recharge', ['installation_id'], 'tx_authority_writer_fence', ['installation_id']],
            ['tx_native_recharge', ['user_id'], 'tx_user', ['id']],
            ['tx_native_recharge', ['payment_id'], 'tx_payment', ['id']],
            ['tx_native_recharge', ['receipt_id'], 'tx_payment_receipt', ['id']],
            ['tx_native_recharge', ['journal_id'], 'tx_money_journal', ['id']],
            ['tx_wallet_order_reservation', ['installation_id'], 'tx_authority_writer_fence', ['installation_id']],
            ['tx_wallet_order_reservation', ['order_id', 'user_id'], 'tx_order', ['id', 'user_id']],
            ['tx_wallet_order_reservation', ['purchase_terms_id'], 'tx_order_purchase_terms', ['id']],
            ['tx_wallet_order_reservation', ['reserve_journal_id', 'installation_id', 'user_id'], 'tx_money_journal', ['id', 'installation_id', 'user_id']],
            ['tx_wallet_order_reservation', ['capture_journal_id', 'installation_id', 'user_id'], 'tx_money_journal', ['id', 'installation_id', 'user_id']],
            ['tx_wallet_order_reservation', ['release_journal_id', 'installation_id', 'user_id'], 'tx_money_journal', ['id', 'installation_id', 'user_id']],
            ['tx_native_refund', ['installation_id'], 'tx_authority_writer_fence', ['installation_id']],
            ['tx_native_refund', ['user_id'], 'tx_user', ['id']],
            ['tx_native_refund', ['actor_id'], 'tx_user', ['id']],
            ['tx_native_refund', ['receipt_id'], 'tx_payment_receipt', ['id']],
            ['tx_native_refund', ['order_payment_id'], 'tx_order_payment', ['id']],
            ['tx_native_refund', ['source_journal_id', 'installation_id', 'user_id'], 'tx_money_journal', ['id', 'installation_id', 'user_id']],
            ['tx_native_refund', ['journal_id', 'installation_id', 'user_id'], 'tx_money_journal', ['id', 'installation_id', 'user_id']],
            ['tx_native_refund', ['order_id', 'user_id'], 'tx_order', ['id', 'user_id']],
            ['tx_native_refund', ['subscription_id'], 'tx_subscription', ['id']],
            ['tx_internal_actor_grant', ['user_id'], 'tx_user', ['id']],
            ['tx_internal_request', ['actor_id'], 'tx_user', ['id']],
            ['tx_internal_audit', ['actor_id'], 'tx_user', ['id']],
            ['tx_native_runtime_trust_transition', ['installation_id'], 'tx_native_runtime_activation', ['installation_id']],
            ['tx_subscription_link', ['subscription_id', 'user_id'], 'tx_subscription', ['id', 'user_id']],
            ['tx_subscription_link', ['access_subject_id', 'subscription_id'], 'tx_access_subject', ['id', 'subscription_id']],
            ['tx_native_plan_request', ['actor_id'], 'tx_user', ['id']],
            ['tx_native_plan_request', ['plan_id'], 'tx_plan', ['id']],
            ['tx_native_free_checkout', ['order_id'], 'tx_order', ['id']],
            ['tx_native_free_checkout', ['user_id'], 'tx_user', ['id']],
            ['tx_order_upgrade_credit', ['installation_id'], 'tx_authority_writer_fence', ['installation_id']],
            ['tx_order_upgrade_credit', ['order_id', 'user_id'], 'tx_order', ['id', 'user_id']],
            ['tx_order_upgrade_credit', ['subscription_id', 'user_id'], 'tx_subscription', ['id', 'user_id']],
            ['tx_order_upgrade_credit', ['terms_id'], 'tx_subscription_terms', ['id']],
            ['tx_order_upgrade_credit', ['period_id'], 'tx_usage_period', ['id']],
            ['tx_order_upgrade_credit', ['offer_id'], 'tx_plan_offer', ['id']],
            ['tx_upgrade_credit_source', ['quote_id'], 'tx_order_upgrade_credit', ['id']],
            ['tx_upgrade_credit_source', ['order_payment_id'], 'tx_order_payment', ['id']],
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
            'tx_usage_batch', 'tx_native_usage_grant', 'tx_native_usage_reservation', 'tx_credential_provider_operation',
            'tx_money_account', 'tx_money_journal', 'tx_money_posting', 'tx_payment_attempt', 'tx_payment_receipt',
            'tx_payment_dispatch', 'tx_payment_settlement', 'tx_order_payment', 'tx_billing_work', 'tx_native_recharge',
            'tx_wallet_order_reservation', 'tx_native_refund', 'tx_internal_request',
            'tx_native_runtime_trust_transition', 'tx_subscription_link', 'tx_native_plan_request',
            'tx_native_runtime_outbox', 'tx_native_free_checkout', 'tx_order_upgrade_credit', 'tx_upgrade_credit_source'] as $table) {
            $this->require(!DB::table($table)->sharedLock()->exists(), 'FRESH_NATIVE_REQUIRED');
        }
        $this->require(!DB::table('idempotency_keys')->where('operation', OrderIdempotency::OPERATION_NATIVE_CREATE)
            ->sharedLock()->exists(), 'FRESH_NATIVE_REQUIRED');
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
