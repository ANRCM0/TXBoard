<?php

namespace App\Domains\CredentialProviderFixture;

use DomainException;
use Firebase\JWT\{JWT, Key};

/** Offline signature/window checks are distinct from live Core eligibility and never grant admission. */
final class FixtureAuthorizationVerifier
{
    public function __construct(private FixtureLeaseClock $clock, private ?string $trustPath = null) {}
    public function verifySignature(string $token): array
    {
        try {
            [$header,$payload]=FixtureAuthorizationCodec::segments($token); $trust=FixtureAuthorizationSigner::trust($this->trustPath);
            $key=$trust['keys'][$header['kid']] ?? null;
            if (!is_array($key) || $payload['key_epoch']!==$key['epoch'] || $payload['key_spki_sha256']!==$key['spki_sha256']
                || $payload['key_not_before']!==$key['not_before'] || $payload['key_not_after']!==$key['not_after']
                || JWT::$supported_algs['RS256']!==['openssl','SHA256']) { throw new DomainException(); }
            // This exact JWS profile has no JWT iat/nbf/exp; UTC is validated explicitly below.
            JWT::decode($token,new Key($key['public'],'RS256'));
            return ['signature_verified'=>true,'payload'=>$payload,'key_revoked'=>$key['revoked'],
                'trust_digest'=>$trust['trust_digest'],'admission_granted'=>false];
        } catch (\Throwable) { throw new DomainException('AUTHORIZATION_SIGNATURE_INVALID'); }
    }
    public function verifyWindow(string $token): array
    {
        $monotonic=$this->clock->monotonic(); $result=$this->verifySignature($token); $p=$result['payload'];
        $now=FixtureCodec::time($this->clock->wall()); $elapsed=$this->clock->monotonic()-$monotonic;
        if ($result['key_revoked']) { throw new DomainException('AUTHORIZATION_KEY_REVOKED'); }
        if ($now<$p['issued_at'] || $elapsed<0) { throw new DomainException('AUTHORIZATION_CLOCK_REGRESSION'); }
        if ($now>=$p['lease_expires_at'] || $now+$elapsed>=$p['lease_expires_at']) { throw new DomainException('AUTHORIZATION_EXPIRED'); }
        return $result+['within_window'=>true];
    }
}
