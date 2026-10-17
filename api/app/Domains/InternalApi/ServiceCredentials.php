<?php

namespace App\Domains\InternalApi;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ServiceCredentials
{
    public function issue(string $id, string $domain, string $fingerprint, array $operations, int $expiresAt): array
    {
        $this->assertNativeProvisioning();
        $this->validate($id, $domain, $fingerprint, $operations, $expiresAt);
        $secret = bin2hex(random_bytes(32));
        DB::table('tx_internal_service')->insert([
            'service_id' => $id, 'security_domain' => $domain,
            'token_hash' => hash('sha256', $secret), 'certificate_sha256' => $fingerprint,
            'epoch' => 1, 'operations_json' => json_encode(array_values($operations), JSON_THROW_ON_ERROR),
            'expires_at' => $expiresAt, 'revoked_at' => null, 'created_at' => time(), 'updated_at' => time(),
        ]);
        return ['service_id' => $id, 'epoch' => '1', 'credential' => $secret];
    }

    public function rotate(string $id, string $fingerprint, int $expiresAt): array
    {
        $this->assertNativeProvisioning();
        return DB::transaction(function () use ($id, $fingerprint, $expiresAt): array {
            $row = DB::table('tx_internal_service')->where('service_id', $id)->lockForUpdate()->first();
            if (!$row || $row->revoked_at !== null) throw new InvalidArgumentException('SERVICE_NOT_ACTIVE');
            $this->validate($id, $row->security_domain, $fingerprint,
                json_decode($row->operations_json, true, flags: JSON_THROW_ON_ERROR), $expiresAt);
            $secret = bin2hex(random_bytes(32));
            $epoch = $this->nextEpoch($row->epoch);
            DB::table('tx_internal_service')->where('service_id', $id)->update([
                'token_hash' => hash('sha256', $secret), 'certificate_sha256' => $fingerprint,
                'epoch' => $epoch, 'expires_at' => $expiresAt, 'updated_at' => time(),
            ]);
            return ['service_id' => $id, 'epoch' => (string) $epoch, 'credential' => $secret];
        });
    }

    public function revoke(string $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $row = DB::table('tx_internal_service')->where('service_id', $id)->lockForUpdate()->first();
            if (!$row) return false;
            if ($row->revoked_at !== null) return true;
            DB::table('tx_internal_service')->where('service_id', $id)->update([
                'revoked_at' => time(), 'epoch' => $this->nextEpoch($row->epoch),
                'updated_at' => time(),
            ]);
            return true;
        });
    }

    private function validate(string $id, string $domain, string $fingerprint, array $operations, int $expiresAt): void
    {
        if (!preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $id)
            || !in_array($domain, ['user', 'admin'], true)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint)
            || $expiresAt <= time() || $expiresAt > time() + 366 * 86400
            || count($operations) < 1 || count($operations) > 100) {
            throw new InvalidArgumentException('SERVICE_CONFIGURATION_INVALID');
        }
        foreach ($operations as $operation) {
            $spec = OperationRegistry::all()[$operation] ?? null;
            if (!$spec || !in_array($domain, $spec['domains'], true)) {
                throw new InvalidArgumentException('SERVICE_OPERATION_INVALID');
            }
        }
    }

    private function assertNativeProvisioning(): void
    {
        $state = app(\App\Domains\Installation\InstallationAuthority::class)->inspect();
        if (config('core_installation.mode') !== 'native' || ($state['mode'] ?? null) !== 'native'
            || !in_array($state['phase'] ?? null, ['initializing', 'open'], true)) {
            throw new InvalidArgumentException('NATIVE_INSTALLATION_REQUIRED');
        }
    }

    private function nextEpoch(mixed $value): int
    {
        $epoch = (string) $value;
        if (!preg_match('/\A[1-9][0-9]{0,18}\z/D', $epoch)
            || strlen($epoch) > strlen((string) PHP_INT_MAX)
            || (strlen($epoch) === strlen((string) PHP_INT_MAX) && strcmp($epoch, (string) PHP_INT_MAX) >= 0)) {
            throw new InvalidArgumentException('SERVICE_EPOCH_EXHAUSTED');
        }
        return (int) $epoch + 1;
    }
}
