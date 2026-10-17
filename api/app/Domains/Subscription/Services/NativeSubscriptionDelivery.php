<?php

namespace App\Domains\Subscription\Services;

use App\Domains\CredentialProvider\ProviderCodec;
use App\Domains\CredentialProvider\ProviderMetadataCodec;
use App\Domains\CredentialProvider\ProviderTransport;
use App\Domains\CredentialProvider\RenderGrantCodec;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Network\NativeNodeAuthorization;
use App\Domains\Subscription\Internal\LockedEntitlementQueries;
use App\Models\Server;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Core signs a finite delivery policy; only the PoP recipient receives material. */
final class NativeSubscriptionDelivery
{
    public function __construct(
        private readonly LockedEntitlementQueries $entitlements,
        private readonly NativeCredentialProviderService $credentials,
        private readonly ProviderTransport $transport,
        private readonly NativeNodeAuthorization $signer,
    ) {}

    public static function endpoint(string $providerId): string
    {
        $configuration = config('native_subscription_delivery.providers.'.$providerId);
        $endpoint = is_array($configuration) ? ($configuration['endpoint'] ?? null) : null;
        $url = is_string($endpoint) ? parse_url($endpoint) : false;
        if (!is_array($configuration) || array_keys($configuration) !== ['endpoint'] || !is_array($url)
            || ($url['scheme'] ?? null) !== 'https' || empty($url['host'])
            || ($url['path'] ?? null) !== '/subscription-render'
            || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment'])
            || preg_match('/[^\x21-\x7e]|\\\\/', $endpoint) === 1) {
            throw new DomainException('NATIVE_SUBSCRIPTION_DELIVERY_CONFIG_INVALID');
        }
        return $endpoint;
    }

    public function authorize(int $userId, int $subjectId, string $recipientKey, ?\Closure $guard = null): array
    {
        $this->require(DB::transactionLevel() === 0 && $userId > 0 && $subjectId > 0, 'INPUT_INVALID');
        RenderGrantCodec::publicKey($recipientKey);
        $locator = DB::table('tx_access_subject as subject')->useWritePdo()
            ->join('tx_subscription as subscription', 'subscription.id', '=', 'subject.subscription_id')
            ->where('subject.id', $subjectId)->where('subscription.user_id', $userId)->first(['subject.provider_id']);
        $this->require($locator !== null, 'SUBJECT_MISSING');
        $configuration = $this->transport->configuration($locator->provider_id);
        $endpoint = self::endpoint($locator->provider_id);
        $before = DB::transaction(function () use ($userId, $subjectId, $configuration, $guard): array {
            $projection = $this->project($userId, $subjectId, $configuration, time());
            if ($guard !== null) { $guard(); }
            return $projection;
        }, 3);
        $request = [
            'contract_version' => ProviderMetadataCodec::VERSION, 'request_id' => 'render_'.bin2hex(random_bytes(16)),
            'provider_id' => $before['registration']['provider_id'],
            'provider_instance_epoch' => $before['registration']['provider_instance_epoch'],
            'subjects' => [$before['subject']],
        ];
        ProviderMetadataCodec::validateRequest($request);
        // No network request is made while holding the account/Subject/Node locks.
        $metadata = $this->transport->metadata($before['registration'], $request);
        $metadata = ProviderMetadataCodec::decodeResponse(ProviderCodec::canonical($metadata), $request);
        return DB::transaction(function () use ($userId, $subjectId, $recipientKey, $configuration, $endpoint, $before, $metadata, $guard): array {
            $now = time();
            $after = $this->project($userId, $subjectId, $configuration, $now);
            $policyExpiry = $guard === null ? null : $guard();
            $this->require($after === $before && self::endpoint($configuration['provider_id']) === $endpoint, 'AUTHORITY_CHANGED');
            $credential = $metadata['credentials'][0];
            $this->require($credential['credential_expires_at'] === $after['credential_expires_at'], 'METADATA_INVALID');
            $expires = $now + 30;
            if ($policyExpiry !== null) { $expires = min($expires, $policyExpiry); }
            foreach ([$after['credential_expires_at'], $after['entitlement']['expires_at'],
                $after['entitlement']['next_reset_at']] as $boundary) {
                if ($boundary !== null) {
                    $expires = min($expires, (int) $boundary);
                }
            }
            $this->require($expires > $now, 'EXPIRED');
            $trust = $this->signer->publicTrust();
            $this->require($trust['core_instance_id'] === $after['installation_id'], 'TRUST_INVALID');
            $payload = [
                'protocol_version' => 1, 'policy_version' => RenderGrantCodec::POLICY,
                'audience' => RenderGrantCodec::AUDIENCE, 'core_instance_id' => $after['installation_id'],
                'key_id' => $trust['key_id'], 'key_epoch' => $trust['key_epoch'],
                'issued_at' => $now, 'not_before' => $now, 'expires_at' => $expires,
                'grant_id' => 'render_'.bin2hex(random_bytes(16)),
                'provider_id' => $after['registration']['provider_id'],
                'provider_instance_epoch' => $after['registration']['provider_instance_epoch'],
                'recipient_public_key' => $recipientKey, 'format_id' => RenderGrantCodec::FORMAT,
                'user_id' => (string) $userId, 'subject' => $after['subject'],
                'credential_sha256' => $credential['credential_sha256'],
                'credential_expires_at' => $after['credential_expires_at'], 'nodes' => $after['nodes'],
            ];
            return [
                'endpoint' => $endpoint, 'format_id' => RenderGrantCodec::FORMAT,
                'expires_at' => $expires, 'proof_scheme' => 'native-provider-subscription-request/v1',
                'authorization' => RenderGrantCodec::sign($payload, config('native_node_authorization.private_seed')),
            ];
        }, 3);
    }

