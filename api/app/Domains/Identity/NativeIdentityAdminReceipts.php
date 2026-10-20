<?php

namespace App\Domains\Identity;

use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Receipts commit with the identity changes; only a keyed digest of secret inputs is retained. */
final class NativeIdentityAdminReceipts
{
    public static function fingerprint(string $installation, int $actor, string $operation, string $key, array $input): array
    {
        if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/Di', $key)) throw new DomainException('NATIVE_ACCOUNT_INPUT_INVALID');
        return ['key_hash' => hash('sha256', $installation.':'.$actor.':'.$operation.':'.strtolower($key)),
            'installation_id' => $installation, 'actor_id' => $actor, 'operation' => $operation,
            'input_hash' => self::mac(self::canonical($input))];
    }

    public static function recover(array $fingerprint): ?array
    {
        $row = DB::table('tx_native_identity_admin_request')->where('key_hash', $fingerprint['key_hash'])->lockForUpdate()->first();
        if ($row === null) return null;
        foreach (['installation_id', 'actor_id', 'operation'] as $field) if ((string) $row->$field !== (string) $fingerprint[$field]) throw new DomainException('NATIVE_ACCOUNT_RECEIPT_INVALID');
        if (!hash_equals($row->input_hash, $fingerprint['input_hash'])) throw new DomainException('NATIVE_ACCOUNT_IDEMPOTENCY_CONFLICT');
        $json = Crypt::decryptString($row->response_ciphertext);
        if (!hash_equals($row->content_hash, self::mac($row->input_hash.':'.$json))) throw new DomainException('NATIVE_ACCOUNT_RECEIPT_INVALID');
        return json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    }

    public static function append(array $fingerprint, array $response): array
    {
        $json = json_encode($response, JSON_THROW_ON_ERROR);
        DB::table('tx_native_identity_admin_request')->insert($fingerprint + ['response_ciphertext' => Crypt::encryptString($json),
            'content_hash' => self::mac($fingerprint['input_hash'].':'.$json), 'created_at' => time()]);
        return $response;
    }

    private static function canonical(array $input): string
    {
        $sort = static function (mixed $value) use (&$sort): mixed {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            foreach ($value as &$item) $item = $sort($item);
            return $value;
        };
        return json_encode($sort($input), JSON_THROW_ON_ERROR);
    }

    private static function mac(string $value): string { return hash_hmac('sha256', $value, (string) config('app.key')); }
}
