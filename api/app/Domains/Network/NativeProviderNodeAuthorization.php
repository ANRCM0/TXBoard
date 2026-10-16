<?php

namespace App\Domains\Network;

use App\Domains\CredentialProvider\ProviderCodec;
use App\Domains\CredentialProvider\ProviderMetadataCodec;
use App\Domains\CredentialProvider\ProviderTransport;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\NativeRuntimeReadiness;
use App\Domains\UsageV2\NativeUsageLedger;
use App\Models\Server;
use App\Models\Setting;
use App\Services\Subscription\NativeCredentialProviderService;
use App\Services\Subscription\SubscriptionEntitlementResolver;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Complete non-secret snapshots; UUID material travels only from Provider to Node. */
final class NativeProviderNodeAuthorization
{
    private const MAX_CANDIDATE_SCAN = 1024;

    public function __construct(
        private readonly SubscriptionEntitlementResolver $entitlements,
        private readonly NativeCredentialProviderService $credentials,
        private readonly ProviderTransport $transport,
        private readonly NativeNodeAuthorization $signer,
    ) {}

    public function publicTrust(): array
    {
        $this->require(DB::transactionLevel() === 0, 'OUTER_TRANSACTION_UNSUPPORTED');
        return DB::transaction(function (): array {
            $installation = app(InstallationAuthority::class)->assertNativeRead();
            $providerId = config('native_provider_node.provider_id');
            $this->require(is_string($providerId), 'CONFIG_INVALID');
            $configuration = $this->transport->configuration($providerId);
            $registration = $this->credentials->authorizationRegistrationLocked($providerId, $installation['installation_id']);
            $this->require((string) $registration['provider_instance_epoch'] === $configuration['provider_instance_epoch']
                && $registration['config_digest'] === $configuration['config_digest'], 'REGISTRATION_CHANGED');
            return $this->trust($installation['installation_id']);
        }, 3);
    }

