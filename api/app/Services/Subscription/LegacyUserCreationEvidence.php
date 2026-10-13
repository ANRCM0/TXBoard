<?php

namespace App\Services\Subscription;

use Illuminate\Support\Facades\DB;

/** Fixed native creation policies share a canonical immutable INSERT anchor. */
final class LegacyUserCreationEvidence
{
    public const REGISTRATION_VERSION = 'registration-creation-shadow/v1';
    private const POLICIES = [
        'admin.user.create' => ['version' => 'admin-creation-shadow/v1', 'flag' => 'legacy_admin_creation_shadow.enabled'],
        'user.register' => ['version' => self::REGISTRATION_VERSION, 'flag' => 'legacy_registration_creation_shadow.enabled'],
    ];

    public function begin(string $kind, int $actorId): ?array
    {
        $this->require(isset(self::POLICIES[$kind]), 'CREATION_SHADOW_POLICY_INVALID');
        if (config(self::POLICIES[$kind]['flag']) === false) return null;
        return $this->safe(function () use ($kind, $actorId): array {
            $context = $this->context($kind);
            $this->require($kind === 'user.register' ? $actorId === 0 : $actorId > 0 && $actorId <= 2147483647,
                'CREATION_SHADOW_ACTOR_INVALID');
            return ['source_kind' => $kind, 'source_version' => self::POLICIES[$kind]['version'],
                'actor_id' => (string) $actorId, 'context' => $context];
        });
    }

    public function append(?array $ticket, int $userId, string $kind): void
    {
        if ($ticket === null) return;
        $this->safe(function () use ($ticket, $userId, $kind): void {
            $context = $this->context($kind);
            $this->require(count($ticket) === 4 && !array_diff(array_keys($ticket),
                ['source_kind', 'source_version', 'actor_id', 'context']) && $ticket['source_kind'] === $kind
                && $ticket['source_version'] === self::POLICIES[$kind]['version'] && $this->json($ticket['context']) === $this->json($context)
                && is_string($ticket['actor_id']) && ($kind === 'user.register' ? $ticket['actor_id'] === '0'
                    : preg_match('/\A[1-9][0-9]{0,9}\z/D', $ticket['actor_id']) && (int) $ticket['actor_id'] <= 2147483647), 'CREATION_SHADOW_TICKET_INVALID');
            $source = app(LegacyUserMutationCapture::class)->initialEvidenceSource($userId);
            $row = ['legacy_user_id' => (string) $userId, 'source_kind' => $ticket['source_kind'],
                'source_version' => self::POLICIES[$kind]['version'],
                'actor_id' => $kind === 'user.register' ? $source['id'] : $ticket['actor_id'], 'context' => $context,
                'context_hash' => $this->hash($context), 'initial_snapshot' => $source,
                'initial_hash' => $this->hash($source), 'captured_at' => (string) time()];
            $row['content_hash'] = $this->hash($row);
            $row['context'] = $this->json($context); $row['initial_snapshot'] = $this->json($source);
            DB::table('tx_legacy_user_creation_shadow')->insert($row);
        });
    }

    public function verify(int $userId, string $kind): array
    {
        return $this->safe(function () use ($userId, $kind): array {
            $this->require(DB::transactionLevel() === 0, 'CREATION_SHADOW_OUTER_TRANSACTION_UNSUPPORTED');
            return DB::transaction(function () use ($userId, $kind): array {
                $context = $this->context($kind);
                $this->require($userId > 0 && $userId <= 2147483647, 'CREATION_SHADOW_USER_INVALID');
                // Current User first, preserving User -> evidence lock order. Missing User is historical evidence.
                $exists = DB::table('tx_user')->where('id', $userId)->sharedLock()->first(['id']);
                $source = $exists ? app(LegacyUserMutationCapture::class)->initialEvidenceSource($userId) : null;
                $row = DB::table('tx_legacy_user_creation_shadow')->where('legacy_user_id', $userId)->sharedLock()
                    ->first(['legacy_user_id', 'source_kind', 'source_version', 'actor_id', 'context_hash', 'initial_hash', 'captured_at',
                        'content_hash', DB::raw('substr(context,1,2049) as context'), DB::raw('substr(initial_snapshot,1,8193) as initial_snapshot')]);
                $this->require($row !== null && strlen($row->context) <= 2048 && strlen($row->initial_snapshot) <= 8192,
                    'CREATION_SHADOW_EVIDENCE_MISSING_OR_INVALID');
                $payload = (array) $row; unset($payload['content_hash']);
                foreach (['legacy_user_id','actor_id','captured_at'] as $key) $payload[$key] = (string) $payload[$key];
                $payload['context'] = json_decode($row->context, true, 16, JSON_THROW_ON_ERROR);
                $payload['initial_snapshot'] = json_decode($row->initial_snapshot, true, 16, JSON_THROW_ON_ERROR);
                app(LegacyUserMutationCapture::class)->initialEvidenceSnapshot($payload['initial_snapshot']);
                $this->require($payload['source_kind'] === $kind && $payload['source_version'] === self::POLICIES[$kind]['version']
                    && ($kind !== 'user.register' || $payload['actor_id'] === (string) $userId)
                    && preg_match('/\A[1-9][0-9]{0,9}\z/D', $payload['actor_id']) && (int) $payload['actor_id'] <= 2147483647
                    && preg_match('/\A[1-9][0-9]{0,18}\z/D', $payload['captured_at'])
                    && (strlen($payload['captured_at']) < 19 || strcmp($payload['captured_at'], '9223372036854775807') <= 0)
                    && $payload['legacy_user_id'] === (string) $userId && $this->json($payload['context']) === $this->json($context)
                    && $payload['context_hash'] === $this->hash($context) && $payload['initial_hash'] === $this->hash($payload['initial_snapshot'])
                    && $payload['initial_snapshot']['id'] === (string) $userId
                    && $row->content_hash === $this->hash($payload), 'CREATION_SHADOW_EVIDENCE_CHANGED');
                $issues = $source === null ? ['CREATION_SHADOW_SOURCE_MISSING']
                    : ($this->hash($source) === $row->initial_hash ? [] : ['CREATION_SHADOW_SOURCE_CHANGED']);
                $firstHash = app(LegacyUserMutationCapture::class)->initialMutationBeforeHash($userId);
                return ['read_only' => true, 'legacy_user_id' => (string) $userId, 'evidence_valid' => true,
                    'source_matches_initial' => $issues === [], 'issues' => $issues,
                    'first_mutation_before_matches_initial' => $firstHash === null ? null : $firstHash === $row->initial_hash,
                    'mutation_chain_verified' => false, 'global_coverage_complete' => false,
                    'projection_applied' => false, 'migration_gate_open' => false, 'content_hash' => $row->content_hash];
            });
        });
    }

    private function context(string $kind): array
    {
        $this->require(isset(self::POLICIES[$kind]) && config(self::POLICIES[$kind]['flag']) === true, 'CREATION_SHADOW_ISOLATED_REQUIRED');
        return app(LegacyUserMutationCapture::class)->initialEvidenceContext();
    }

    private function json(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            return array_map($normalize, $item);
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    private function hash(array $value): string { return hash('sha256', $this->json($value)); }
    private function require(bool $ok, string $code): void { if (!$ok) throw new LegacyCaptureShadowException($code); }
    private function safe(callable $callback): mixed
    {
        try { return $callback(); }
        catch (LegacyCaptureShadowException $exception) { throw $exception; }
        catch (\Throwable) { throw new LegacyCaptureShadowException('CREATION_SHADOW_STORAGE_OR_EVIDENCE_FAILED'); }
    }
}
