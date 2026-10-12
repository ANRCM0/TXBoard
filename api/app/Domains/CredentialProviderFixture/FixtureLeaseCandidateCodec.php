<?php

namespace App\Domains\CredentialProviderFixture;

use App\Domains\InternalReadOnly\StrictJson;
use DomainException;

/** Fixed nonsecret schemas; reused bounded JSON scanner rejects duplicate keys. */
final class FixtureLeaseCandidateCodec
{
    public const INPUT = ['expected_subject_version', 'expected_terms_id', 'source_id', 'usage_period_id', 'tariff_revision_id'];
    public const POLICY = 'local-synthetic-lease-candidate-60s/v1';
    public static function exact(array $value, array $keys): void
    {
        $actual = array_keys($value); sort($actual); sort($keys);
        if ($actual !== $keys) { throw new DomainException('LEASE_EVIDENCE_INVALID'); }
    }
    public static function request(FixtureScope $scope, string $ref, string $key, array $input): array
    {
        self::exact($input, self::INPUT); foreach ($input as $value) { FixtureCodec::decimal($value); }
        if (!preg_match('/\Aop_[a-f0-9]{32}\z/', $ref) || !preg_match('/\Afixture_[a-z0-9_-]{1,96}\z/', $key)) {
            throw new DomainException('PROVIDER_INPUT_INVALID');
        }
        return ['provider_id' => $scope->providerId, 'access_subject_id' => $scope->subjectId,
            'actor_ref' => $scope->actorRef, 'authorization_ref' => $scope->authorizationRef,
            'issue_operation_ref' => $ref, 'business_key' => $key, 'input' => $input];
    }
    public static function decode(mixed $raw): array
    {
        try {
            if (!is_string($raw) || strlen($raw) > 16384) { throw new DomainException(); }
            StrictJson::object($raw);
            return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) { throw new DomainException('LEASE_EVIDENCE_INVALID'); }
    }
    public static function hash(mixed $hash): string
    {
        if (!is_string($hash) || !preg_match('/\A[a-f0-9]{64}\z/', $hash)) { throw new DomainException('LEASE_EVIDENCE_INVALID'); }
        return $hash;
    }
    public static function issue(object $row, object $registration, FixtureScope $scope): array
    {
        if ($row->operation !== 'issue' || $row->status !== 'succeeded' || $row->last_error_code !== null
            || (string) $row->registration_id !== (string) $registration->id
            || (string) $row->instance_epoch !== (string) $registration->instance_epoch
            || (string) $row->access_subject_id !== $scope->subjectId
            || !preg_match('/\Aop_[a-f0-9]{32}\z/', $row->operation_ref)) { throw new DomainException('LEASE_ISSUE_INVALID'); }
        $input = self::decode($row->input_snapshot); $result = self::decode($row->result_snapshot);
        self::exact($input, ['expected_subject_version', 'credential_profile_id', 'not_before_at', 'credential_expires_at',
            'provider_id', 'provider_instance_epoch', 'access_subject_id', 'actor_ref', 'authorization_ref', 'subscription_id', 'terms_id']);
        self::exact($result, ['credential_ref', 'provider_subject_ref', 'credential_generation', 'issued_at', 'credential_expires_at']);
        foreach (['expected_subject_version', 'provider_instance_epoch', 'access_subject_id', 'subscription_id', 'terms_id'] as $key) { FixtureCodec::decimal($input[$key]); }
        foreach (['not_before_at', 'credential_expires_at'] as $key) { FixtureCodec::time($input[$key]); }
        FixtureCodec::time($result['issued_at']); FixtureCodec::time($result['credential_expires_at']);
        if (!hash_equals(self::hash($row->input_hash), FixtureCodec::hash($input))
            || !hash_equals(self::hash($row->result_hash), FixtureCodec::hash($result))
            || $input['provider_id'] !== $scope->providerId || $input['provider_id'] !== $registration->provider_id
            || $input['provider_instance_epoch'] !== (string) $registration->instance_epoch
            || $input['access_subject_id'] !== $scope->subjectId || $input['actor_ref'] !== $scope->actorRef
            || $input['authorization_ref'] !== $scope->authorizationRef || $input['credential_profile_id'] !== 'synthetic-v1'
            || $result['credential_generation'] !== '1' || $result['credential_expires_at'] !== $input['credential_expires_at']
            || $input['credential_expires_at'] <= $input['not_before_at']
            || $input['credential_expires_at'] - $input['not_before_at'] > 3600
            || $result['issued_at'] > $input['credential_expires_at']
            || !is_string($result['credential_ref']) || !preg_match('/\Acr_[a-f0-9]{32}\z/', $result['credential_ref'])
            || !is_string($result['provider_subject_ref']) || !preg_match('/\Aps_[a-f0-9]{32}\z/', $result['provider_subject_ref'])) {
            throw new DomainException('LEASE_ISSUE_INVALID');
        }
        return [$input, $result];
    }
    public static function retained(object $row, FixtureScope $scope): array
    {
        try { return self::retainedExact($row, $scope); }
        catch (DomainException $error) { throw $error; }
        catch (\Throwable) { throw new DomainException('LEASE_EVIDENCE_INVALID'); }
    }
    private static function retainedExact(object $row, FixtureScope $scope): array
    {
        $request = self::decode($row->request_snapshot); self::exact($request, ['provider_id', 'access_subject_id', 'actor_ref', 'authorization_ref', 'issue_operation_ref', 'business_key', 'input']);
        $expected = self::request($scope, $request['issue_operation_ref'], $request['business_key'], $request['input']);
        if (FixtureCodec::hash($request) !== FixtureCodec::hash($expected) || !hash_equals(self::hash($row->request_hash), FixtureCodec::hash($request))
            || $row->provider_id !== $scope->providerId || (string) $row->access_subject_id !== $scope->subjectId
            || $row->business_key !== $request['business_key']) { throw new DomainException('LEASE_SCOPE_OR_EVIDENCE_INVALID'); }
        $e = self::decode($row->evidence_snapshot);
        self::exact($e, ['binding_id', 'registration_id', 'provider_instance_epoch', 'issue_operation_id', 'issue_input', 'issue_result',
            'issue_input_hash', 'issue_result_hash', 'source_id', 'node_id', 'source_epoch', 'core_instance_id', 'receipt_generation',
            'user_id', 'subscription_id', 'terms_id', 'usage_period_id', 'tariff_revision_id', 'subject_core_ref', 'auth_version',
            'revocation_version', 'terms_hash', 'period_quota_bytes', 'tariff_hash', 'boundary', 'policy_revision', 'policy_digest',
            'prepared_at', 'candidate_expires_at']);
        if (!hash_equals(self::hash($row->evidence_hash), FixtureCodec::hash($e))) { throw new DomainException('LEASE_EVIDENCE_INVALID'); }
        foreach (['binding_id', 'registration_id', 'issue_operation_id', 'source_id', 'subscription_id', 'terms_id', 'usage_period_id', 'tariff_revision_id'] as $key) {
            FixtureCodec::decimal($e[$key]); if ($e[$key] !== (string) $row->$key) { throw new DomainException('LEASE_EVIDENCE_INVALID'); }
        }
        foreach (['node_id', 'source_epoch', 'user_id', 'auth_version', 'provider_instance_epoch', 'receipt_generation'] as $key) { FixtureCodec::decimal($e[$key]); }
        self::number($e['revocation_version']); self::number($e['period_quota_bytes']);
        foreach (['terms_hash', 'tariff_hash', 'policy_digest'] as $key) { self::hash($e[$key]); }
        $fake = (object) ['id' => $e['issue_operation_id'], 'operation' => 'issue', 'status' => 'succeeded', 'last_error_code' => null,
            'registration_id' => $e['registration_id'], 'instance_epoch' => $e['provider_instance_epoch'], 'access_subject_id' => $scope->subjectId,
            'operation_ref' => $request['issue_operation_ref'], 'input_snapshot' => FixtureCodec::json($e['issue_input']),
            'result_snapshot' => FixtureCodec::json($e['issue_result']), 'input_hash' => $e['issue_input_hash'], 'result_hash' => $e['issue_result_hash']];
        [$input] = self::issue($fake, (object) ['id' => $e['registration_id'], 'instance_epoch' => $e['provider_instance_epoch'], 'provider_id' => $scope->providerId], $scope);
        self::exact($e['boundary'], ['boundary_revision', 'period_creation_terms_id', 'terms_effective_from', 'terms_effective_until', 'terms_ending_transition_id']);
        FixtureCodec::decimal($e['boundary']['period_creation_terms_id']); FixtureCodec::time($e['boundary']['terms_effective_from']);
        if ($e['boundary']['terms_effective_until'] !== null) { FixtureCodec::time($e['boundary']['terms_effective_until']); }
        if ($e['boundary']['terms_ending_transition_id'] !== null) { FixtureCodec::decimal($e['boundary']['terms_ending_transition_id']); }
        FixtureCodec::time($e['prepared_at']); FixtureCodec::time($e['candidate_expires_at']);
        if ($e['prepared_at'] !== (int) $row->prepared_at || $e['candidate_expires_at'] !== (int) $row->candidate_expires_at
            || $e['candidate_expires_at'] <= $e['prepared_at'] || $e['candidate_expires_at'] > $e['prepared_at'] + 60
            || $e['candidate_expires_at'] > $e['issue_result']['credential_expires_at']
            || $e['prepared_at'] < $e['issue_input']['not_before_at']
            || $e['prepared_at'] < $e['boundary']['terms_effective_from']
            || ($e['boundary']['terms_effective_until'] !== null && $e['candidate_expires_at'] > $e['boundary']['terms_effective_until'])
            || $e['boundary']['boundary_revision'] !== 'terms-period-half-open-v1'
            || $input['subscription_id'] !== $e['subscription_id'] || $input['terms_id'] !== $e['terms_id']
            || $input['expected_subject_version'] !== $e['auth_version']
            || $request['input']['expected_subject_version'] !== $e['auth_version']
            || $request['input']['expected_terms_id'] !== $e['terms_id']
            || $e['policy_revision'] !== self::POLICY || $e['policy_digest'] !== FixtureCodec::hash(['revision' => self::POLICY, 'ttl_seconds' => 60])
            || !is_string($e['subject_core_ref']) || strlen($e['subject_core_ref']) > 128
            || !is_string($e['core_instance_id']) || !preg_match('/\A[A-Za-z0-9:_-]{8,80}\z/', $e['core_instance_id'])
            || !preg_match('/\Alc_[a-f0-9]{32}\z/', $row->candidate_ref)
            || !in_array($row->state, ['prepared', 'expired', 'refused'], true)) { throw new DomainException('LEASE_EVIDENCE_INVALID'); }
        self::number((string) $row->last_observed_at); FixtureCodec::decimal((string) $row->row_version);
        if ((int) $row->last_observed_at < $e['prepared_at'] || ($row->state === 'prepared' && $row->reason_code !== null)
            || ($row->state !== 'prepared' && (!is_string($row->reason_code) || !preg_match('/\A[A-Z][A-Z0-9_]{2,79}\z/', $row->reason_code)))) { throw new DomainException('LEASE_EVIDENCE_INVALID'); }
        foreach (['source_id', 'usage_period_id', 'tariff_revision_id'] as $key) { if ($request['input'][$key] !== $e[$key]) { throw new DomainException('LEASE_EVIDENCE_INVALID'); } }
        return $e;
    }
    public static function number(mixed $value): string
    {
        if ($value === '0') { return '0'; } return FixtureCodec::decimal($value);
    }
    public static function dbTime(mixed $value): int
    {
        $string = self::number((string) $value);
        if (strlen($string) > 16 || (int) $string > 9007199254740991) { throw new DomainException('LEASE_TIME_INVALID'); }
        return (int) $string;
    }
}