    public function snapshot(Server $node, #[\SensitiveParameter] array $credential): array
    {
        $this->require(DB::transactionLevel() === 0, 'OUTER_TRANSACTION_UNSUPPORTED');
        $this->require(count($credential) === 3 && in_array($credential['kind'] ?? null, ['node', 'machine'], true)
            && array_key_exists('machine_id', $credential)
            && ($credential['kind'] === 'node' ? $credential['machine_id'] === null
                : (is_int($credential['machine_id']) && $credential['machine_id'] > 0))
            && is_string($credential['credential_sha256'] ?? null)
            && preg_match('/\A[0-9a-f]{64}\z/D', $credential['credential_sha256']) === 1, 'CREDENTIAL_INVALID');
        $currentNode = Server::query()->useWritePdo()->whereKey($node->id)->first();
        $this->require($currentNode !== null && $currentNode->enabled && $currentNode->machine_id === $node->machine_id
            && $currentNode->type === $node->type, 'TARGET_CHANGED');
        $node = $currentNode;
        $providerId = config('native_provider_node.provider_id');
        $this->require(is_string($providerId) && preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $providerId) === 1, 'CONFIG_INVALID');
        $profile = $this->profile($node);
        $configuration = $this->transport->configuration($providerId);
        $locators = $this->locators($node, $providerId, $profile, time());
        $before = DB::transaction(fn (): array => $this->projectLocked($node, $locators, $configuration, $credential, time()), 3);
        $request = [
            'contract_version' => ProviderMetadataCodec::VERSION, 'request_id' => 'metadata_'.bin2hex(random_bytes(16)),
            'provider_id' => $providerId, 'provider_instance_epoch' => $before['registration']['provider_instance_epoch'],
            'subjects' => array_column($before['rows'], 'input'),
        ];
        ProviderMetadataCodec::validateRequest($request);
        $metadata = ['credentials' => []];
        if ($request['subjects'] !== []) {
            $metadata = $this->transport->metadata($before['registration'], $request);
            $metadata = ProviderMetadataCodec::decodeResponse(ProviderCodec::canonical($metadata), $request);
        }
        $afterLocators = $this->locators($node, $providerId, $profile, time());
        $identity = static fn (object $row): array => [(string) $row->id, (string) $row->subscription_id, (string) $row->user_id];
        $this->require(array_map($identity, $locators) === array_map($identity, $afterLocators), 'AUTHORITY_CHANGED');

        return DB::transaction(function () use ($node, $locators, $configuration, $credential, $before, $metadata): array {
            $now = time();
            $current = $this->projectLocked($node, $locators, $configuration, $credential, $now, true);
            $this->require($current === $before, 'AUTHORITY_CHANGED');
            $now = time();
            $users = []; $subjects = []; $expiresAt = $now + $this->signer->publicTrust()['lease_seconds'];
            foreach ($current['rows'] as $index => $row) {
                $metadataRow = $metadata['credentials'][$index];
                $this->require($metadataRow['credential_expires_at'] === $row['credential_expires_at'], 'METADATA_INVALID');
                $entitlement = $row['entitlement'];
                foreach ([$row['credential_expires_at'], $entitlement['expires_at'], $entitlement['next_reset_at']] as $boundary) {
                    if ($boundary !== null) { $expiresAt = min($expiresAt, (int) $boundary); }
                }
                $users[] = [
                    'id' => (int) $entitlement['user_id'], 'uuid' => 'sha256:'.$metadataRow['credential_sha256'],
                    'speed_limit' => (int) ($entitlement['speed_limit_mbps'] ?? 0),
                    'device_limit' => (int) ($entitlement['device_limit'] ?? 0),
                ];
                $input = $row['input'];
                unset($input['installation_id'], $input['credential_profile_id']);
                $subjects[] = ['user_id' => (int) $entitlement['user_id']] + $input
                    + ['credential_expires_at' => $row['credential_expires_at']];
            }
            $this->require($expiresAt > $now, 'EXPIRED');
            $provider = [
                'installation_id' => $current['installation_id'], 'provider_id' => $configuration['provider_id'],
                'provider_instance_epoch' => $current['registration']['provider_instance_epoch'],
                'recipient_sha256' => $current['target']['recipient_sha256'],
                'credential_profile_id' => $current['target']['profile'], 'subjects' => $subjects,
            ];
            // projectLocked takes this target lock last, after every account/Subject/receipt.
            $lockedNode = Server::query()->whereKey($node->id)->lockForUpdate()->first();
            $usage = null;
            if (config('native_node_authorization.policy_version') === NativeNodeAuthorization::PROVIDER_USAGE_POLICY_VERSION) {
                $usage = app(NativeUsageLedger::class)->issueLocked($lockedNode, $current['rows'], $now, $expiresAt);
            }
            $authorization = $this->signer->issueProviderLocked($lockedNode, $users, $provider, $expiresAt, $now, $usage);
            if ($usage !== null) { app(NativeUsageLedger::class)->bindEnvelopeLocked($usage, $authorization); }
            return ['protocol_version' => 1, 'node_id' => (int) $node->id, 'users' => $users,
                'authorization' => $authorization];
        }, 3);
    }

