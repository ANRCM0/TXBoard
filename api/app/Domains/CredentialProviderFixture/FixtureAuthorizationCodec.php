<?php

namespace App\Domains\CredentialProviderFixture;

use DomainException;

/** Fixed nonsecret local JWS profile, separate from Node grants and Internal/OAuth JWTs. */
final class FixtureAuthorizationCodec
{
    public const VERSION = 'credential-provider/local-authorization/1';
    public const TYPE = 'txboard-fixture-authorization+jws';
    public const ISSUER = 'txboard:local-synthetic-authority';
    public const AUDIENCE = 'txboard:offline-fixture-verifier';
    public const POLICY = 'local-synthetic-binding-60s/v1';
    public const MAX_BYTES = 16384;
    public static function request(FixtureScope $scope, string $candidate, string $key): array
    {
        if (!preg_match('/\Alc_[a-f0-9]{32}\z/', $candidate) || !preg_match('/\Afixture_[a-z0-9_-]{1,96}\z/', $key)) {
            throw new DomainException('PROVIDER_INPUT_INVALID');
        }
        return ['provider_id'=>$scope->providerId, 'access_subject_id'=>$scope->subjectId, 'actor_ref'=>$scope->actorRef,
            'authorization_ref'=>$scope->authorizationRef, 'candidate_ref'=>$candidate, 'business_key'=>$key];
    }
    public static function policyDigest(): string
    {
        return FixtureCodec::hash(['revision'=>self::POLICY, 'max_seconds'=>60, 'admission_granted'=>false]);
    }
    public static function increment(string $value): string
    {
        FixtureCodec::decimal($value); return FixtureCodec::decimal(bcadd($value, '1', 0));
    }
    public static function transition(array $t): void
    {
        FixtureLeaseCandidateCodec::exact($t, ['original_subject_ref','original_auth_version','original_row_version',
            'subject_ref','auth_version','row_version','credential_ref','credential_generation','committed_at']);
        if ($t['original_subject_ref'] !== 'core_pending_fixture' || $t['credential_generation'] !== '1'
            || !is_string($t['subject_ref']) || !preg_match('/\Aps_[a-f0-9]{32}\z/', $t['subject_ref'])
            || !is_string($t['credential_ref']) || !preg_match('/\Acr_[a-f0-9]{32}\z/', $t['credential_ref'])
            || self::increment($t['original_auth_version']) !== $t['auth_version']
            || self::increment($t['original_row_version']) !== $t['row_version']) { throw new DomainException('AUTHORIZATION_EVIDENCE_INVALID'); }
        FixtureCodec::time($t['committed_at']);
    }
    public static function segments(string $token): array
    {
        if (strlen($token) > self::MAX_BYTES) { throw new DomainException('AUTHORIZATION_EVIDENCE_INVALID'); }
        $parts=explode('.', $token); if (count($parts)!==3) { throw new DomainException('AUTHORIZATION_EVIDENCE_INVALID'); }
        $decoded=[];
        foreach ($parts as $part) {
            if (!preg_match('/\A[A-Za-z0-9_-]+\z/', $part)) { throw new DomainException('AUTHORIZATION_EVIDENCE_INVALID'); }
            $raw=base64_decode(strtr($part,'-_','+/'),true);
            if ($raw===false || rtrim(strtr(base64_encode($raw),'+/','-_'),'=')!==$part) { throw new DomainException('AUTHORIZATION_EVIDENCE_INVALID'); }
            $decoded[]=$raw;
        }
        $header=FixtureLeaseCandidateCodec::decode($decoded[0]);
        FixtureLeaseCandidateCodec::exact($header, ['alg','typ','kid']);
        if ($header['alg']!=='RS256' || $header['typ']!==self::TYPE || !is_string($header['kid'])
            || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $header['kid']) || strlen($decoded[2])!==384) {
            throw new DomainException('AUTHORIZATION_EVIDENCE_INVALID');
        }
        $payload=FixtureLeaseCandidateCodec::decode($decoded[1]); self::payload($payload);
        return [$header,$payload];
    }
    public static function payload(array $p): void
    {
        try { self::payloadExact($p); }
        catch (\Throwable) { throw new DomainException('AUTHORIZATION_EVIDENCE_INVALID'); }
    }
    private static function payloadExact(array $p): void
    {
        FixtureLeaseCandidateCodec::exact($p, ['v','mode','iss','aud','snapshot_ref','provider_id','access_subject_id','actor_ref','authorization_ref',
            'candidate_id','candidate_ref','candidate_request','candidate_evidence','candidate_evidence_hash','binding_commit','source','policy',
            'issued_at','lease_expires_at','key_epoch','key_spki_sha256','key_not_before','key_not_after',
            'admission_granted','delivery_status','node_convergence']);
        if ($p['v']!==self::VERSION || $p['mode']!=='local_synthetic_authorization_snapshot' || $p['iss']!==self::ISSUER || $p['aud']!==self::AUDIENCE
            || !is_string($p['snapshot_ref']) || !preg_match('/\Aasf_[a-f0-9]{32}\z/', $p['snapshot_ref'])
            || $p['admission_granted']!==false || $p['delivery_status']!=='not_implemented' || $p['node_convergence']!=='not_implemented') {
            throw new DomainException();
        }
        $scope=new FixtureScope($p['provider_id'],$p['access_subject_id'],$p['actor_ref'],$p['authorization_ref']);
        FixtureCodec::decimal($p['candidate_id']); FixtureCodec::decimal($p['key_epoch']); FixtureLeaseCandidateCodec::hash($p['key_spki_sha256']);
        $e=$p['candidate_evidence']; $candidate=(object) ['candidate_ref'=>$p['candidate_ref'], 'provider_id'=>$scope->providerId,
            'access_subject_id'=>$scope->subjectId, 'business_key'=>$p['candidate_request']['business_key'],
            'request_snapshot'=>FixtureCodec::json($p['candidate_request']), 'request_hash'=>FixtureCodec::hash($p['candidate_request']),
            'evidence_snapshot'=>FixtureCodec::json($e), 'evidence_hash'=>$p['candidate_evidence_hash'],
            'prepared_at'=>$e['prepared_at'], 'candidate_expires_at'=>$e['candidate_expires_at'], 'state'=>'prepared','reason_code'=>null,
            'last_observed_at'=>$e['prepared_at'], 'row_version'=>1];
        foreach (['binding_id','registration_id','issue_operation_id','source_id','subscription_id','terms_id','usage_period_id','tariff_revision_id'] as $field) { $candidate->$field=$e[$field]; }
        FixtureLeaseCandidateCodec::retained($candidate,$scope); self::transition($p['binding_commit']); $t=$p['binding_commit'];
        if ($t['original_subject_ref']!==$e['subject_core_ref'] || $t['original_auth_version']!==$e['auth_version']
            || $t['subject_ref']!==$e['issue_result']['provider_subject_ref'] || $t['credential_ref']!==$e['issue_result']['credential_ref']
            || $t['committed_at']!==$p['issued_at']) { throw new DomainException(); }
        FixtureLeaseCandidateCodec::exact($p['source'], ['source_id','node_id','source_epoch','installation_ref','core_instance_id','receipt_generation']);
        foreach (['source_id','node_id','source_epoch','core_instance_id','receipt_generation'] as $field) { if ($p['source'][$field]!==$e[$field]) { throw new DomainException(); } }
        if (!is_string($p['source']['installation_ref']) || !preg_match('/\A[A-Za-z0-9:_-]{8,80}\z/', $p['source']['installation_ref'])) { throw new DomainException(); }
        FixtureLeaseCandidateCodec::exact($p['policy'], ['revision','digest','quota_bytes','settled_billable_bytes','subject_revocation_fact_version',
            'provider_fact_domain','provider_subject_revoked','node_revocation_version']);
        $policy=$p['policy']; FixtureLeaseCandidateCodec::number($policy['quota_bytes']); FixtureLeaseCandidateCodec::number($policy['settled_billable_bytes']);
        if ($policy['revision']!==self::POLICY || $policy['digest']!==self::policyDigest() || $policy['quota_bytes']!==$e['period_quota_bytes']
            || bccomp($policy['settled_billable_bytes'],$policy['quota_bytes'],0)>=0 || $policy['subject_revocation_fact_version']!=='0'
            || $e['revocation_version']!=='0' || $policy['provider_fact_domain']!=='provider-subject/v1'
            || $policy['provider_subject_revoked']!==false || $policy['node_revocation_version']!==null) { throw new DomainException(); }
        foreach (['issued_at','lease_expires_at','key_not_before','key_not_after'] as $f) { FixtureCodec::time($p[$f]); }
        if ($p['issued_at']<$e['prepared_at'] || $p['issued_at']<$p['key_not_before'] || $p['lease_expires_at']<=$p['issued_at']
            || $p['lease_expires_at']>$e['candidate_expires_at'] || $p['lease_expires_at']>$p['key_not_after']) { throw new DomainException(); }
    }
    public static function retained(object $row, FixtureScope $scope): array
    {
        try {
            $request=FixtureLeaseCandidateCodec::decode($row->request_snapshot);
            FixtureLeaseCandidateCodec::exact($request,['provider_id','access_subject_id','actor_ref','authorization_ref','candidate_ref','business_key']);
            if ($request!==self::orderedRequest($scope,$request['candidate_ref'],$request['business_key'])
                || $row->provider_id!==$scope->providerId || (string)$row->access_subject_id!==$scope->subjectId
                || $row->business_key!==$request['business_key'] || !hash_equals($row->request_hash,FixtureCodec::hash($request))) { throw new DomainException(); }
            [$header,$p]=self::segments($row->signed_snapshot); $stored=FixtureLeaseCandidateCodec::decode($row->payload_snapshot);
            if (FixtureCodec::hash($p)!==FixtureCodec::hash($stored) || !hash_equals($row->payload_hash,FixtureCodec::hash($p))
                || !hash_equals($row->signature_hash,hash('sha256',$row->signed_snapshot)) || $p['snapshot_ref']!==$row->snapshot_ref
                || $p['provider_id']!==$scope->providerId || $p['access_subject_id']!==$scope->subjectId
                || $p['actor_ref']!==$scope->actorRef || $p['authorization_ref']!==$scope->authorizationRef || $p['candidate_ref']!==$request['candidate_ref']
                || $header['kid']!==$row->key_id || $p['key_epoch']!==(string)$row->key_epoch || $p['key_spki_sha256']!==$row->key_spki_sha256
                || $p['issued_at']!==(int)$row->issued_at || $p['lease_expires_at']!==(int)$row->lease_expires_at) { throw new DomainException(); }
            foreach (['binding_id','registration_id','issue_operation_id','source_id','subscription_id','terms_id','usage_period_id','tariff_revision_id'] as $field) {
                if ($p['candidate_evidence'][$field]!==(string)$row->$field) { throw new DomainException(); }
            }
            if ($p['candidate_id']!==(string)$row->candidate_id || !in_array($row->state,['valid','expired','refused'],true)
                || (int)$row->last_observed_at<$p['issued_at'] || ($row->state==='valid' && $row->reason_code!==null)
                || ($row->state!=='valid' && (!is_string($row->reason_code) || !preg_match('/\A[A-Z][A-Z0-9_]{2,79}\z/',$row->reason_code)))) { throw new DomainException(); }
            FixtureCodec::decimal((string)$row->row_version); FixtureLeaseCandidateCodec::dbTime($row->last_observed_at);
            return $p;
        } catch (\Throwable) { throw new DomainException('AUTHORIZATION_EVIDENCE_INVALID'); }
    }
    private static function orderedRequest(FixtureScope $scope, string $ref, string $key): array
    {
        return FixtureLeaseCandidateCodec::decode(FixtureCodec::json(self::request($scope,$ref,$key)));
    }
}