    private function project(int $userId, int $subjectId, array $configuration, int $now): array
    {
        $installation = app(InstallationAuthority::class)->assertNativeRead();
        $entitlement = $this->entitlements->resolveLocked($userId, $now);
        $this->require($entitlement['authorized'] === true, 'ENTITLEMENT_DENIED');
        $subject = DB::table('tx_access_subject')->where('id', $subjectId)->sharedLock()->first();
        $this->require($subject !== null && (string) $subject->subscription_id === $entitlement['subscription_id'],
            'SUBJECT_MISSING');
        $registration = $this->credentials->authorizationRegistrationLocked($configuration['provider_id'], $installation['installation_id']);
        $this->require($registration['provider_id'] === $subject->provider_id
            && (string) $registration['provider_instance_epoch'] === $configuration['provider_instance_epoch']
            && $registration['config_digest'] === $configuration['config_digest'], 'REGISTRATION_CHANGED');
        $binding = $this->credentials->authorizationBindingLocked($subject, $entitlement, $registration);
        $this->require((string) $subject->bound_subscription_version === $entitlement['subscription_version']
            && (string) $subject->bound_terms_id === $entitlement['terms_id']
            && (int) $subject->not_before_at <= $now && (int) $subject->credential_expires_at > $now, 'SUBJECT_STALE');
        $this->require($entitlement['group_id'] !== null, 'NODES_UNAVAILABLE');
        $protocol = match ($subject->credential_profile_id) {
            'vless-uuid/v1' => 'vless', 'vmess-uuid/v1' => 'vmess',
            default => throw new DomainException('NATIVE_SUBSCRIPTION_DELIVERY_PROFILE_UNSUPPORTED'),
        };
        $query = Server::query()->where('enabled', true)->where('show', true)->where('type', $protocol)
            ->where(function ($query) use ($entitlement): void {
                $query->whereJsonContains('group_ids', (string) $entitlement['group_id'])
                    ->orWhereJsonContains('group_ids', (int) $entitlement['group_id']);
            });
        $locators = (clone $query)->orderBy('id')->limit(129)->get(['id', 'machine_id']);
        $this->require($locators->isNotEmpty() && $locators->count() <= 128, 'NODES_UNAVAILABLE');
        $machineIds = $locators->pluck('machine_id')->filter(static fn ($id): bool => $id !== null)->unique()->sort()->values()->all();
        if ($machineIds !== []) {
            $machines = DB::table('tx_server_machine')->whereIn('id', $machineIds)->orderBy('id')->sharedLock()->get(['id', 'is_active']);
            $this->require($machines->count() === count($machineIds)
                && $machines->every(static fn ($machine): bool => in_array($machine->is_active, [true, 1, '1'], true)), 'NODES_UNAVAILABLE');
        }
        $nodes = (clone $query)->orderBy('id')->limit(129)->sharedLock()->get();
        $this->require($nodes->count() === $locators->count()
            && $nodes->pluck('id')->all() === $locators->pluck('id')->all(), 'NODES_CHANGED');
        $connections = [];
        foreach ($nodes as $node) {
            $expected = $locators->firstWhere('id', $node->id);
            $this->require($expected !== null && $expected->machine_id === $node->machine_id, 'NODES_CHANGED');
            $machine = $node->machine_id === null ? null : (int) $node->machine_id;
            $recipient = config('native_provider_node.recipients.'.(string) $node->id);
            $this->require(is_array($recipient) && ($recipient['machine_id'] ?? null) === $machine
                && is_string($recipient['recipient_sha256'] ?? null)
                && preg_match('/\A[0-9a-f]{64}\z/D', $recipient['recipient_sha256']) === 1, 'NODE_RECIPIENT_INVALID');
            $connections[] = self::connection($node);
        }
        return [
            'installation_id' => $installation['installation_id'], 'entitlement' => $entitlement,
            'registration' => ['provider_id' => $registration['provider_id'],
                'provider_instance_epoch' => (string) $registration['provider_instance_epoch'], 'config_digest' => $registration['config_digest']],
            'subject' => $binding, 'subject_version' => (string) $subject->row_version,
            'credential_expires_at' => (int) $subject->credential_expires_at, 'nodes' => $connections,
        ];
    }

