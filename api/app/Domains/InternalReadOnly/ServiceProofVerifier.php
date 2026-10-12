<?php

namespace App\Domains\InternalReadOnly;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;

final class ServiceProofVerifier
{
    public function verify(Request $request, array $operation): array
    {
        $proof = $request->header('X-TXBoard-Service-Proof', '');
        $authorization = $request->header('Authorization', '');
        if (!is_string($proof) || strlen($proof) > config('internal_api_slice.max_proof_bytes')
            || !is_string($authorization) || strlen($authorization) > config('internal_api_slice.max_authorization_bytes')
            || !preg_match('/\ABearer [A-Za-z0-9|._-]{1,4089}\z/D', $authorization)) {
            throw new SliceFailure('SERVICE_UNAUTHORIZED', 401);
        }
        try {
            $parts = explode('.', $proof);
            if (count($parts) !== 3) {
                throw new \UnexpectedValueException();
            }
            $header = StrictJson::object(self::base64url($parts[0]));
            $claims = StrictJson::object(self::base64url($parts[1]));
            self::base64url($parts[2]);
            StrictJson::exact($header, ['alg', 'typ', 'kid']);
            StrictJson::exact($claims, ['v','epoch','iss','aud','service','cert_sha256','method','path','body_sha256',
                'actor_sha256','iat','nbf','exp','jti']);
            if ($header->alg !== 'RS256' || $header->typ !== 'JWT' || !is_string($header->kid)
                || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $header->kid)) {
                throw new \UnexpectedValueException();
            }
            $path = config('internal_api_slice.trust_file');
            if (!is_string($path) || !str_starts_with($path, DIRECTORY_SEPARATOR) || !is_file($path)
                || filesize($path) > 32768) {
                throw new \UnexpectedValueException();
            }
            $trust = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            $key = $trust['proof_keys'][$header->kid] ?? null;
            $service = is_string($claims->service) ? ($trust['services'][$claims->service] ?? null) : null;
            $now = time();
            if (!is_array($key) || !is_array($service) || ($key['revoked'] ?? true) !== false
                || ($key['service'] ?? null) !== $claims->service
                || !is_int($key['not_before'] ?? null) || !is_int($key['not_after'] ?? null)
                || $now < $key['not_before'] || $now >= $key['not_after']
                || $claims->v !== 'internal-readonly/1' || !is_int($claims->epoch) || $claims->epoch !== ($key['epoch'] ?? null)
                || $claims->iss !== config('internal_api_slice.issuer') || $claims->aud !== config('internal_api_slice.audience')
                || $claims->service !== $operation['definition']['service']
                || !is_string($claims->cert_sha256) || !preg_match('/\A[0-9a-f]{64}\z/D', $claims->cert_sha256)) {
                throw new \UnexpectedValueException();
            }
            $certificate = $service['certificates'][$claims->cert_sha256] ?? null;
            if (!is_array($certificate) || ($certificate['revoked'] ?? true) !== false
                || !is_int($certificate['not_before'] ?? null) || !is_int($certificate['not_after'] ?? null)
                || $now < $certificate['not_before'] || $now >= $certificate['not_after']) {
                throw new \UnexpectedValueException();
            }
            foreach (['iat','nbf','exp'] as $field) {
                if (!is_int($claims->$field)) {
                    throw new \UnexpectedValueException();
                }
            }
            $skew = config('internal_api_slice.clock_skew_seconds');
            if ($claims->iat > $now + $skew || $claims->iat < $now - config('internal_api_slice.max_proof_seconds')
                || $claims->nbf !== $claims->iat - $skew || $claims->nbf > $now
                || $claims->exp <= $now || $claims->exp <= $claims->iat
                || $claims->exp > $claims->iat + config('internal_api_slice.max_proof_seconds')
                || $claims->exp > $key['not_after'] || $claims->exp > $certificate['not_after']
                || !is_string($claims->jti) || !preg_match('/\A[0-9a-f]{32}\z/D', $claims->jti)
                || $claims->method !== $request->method() || $claims->path !== $request->getRequestUri()) {
                throw new \UnexpectedValueException();
            }
            foreach (['body_sha256' => $request->getContent(), 'actor_sha256' => $authorization] as $field => $value) {
                if (!is_string($claims->$field) || !hash_equals(hash('sha256', $value), $claims->$field)) {
                    throw new \UnexpectedValueException();
                }
            }
            $publicPath = $key['public_key_path'] ?? null;
            if (!is_string($publicPath) || !str_starts_with($publicPath, DIRECTORY_SEPARATOR)
                || !is_file($publicPath) || filesize($publicPath) > 8192) {
                throw new \UnexpectedValueException();
            }
            $public = file_get_contents($publicPath);
            $details = openssl_pkey_get_details(openssl_pkey_get_public($public));
            if (!is_array($details) || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
                throw new \UnexpectedValueException();
            }
            // Explicit algorithm/key binding. Do not mutate JWT::$leeway/$timestamp under Octane.
            JWT::decode($proof, new Key($public, 'RS256'));
            return ['kid' => $header->kid, 'jti' => $claims->jti, 'expires_at' => $claims->exp,
                'service' => $claims->service, 'epoch' => $claims->epoch];
        } catch (\Throwable) {
            throw new SliceFailure('SERVICE_UNAUTHORIZED', 401);
        }
    }

    private static function base64url(string $encoded): string
    {
        if ($encoded === '' || !preg_match('/\A[A-Za-z0-9_-]+\z/D', $encoded)) {
            throw new \UnexpectedValueException();
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($decoded === false || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $encoded) {
            throw new \UnexpectedValueException();
        }
        return $decoded;
    }
}