    private function projectLocked(Server $expectedNode, array $locators, array $configuration,
        #[\SensitiveParameter] array $credential, int $now, bool $writeNode = false): array
    {
        $installation = app(InstallationAuthority::class)->assertNativeRead();
        $this->trust($installation['installation_id']);
        $entitlements = [];
        foreach ($locators as $locator) {
            $userId = (int) $locator->user_id;
            $this->require($userId > 0 && $userId <= 2147483647, 'EVIDENCE_INVALID');
            if (!isset($entitlements[$userId])) {
                $entitlements[$userId] = $this->entitlements->resolveLocked($userId, $now);
            }
        }
        // All accounts precede all Subjects, and all Subjects precede the shared Provider registration.
        $subjects = [];
        foreach ($locators as $locator) {
            $subject = DB::table('tx_access_subject')->where('id', $locator->id)->sharedLock()->first();
            $this->require($subject !== null && (string) $subject->subscription_id === (string) $locator->subscription_id,
                'EVIDENCE_INVALID');
            $subjects[(string) $subject->id] = $subject;
        }
        $profile = $this->profile($expectedNode);
        $membership = DB::table('tx_access_subject')->where('provider_id', $configuration['provider_id'])
            ->where('credential_profile_id', $profile)->where('status', '!=', 'revoked')
            ->whereIn('subscription_id', array_unique(array_map(static fn ($row) => $row->subscription_id, $locators)))
            ->where(static fn ($query) => $query->whereNull('credential_expires_at')->orWhere('credential_expires_at', '>', $now))
            ->orderBy('id')->limit(self::MAX_CANDIDATE_SCAN + 1)->sharedLock()->pluck('id')
            ->map(static fn ($id): string => (string) $id)->all();
        $expectedIds = array_map(static fn ($row): string => (string) $row->id, $locators);
        sort($expectedIds, SORT_NUMERIC);
        $this->require($membership === $expectedIds, 'MEMBERSHIP_CHANGED');
        $registration = $this->credentials->authorizationRegistrationLocked($configuration['provider_id'], $installation['installation_id']);
        $this->require((string) $registration['provider_instance_epoch'] === $configuration['provider_instance_epoch']
            && $registration['config_digest'] === $configuration['config_digest']
            && in_array($profile, $registration['profiles_decoded'], true), 'REGISTRATION_CHANGED');
        $rows = []; $seen = [];
        foreach ($locators as $locator) {
            $entitlement = $entitlements[(int) $locator->user_id];
            if (!$entitlement['authorized']) { continue; }
            $subject = $subjects[(string) $locator->id];
            $this->require($entitlement['subscription_id'] === (string) $subject->subscription_id, 'EVIDENCE_INVALID');
            if ($this->credentials->authorizationPendingLocked($subject, $entitlement, $registration)) { continue; }
            $input = $this->credentials->authorizationBindingLocked($subject, $entitlement, $registration);
            if ((string) $subject->bound_subscription_version !== $entitlement['subscription_version']
                || (string) $subject->bound_terms_id !== $entitlement['terms_id']
                || (int) $subject->not_before_at > $now || (int) $subject->credential_expires_at <= $now) {
                continue;
            }
            $this->require(!isset($seen[$entitlement['user_id']]), 'SUBJECT_AMBIGUOUS');
            foreach (['speed_limit_mbps', 'device_limit'] as $field) {
                $this->require($entitlement[$field] === null || (int) $entitlement[$field] <= 2147483647, 'LIMIT_UNSUPPORTED');
            }
            $seen[$entitlement['user_id']] = true;
            $rows[] = ['input' => $input, 'entitlement' => $entitlement,
                'subject_version' => (string) $subject->row_version, 'auth_version' => (string) $subject->auth_version,
                'credential_expires_at' => (int) $subject->credential_expires_at];
        }
        $this->lockCredential($expectedNode, $credential);
        $query = Server::query()->whereKey($expectedNode->id);
        $lockedNode = ($writeNode ? $query->lockForUpdate() : $query->sharedLock())->first();
        $this->require($lockedNode !== null && $lockedNode->enabled
            && $lockedNode->machine_id === $expectedNode->machine_id
            && $this->profile($lockedNode) === $profile, 'TARGET_CHANGED');
        if (config('native_node_authorization.policy_version') === NativeNodeAuthorization::PROVIDER_USAGE_POLICY_VERSION) {
            NativeRuntimeReadiness::assertSupportedNode($lockedNode);
        }
        $recipient = config('native_provider_node.recipients.'.(string) $lockedNode->id);
        $machineId = $lockedNode->machine_id === null ? null : (int) $lockedNode->machine_id;
        $this->require(is_array($recipient) && count($recipient) === 2 && array_key_exists('machine_id', $recipient)
            && $recipient['machine_id'] === $machineId && is_string($recipient['recipient_sha256'] ?? null)
            && preg_match('/\A[0-9a-f]{64}\z/D', $recipient['recipient_sha256']) === 1, 'RECIPIENT_INVALID');
        $groups = $this->groups($lockedNode);
        $rows = array_values(array_filter($rows, static fn (array $row): bool =>
            $row['entitlement']['group_id'] !== null && in_array($row['entitlement']['group_id'], $groups, true)));
        $this->require(count($rows) <= ProviderMetadataCodec::MAX_SUBJECTS, 'CAPACITY_EXCEEDED');
        return [
            'installation_id' => $installation['installation_id'],
            'target' => ['node_id' => (int) $lockedNode->id, 'machine_id' => $machineId,
                'profile' => $profile, 'groups' => $groups, 'recipient_sha256' => $recipient['recipient_sha256']],
            'registration' => ['provider_id' => $registration['provider_id'],
                'provider_instance_epoch' => (string) $registration['provider_instance_epoch'], 'config_digest' => $registration['config_digest']],
            'rows' => $rows,
        ];
    }

    private function lockCredential(Server $node, #[\SensitiveParameter] array $credential): void
    {
        if ($credential['kind'] === 'node') {
            // Settings are request-cached at middleware admission; use the current locked SQL value here.
            $token = Setting::query()->where('name', 'server_token')->sharedLock()->first()?->value;
            $this->require(is_string($token) && $token !== ''
                && hash_equals($credential['credential_sha256'], hash('sha256', $token)), 'CREDENTIAL_CHANGED');
        }
        $machineId = $node->machine_id === null ? null : (int) $node->machine_id;
        if ($credential['kind'] === 'machine') {
            $this->require($machineId === $credential['machine_id'], 'CREDENTIAL_CHANGED');
        }
        if ($machineId !== null) {
            // Machine administrators lock Machine before its Nodes; retain that order.
            $machine = DB::table('tx_server_machine')->where('id', $machineId)->sharedLock()->first();
            $this->require($machine !== null && in_array($machine->is_active, [true, 1, '1'], true), 'CREDENTIAL_CHANGED');
            if ($credential['kind'] === 'machine') {
                $this->require(is_string($machine->token) && $machine->token !== ''
                    && hash_equals($credential['credential_sha256'], hash('sha256', $machine->token)), 'CREDENTIAL_CHANGED');
            }
        }
    }

    private function locators(Server $node, string $providerId, string $profile, int $now): array
    {
        // A bounded scope locator is not authority: every returned row is rechecked under account-first locks.
        $rows = DB::table('tx_access_subject as subject')->useWritePdo()
            ->join('tx_subscription as subscription', 'subscription.id', '=', 'subject.subscription_id')
            ->join('tx_user as account', 'account.id', '=', 'subscription.user_id')
            ->join('tx_subscription_slot as slot', function ($join): void {
                $join->on('slot.subscription_id', '=', 'subscription.id')->on('slot.user_id', '=', 'subscription.user_id')
                    ->where('slot.slot_no', '=', 1);
            })
            ->join('tx_subscription_terms as terms', function ($join): void {
                $join->on('terms.id', '=', 'subscription.current_terms_id')->on('terms.subscription_id', '=', 'subscription.id');
            })
            ->whereIn('terms.group_id', $this->groups($node))
            ->where(static fn ($query) => $query->where('account.banned', '!=', 1)->orWhereNull('account.banned'))
            ->whereNotIn('subscription.status', ['pending', 'suspended', 'expired', 'revoked'])
            ->where(static fn ($query) => $query->whereNull('subscription.expires_at')->orWhere('subscription.expires_at', '>', $now))
            ->where('subject.provider_id', $providerId)->where('subject.credential_profile_id', $profile)
            ->where('subject.status', '!=', 'revoked')
            ->where(static fn ($query) => $query->whereNull('subject.credential_expires_at')->orWhere('subject.credential_expires_at', '>', $now))
            ->orderBy('subscription.user_id')->orderBy('subject.id')->limit(self::MAX_CANDIDATE_SCAN + 1)
            ->get(['subject.id', 'subject.subscription_id', 'subscription.user_id'])->all();
        $this->require(count($rows) <= self::MAX_CANDIDATE_SCAN, 'SCAN_CAPACITY_EXCEEDED');
        return $rows;
    }

    private function groups(Server $node): array
    {
        $groups = $node->group_ids ?? [];
        $this->require(is_array($groups) && array_is_list($groups), 'TARGET_INVALID');
        $groups = array_map(function ($id): string {
            $this->require((is_int($id) || is_string($id)) && preg_match('/\A[1-9][0-9]{0,9}\z/D', (string) $id) === 1,
                'TARGET_INVALID');
            return (string) $id;
        }, $groups);
        sort($groups, SORT_STRING);
        return $groups;
    }

    private function trust(string $installationId): array
    {
        $trust = $this->signer->publicTrust();
        $this->require(in_array($trust['policy_version'], [NativeNodeAuthorization::PROVIDER_POLICY_VERSION,
                NativeNodeAuthorization::PROVIDER_USAGE_POLICY_VERSION], true)
            && $trust['core_instance_id'] === $installationId, 'TRUST_INVALID');
        return $trust;
    }

    private function profile(Server $node): string
    {
        return match ($node->type) {
            'vless' => 'vless-uuid/v1', 'vmess' => 'vmess-uuid/v1',
            default => throw new DomainException('NATIVE_PROVIDER_NODE_PROFILE_UNSUPPORTED'),
        };
    }

    private function require(bool $valid, string $code): void
    {
        if (!$valid) { throw new DomainException('NATIVE_PROVIDER_NODE_'.$code); }
    }
}