    public static function connection(Server $node): array
    {
        $settings = $node->protocol_settings ?? [];
        $valid = in_array($node->type, ['vless', 'vmess'], true) && is_array($settings)
            && ($settings['network'] ?? 'tcp') === 'tcp'
            && empty($settings['network_settings']) && empty($settings['flow'])
            && empty($settings['encryption']['enabled']) && empty($settings['multiplex']['enabled'])
            && empty($settings['utls']['enabled']) && in_array($settings['tls'] ?? 0, [0, 1], true);
        if (!$valid || !is_string($node->name) || strlen($node->name) < 1 || strlen($node->name) > 128
            || preg_match('//u', $node->name) !== 1 || preg_match('/\p{Cc}/u', $node->name) === 1
            || !preg_match('/\A[1-9][0-9]{0,4}\z/D', (string) $node->port) || (int) $node->port > 65535) {
            throw new DomainException('NATIVE_SUBSCRIPTION_DELIVERY_NODE_UNSUPPORTED');
        }
        $tls = null;
        if (($settings['tls'] ?? 0) === 1) {
            $tlsSettings = $settings['tls_settings'] ?? [];
            $alpn = $tlsSettings['alpn'] ?? [];
            if (!empty($tlsSettings['allow_insecure']) || !empty($tlsSettings['ech']['enabled'])
                || !is_array($alpn) || !array_is_list($alpn)
                || count($alpn) > 2 || count(array_unique($alpn, SORT_REGULAR)) !== count($alpn)
                || array_diff($alpn, ['h2', 'http/1.1']) !== []) {
                throw new DomainException('NATIVE_SUBSCRIPTION_DELIVERY_NODE_UNSUPPORTED');
            }
            $tls = ['server_name' => RenderGrantCodec::host(($tlsSettings['server_name'] ?? '') ?: $node->host), 'alpn' => $alpn];
        }
        return [
            'node_id' => (string) $node->id, 'machine_id' => $node->machine_id === null ? null : (string) $node->machine_id,
            'name' => $node->name, 'protocol' => $node->type, 'host' => RenderGrantCodec::host($node->host),
            'port' => (int) $node->port, 'transport' => 'tcp', 'tls' => $tls,
        ];
    }

    private function require(bool $condition, string $code): void
    {
        if (!$condition) {
            throw new DomainException('NATIVE_SUBSCRIPTION_DELIVERY_'.$code);
        }
    }
}
