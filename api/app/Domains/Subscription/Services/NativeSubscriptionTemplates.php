<?php

namespace App\Domains\Subscription\Services;

use App\Domains\CredentialProvider\RenderGrantCodec;
use App\Domains\CredentialProvider\ProviderCodec;
use App\Services\NativeSettingsStorage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Template policy contains administrator source, never recipient credentials. */
final class NativeSubscriptionTemplates
{
    private function key(string $format): string
    {
        abort_unless(isset(RenderGrantCodec::CLIENT_FORMATS[$format]), 422, 'SUBSCRIPTION_TEMPLATE_FORMAT_INVALID');
        return 'native_subscription_template_'.str_replace(['/', '-'], '_', $format);
    }

    public function snapshot(string $format): array
    {
        if (DB::transactionLevel() === 0) throw new \LogicException('TEMPLATE_TRANSACTION_REQUIRED');
        $row = DB::table('tx_settings')->where('name', $this->key($format))->sharedLock()->first();
        $value = $row === null ? null : json_decode($row->value, true, 8, JSON_THROW_ON_ERROR);
        if ($value !== null && (!is_array($value) || array_keys($value) !== ['source', 'revision']
            || !is_string($value['source']) || !is_string($value['revision']))) throw new \DomainException('SUBSCRIPTION_TEMPLATE_STORAGE_INVALID');
        $source = $value['source'] ?? '';
        return ['format_id' => $format, 'version' => hash_hmac('sha256', ProviderCodec::canonical([$format, $value]), (string) config('app.key')),
            'source' => $source, 'custom' => $value !== null && $source !== '', 'sha256' => hash('sha256', $source)];
    }

    public function envelope(string $format): ?array
    {
        if ($format === RenderGrantCodec::FORMAT) return null;
        $snapshot = $this->snapshot($format);
        return $snapshot['custom'] ? ['engine' => 'tx-template/v1', 'format_id' => $format, 'version' => $snapshot['version'],
            'sha256' => $snapshot['sha256'], 'source' => $snapshot['source']] : null;
    }

    public function replace(string $format, string $expected, ?string $source): void
    {
        app(NativeSettingsStorage::class)->lock();
        abort_unless(hash_equals($this->snapshot($format)['version'], $expected), 409, 'SUBSCRIPTION_TEMPLATE_VERSION_CONFLICT');
        if ($source !== null) self::assertSource($source);
        // Preserve an ever-changing revision across reset to prevent ABA writes.
        app(NativeSettingsStorage::class)->persist([$this->key($format) => json_encode(['source' => $source ?? '',
            'revision' => bin2hex(random_bytes(16))], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    public static function assertSource(string $source): void
    {
        abort_unless(strlen($source) >= 1 && strlen($source) <= 65536 && preg_match('//u', $source) === 1
            && !str_contains($source, "\0"), 422, 'SUBSCRIPTION_TEMPLATE_SOURCE_INVALID');
    }

    /** Replay is checked inside current authorization before testing the old CAS. */
    public function receipt(int $actor, string $operation, string $key, array $input): bool
    {
        app(NativeSettingsStorage::class)->lock();
        [$identity, $digest] = $this->receiptIdentity($actor, $operation, $key, $input);
        $row = DB::table('tx_native_subscription_template_receipt')->where('receipt_key', $identity)->lockForUpdate()->first();
        if ($row === null) return false;
        abort_unless((string) $row->actor_id === (string) $actor && $row->operation === $operation
            && hash_equals($row->input_digest, $digest), 409, 'SUBSCRIPTION_TEMPLATE_IDEMPOTENCY_CONFLICT');
        $result = json_decode(Crypt::decryptString($row->result_ciphertext), true, 8, JSON_THROW_ON_ERROR);
        if ($result !== ['ok' => true]) throw new \DomainException('SUBSCRIPTION_TEMPLATE_RECEIPT_INVALID');
        return true;
    }

    public function remember(int $actor, string $operation, string $key, array $input): void
    {
        if (DB::transactionLevel() === 0) throw new \LogicException('TEMPLATE_TRANSACTION_REQUIRED');
        [$identity, $digest] = $this->receiptIdentity($actor, $operation, $key, $input);
        DB::table('tx_native_subscription_template_receipt')->insert(['receipt_key' => $identity, 'actor_id' => $actor,
            'operation' => $operation, 'input_digest' => $digest,
            'result_ciphertext' => Crypt::encryptString('{"ok":true}'), 'created_at' => time()]);
    }

    private function receiptIdentity(int $actor, string $operation, string $key, array $input): array
    {
        abort_unless($actor > 0 && in_array($operation, ['save', 'reset'], true) && strlen($key) >= 1
            && strlen($key) <= 128 && preg_match('/\A[\x21-\x7e]+\z/D', $key) === 1, 422, 'SUBSCRIPTION_TEMPLATE_IDEMPOTENCY_INVALID');
        $secret = (string) config('app.key');
        return [hash_hmac('sha256', ProviderCodec::canonical([$actor, $operation, $key]), $secret),
            hash_hmac('sha256', ProviderCodec::canonical($input), $secret)];
    }
}
