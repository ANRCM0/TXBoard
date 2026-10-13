<?php

namespace App\Services\Subscription;

use Illuminate\Support\Facades\DB;

/** Native admission state and subsequent observed absence; not a final pre-DELETE snapshot. */
final class LegacyAdminDeletionShadow
{
    public const VERSION = 'admin-deletion-shadow/v1';

    public function begin(int $userId, int $adminId): ?array
    {
        if (config('legacy_admin_deletion_shadow.enabled') === false) return null;
        return $this->safe(function () use ($userId, $adminId): array {
            $context = $this->context(); $this->id((string) $adminId);
            $snapshot = app(LegacyUserMutationCapture::class)->initialEvidenceSource($userId);
            return ['source_kind' => 'admin.user.delete', 'source_version' => self::VERSION,
                'actor_id' => (string) $adminId, 'context' => $context, 'admitted_snapshot' => $snapshot];
        });
    }

    public function append(?array $ticket): void
    {
        if ($ticket === null) return;
        $this->safe(function () use ($ticket): void {
            $context = $this->context();
            $this->require(count($ticket) === 5 && !array_diff(array_keys($ticket),
                ['source_kind', 'source_version', 'actor_id', 'context', 'admitted_snapshot'])
                && $ticket['source_kind'] === 'admin.user.delete' && $ticket['source_version'] === self::VERSION
                && $this->json($ticket['context']) === $this->json($context), 'DELETION_SHADOW_TICKET_INVALID');
            $this->id($ticket['actor_id']);
            $snapshot = app(LegacyUserMutationCapture::class)->initialEvidenceSnapshot($ticket['admitted_snapshot']);
            // Native deleting callbacks may return false. Preserve its response and session semantics.
            if (DB::table('tx_user')->where('id', $snapshot['id'])->sharedLock()->first(['id']) !== null) return;
            $row = ['legacy_user_id' => $snapshot['id'], 'source_kind' => $ticket['source_kind'],
                'source_version' => self::VERSION, 'actor_id' => $ticket['actor_id'], 'context' => $context,
                'context_hash' => $this->hash($context), 'admitted_snapshot' => $snapshot,
                'admitted_hash' => $this->hash($snapshot), 'deletion_confirmed' => true, 'captured_at' => (string) time()];
            $row['content_hash'] = $this->hash($row);
            $row['context'] = $this->json($context); $row['admitted_snapshot'] = $this->json($snapshot);
            DB::table('tx_legacy_user_deletion_shadow')->insert($row);
        });
    }

    public function verify(int $userId): array
    {
        return $this->safe(function () use ($userId): array {
            $this->require(DB::transactionLevel() === 0, 'DELETION_SHADOW_OUTER_TRANSACTION_UNSUPPORTED');
            return DB::transaction(function () use ($userId): array {
                $context = $this->context(); $this->id((string) $userId);
                $exists = DB::table('tx_user')->where('id', $userId)->sharedLock()->first(['id']) !== null;
                $row = DB::table('tx_legacy_user_deletion_shadow')->where('legacy_user_id', $userId)->sharedLock()
                    ->first(['legacy_user_id', 'source_kind', 'source_version', 'actor_id', 'context_hash', 'admitted_hash',
                        'deletion_confirmed', 'captured_at', 'content_hash', DB::raw('substr(context,1,2049) as context'),
                        DB::raw('substr(admitted_snapshot,1,8193) as admitted_snapshot')]);
                $this->require($row !== null && strlen($row->context) <= 2048 && strlen($row->admitted_snapshot) <= 8192,
                    'DELETION_SHADOW_EVIDENCE_MISSING_OR_INVALID');
                $payload = (array) $row; unset($payload['content_hash']);
                foreach (['legacy_user_id', 'actor_id', 'captured_at'] as $key) $payload[$key] = (string) $payload[$key];
                $this->id($payload['actor_id']);
                $this->require(in_array($payload['deletion_confirmed'], [true, 1, '1'], true), 'DELETION_SHADOW_EVIDENCE_CHANGED');
                $payload['deletion_confirmed'] = true;
                $payload['context'] = json_decode($row->context, true, 16, JSON_THROW_ON_ERROR);
                $payload['admitted_snapshot'] = json_decode($row->admitted_snapshot, true, 16, JSON_THROW_ON_ERROR);
                app(LegacyUserMutationCapture::class)->initialEvidenceSnapshot($payload['admitted_snapshot']);
                $this->require($payload['source_kind'] === 'admin.user.delete' && $payload['source_version'] === self::VERSION
                    && preg_match('/\A[1-9][0-9]{0,18}\z/D', $payload['captured_at'])
                    && (strlen($payload['captured_at']) < 19 || strcmp($payload['captured_at'], '9223372036854775807') <= 0)
                    && $payload['legacy_user_id'] === (string) $userId && $this->json($payload['context']) === $this->json($context)
                    && $payload['context_hash'] === $this->hash($context) && $payload['admitted_hash'] === $this->hash($payload['admitted_snapshot'])
                    && $payload['admitted_snapshot']['id'] === (string) $userId && $row->content_hash === $this->hash($payload),
                    'DELETION_SHADOW_EVIDENCE_CHANGED');
                return ['read_only' => true, 'legacy_user_id' => (string) $userId, 'evidence_valid' => true,
                    'source_absent' => !$exists, 'issues' => $exists ? ['DELETION_SHADOW_SOURCE_REAPPEARED'] : [],
                    'snapshot_boundary' => 'native-admission-before-trusted-hook', 'final_predelete_state_verified' => false,
                    'mutation_chain_verified' => false, 'global_coverage_complete' => false,
                    'revocation_converged' => false, 'projection_applied' => false, 'migration_gate_open' => false,
                    'content_hash' => $row->content_hash];
            });
        });
    }

    private function context(): array
    {
        $this->require(config('legacy_admin_deletion_shadow.enabled') === true, 'DELETION_SHADOW_ISOLATED_REQUIRED');
        return app(LegacyUserMutationCapture::class)->initialEvidenceContext();
    }
    private function id(mixed $id): void
    {
        $this->require(is_string($id) && preg_match('/\A[1-9][0-9]{0,9}\z/D', $id) && (int) $id <= 2147483647,
            'DELETION_SHADOW_REFERENCE_INVALID');
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
        catch (\Throwable) { throw new LegacyCaptureShadowException('DELETION_SHADOW_STORAGE_OR_EVIDENCE_FAILED'); }
    }
}
