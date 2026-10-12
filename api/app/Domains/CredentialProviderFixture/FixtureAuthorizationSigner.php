<?php

namespace App\Domains\CredentialProviderFixture;

use DomainException;
use Firebase\JWT\JWT;

/** Server-local bounded fixture trust. PEM remains inside this boundary, never an exception argument. */
class FixtureAuthorizationSigner
{
    public static function file(mixed $path, int $limit, bool $private = false): string
    {
        if (!is_string($path) || $path==='' || $path[0]!==DIRECTORY_SEPARATOR || is_link($path) || !is_file($path)
            || filesize($path)>$limit || ($private && PHP_OS_FAMILY!=='Windows' && (fileperms($path)&0777)!==0600)) {
            throw new DomainException('AUTHORIZATION_TRUST_UNAVAILABLE');
        }
        $raw=file_get_contents($path); if (!is_string($raw) || strlen($raw)>$limit) { throw new DomainException('AUTHORIZATION_TRUST_UNAVAILABLE'); }
        return $raw;
    }
    public static function spki(\OpenSSLAsymmetricKey $key): string
    {
        $details=openssl_pkey_get_details($key);
        if (!is_array($details) || $details['type']!==OPENSSL_KEYTYPE_RSA || $details['bits']!==3072) { throw new DomainException('AUTHORIZATION_TRUST_UNAVAILABLE'); }
        $pem=$details['key']; $der=base64_decode(str_replace(["-----BEGIN PUBLIC KEY-----\n","-----END PUBLIC KEY-----\n","\n"],'',$pem),true);
        if ($der===false) { throw new DomainException('AUTHORIZATION_TRUST_UNAVAILABLE'); }
        return hash('sha256',$der);
    }
    public static function trust(?string $configuredPath = null): array
    {
        try {
            $path=$configuredPath ?? config('credential_provider_authorization_fixture.trust_file');
            $raw=self::file($path,16384); $t=FixtureLeaseCandidateCodec::decode($raw);
            FixtureLeaseCandidateCodec::exact($t,['version','issuer','audience','current_kid','keys']);
            if ($t['version']!=='credential-provider/local-authorization-trust/1' || $t['issuer']!==FixtureAuthorizationCodec::ISSUER
                || $t['audience']!==FixtureAuthorizationCodec::AUDIENCE || !is_string($t['current_kid'])
                || !is_array($t['keys']) || !array_is_list($t['keys']) || count($t['keys'])<1 || count($t['keys'])>2) { throw new DomainException(); }
            $keys=[]; $digests=[];
            foreach ($t['keys'] as $key) {
                FixtureLeaseCandidateCodec::exact($key,['kid','epoch','public_key_path','private_key_path','spki_sha256','not_before','not_after','revoked']);
                if (!is_string($key['kid']) || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/',$key['kid']) || isset($keys[$key['kid']])
                    || !is_bool($key['revoked']) || ($key['private_key_path']!==null && !is_string($key['private_key_path']))) { throw new DomainException(); }
                FixtureCodec::decimal($key['epoch']); FixtureCodec::time($key['not_before']); FixtureCodec::time($key['not_after']);
                FixtureLeaseCandidateCodec::hash($key['spki_sha256']); if ($key['not_before']>=$key['not_after']) { throw new DomainException(); }
                $public=self::file($key['public_key_path'],8192); $object=openssl_pkey_get_public($public);
                if (!$object instanceof \OpenSSLAsymmetricKey || openssl_pkey_get_details($object)['key']!==$public
                    || !hash_equals($key['spki_sha256'],self::spki($object))) { throw new DomainException(); }
                $digests[$key['kid']]=hash('sha256',$public); $keys[$key['kid']]=$key+['public'=>$public];
            }
            if (!isset($keys[$t['current_kid']])) { throw new DomainException(); }
            return ['keys'=>$keys,'current_kid'=>$t['current_kid'],'trust_digest'=>FixtureCodec::hash(['raw_sha256'=>hash('sha256',$raw),'public_files'=>$digests])];
        } catch (\Throwable) { throw new DomainException('AUTHORIZATION_TRUST_UNAVAILABLE'); }
    }
    public function prepare(int $now): array
    {
        try {
            $trust=self::trust(); $key=$trust['keys'][$trust['current_kid']];
            if ($key['revoked'] || $now<$key['not_before'] || $now>=$key['not_after'] || $key['private_key_path']===null) { throw new DomainException(); }
            $pem=self::file($key['private_key_path'],8192,true);
            if (!preg_match('/\A-----BEGIN (?:RSA )?PRIVATE KEY-----\n[A-Za-z0-9+\/\n=]+-----END (?:RSA )?PRIVATE KEY-----\n\z/',$pem)) { throw new DomainException(); }
            $private=openssl_pkey_get_private($pem);
            if (!$private instanceof \OpenSSLAsymmetricKey || !hash_equals($key['spki_sha256'],self::spki($private))) { throw new DomainException(); }
            return ['trust_digest'=>$trust['trust_digest'],'key'=>$key,'private'=>$private,'private_file_sha256'=>hash('sha256',$pem)];
        } catch (\Throwable) { throw new DomainException('AUTHORIZATION_TRUST_UNAVAILABLE'); }
    }
    public function sign(array $payload, array $context): string
    {
        try {
            FixtureAuthorizationCodec::payload($payload);
            foreach (['epoch'=>'key_epoch','spki_sha256'=>'key_spki_sha256','not_before'=>'key_not_before','not_after'=>'key_not_after'] as $key=>$field) {
                if ($context['key'][$key]!==$payload[$field]) { throw new DomainException(); }
            }
            if (JWT::$supported_algs['RS256']!==['openssl','SHA256']) { throw new DomainException(); }
            $token=JWT::encode(FixtureLeaseCandidateCodec::decode(FixtureCodec::json($payload)),$context['private'],'RS256',$context['key']['kid'],['typ'=>FixtureAuthorizationCodec::TYPE]);
            FixtureAuthorizationCodec::segments($token); return $token;
        } catch (\Throwable) { throw new DomainException('AUTHORIZATION_SIGNING_FAILED'); }
    }
    public function assertUnchanged(array $context, int $now): void
    {
        $fresh=$this->prepare($now);
        if (!hash_equals($context['trust_digest'],$fresh['trust_digest']) || !hash_equals($context['private_file_sha256'],$fresh['private_file_sha256'])) {
            throw new DomainException('AUTHORIZATION_TRUST_CHANGED');
        }
    }
}
