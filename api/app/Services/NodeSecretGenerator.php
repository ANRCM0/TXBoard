<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Native node secret generation; deliberately stateless, no persistence
 * and no legacy admin controller dependency.
 */
final class NodeSecretGenerator
{
    public function generate(string $kind, Request $request): array
    {
        return match ($kind) {
            'x25519' => $this->x25519KeyPairMaterial(),
            'hex' => $this->hexMaterial($request),
            'ech' => $this->echKeyMaterial((string) $request->input('public_name', '')),
            default => throw ValidationException::withMessages([
                'kind' => 'Unsupported node secret generator',
            ]),
        };
    }

    /** @return array{private_key:string,public_key:string} */
    private function x25519KeyPairMaterial(): array
    {
        $privateKey = random_bytes(32);

        return [
            'private_key' => base64_encode($privateKey),
            'public_key' => base64_encode(sodium_crypto_scalarmult_base($privateKey)),
        ];
    }

    /** @return array{value:string} */
    private function hexMaterial(Request $request): array
    {
        $bytes = (int) $request->input('bytes', 8);
        $bytes = max(1, min(64, $bytes));

        return ['value' => bin2hex(random_bytes($bytes))];
    }

    /**
     * Build ECH key material: PEM key (server-side) plus PEM config (client-side).
     *
     * @return array{key:string,config:string}
     */
    private function echKeyMaterial(string $publicName): array
    {
        $publicName = trim($publicName) ?: 'ech.example.com';
        if (strlen($publicName) > 253 || !preg_match(
            '/^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(?:\\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/D',
            $publicName
        )) {
            throw ValidationException::withMessages(['public_name' => 'Invalid ECH public hostname']);
        }

        $privateKey = random_bytes(32);
        $publicKey = sodium_crypto_scalarmult_base($privateKey);

        $configId = random_int(0, 255);

        // Build ECHConfigContents (draft-ietf-tls-esni-18)
        $contents = '';
        $contents .= pack('C', $configId);                // config_id
        $contents .= pack('n', 0x0020);                   // kem_id: DHKEM(X25519)
        $contents .= pack('n', 32) . $publicKey;          // public_key (length-prefixed)
        // cipher_suites: 2 suites × 4 bytes = 8 bytes
        $contents .= pack('n', 8);                        // cipher_suites byte length
        $contents .= pack('nn', 0x0001, 0x0001);          // HKDF-SHA256 + AES-128-GCM
        $contents .= pack('nn', 0x0001, 0x0003);          // HKDF-SHA256 + ChaCha20Poly1305
        $contents .= pack('C', 0);                        // max_name_length
        $contents .= pack('C', strlen($publicName)) . $publicName;
        $contents .= pack('n', 0);                        // extensions: empty

        // ECHConfig = version(2) + length(2) + contents
        $echConfig = pack('n', 0xfe0d) . pack('n', strlen($contents)) . $contents;

        // ECHConfigList = total_length(2) + configs
        $echConfigList = pack('n', strlen($echConfig)) . $echConfig;

        // ECH Keys = private_key_len(2) + key(32) + config_len(2) + config
        $echKeysPayload = pack('n', 32) . $privateKey . pack('n', strlen($echConfig)) . $echConfig;

        $keyPem = "-----BEGIN ECH KEYS-----\n"
            . chunk_split(base64_encode($echKeysPayload), 64, "\n")
            . "-----END ECH KEYS-----";

        $configPem = "-----BEGIN ECH CONFIGS-----\n"
            . chunk_split(base64_encode($echConfigList), 64, "\n")
            . "-----END ECH CONFIGS-----";

        return [
            'key' => $keyPem,
            'config' => $configPem,
        ];
    }
}
