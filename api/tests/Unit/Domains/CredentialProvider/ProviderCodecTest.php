<?php

namespace Tests\Unit\Domains\CredentialProvider;

use App\Domains\CredentialProvider\ProviderCodec;
use App\Domains\CredentialProvider\ProviderError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProviderCodecTest extends TestCase
{
    public function test_canonical_hash_preserves_scalar_types_and_sorts_nested_objects(): void
    {
        $this->assertSame(
            '{"a":{"a":"1","b":1},"profiles":["vmess-uuid/v1","vless-uuid/v1"],"z":true}',
            ProviderCodec::canonical(['z' => true, 'profiles' => ['vmess-uuid/v1', 'vless-uuid/v1'], 'a' => ['b' => 1, 'a' => '1']]),
        );
        $request = self::issue();
        ProviderCodec::validateRequest($request, 1700000000);
        $this->assertSame($request['input_hash'], ProviderCodec::inputHash('issue', 'test-provider', '1', $request['input']));
        $reordered = array_reverse($request['input'], true);
        $this->assertSame($request['input_hash'], ProviderCodec::inputHash('issue', 'test-provider', '1', $reordered));
    }

    #[DataProvider('invalidRequests')]
    public function test_rejects_closed_wire_and_invalid_scalars(string $path, mixed $value): void
    {
        $request = self::issue();
        if (str_starts_with($path, 'input.')) {
            $request['input'][substr($path, 6)] = $value;
            $request['input_hash'] = ProviderCodec::inputHash('issue', 'test-provider', '1', $request['input']);
        } else {
            $request[$path] = $value;
        }
        $this->expectException(ProviderError::class);
        $this->expectExceptionMessage('PROVIDER_INVALID_REQUEST');
        ProviderCodec::validateRequest($request, 1700000000);
    }

    public static function invalidRequests(): array
    {
        return [
            'unknown top-level secret' => ['private_key', 'do-not-log'],
            'expired deadline' => ['deadline_at', 1700000000],
            'overlong deadline' => ['deadline_at', 1700000031],
            'string deadline' => ['deadline_at', '1700000010'],
            'float deadline' => ['deadline_at', 1700000010.0],
            'invalid input hash' => ['input_hash', str_repeat('a', 64)],
            'epoch overflow' => ['provider_instance_epoch', '9223372036854775808'],
            'numeric epoch' => ['provider_instance_epoch', 1],
            'non-ascii request' => ['request_id', "request-\xc3\xa9"],
            'request newline' => ['request_id', "request\n"],
            'leading zero' => ['input.access_subject_id', '01'],
            'numeric identity' => ['input.access_subject_id', 1],
            'negative identity' => ['input.terms_id', '-1'],
            'unknown profile' => ['input.credential_profile_id', 'trojan-password/v1'],
            'unknown input secret' => ['input.credential', 'do-not-log'],
            'uppercase uuid' => ['input.installation_id', 'AAAAAAAA-0000-0000-0000-000000000000'],
            'zero lifetime' => ['input.credential_expires_at', 1700000000],
            'overlong lifetime' => ['input.credential_expires_at', 1702678401],
        ];
    }

    public function test_issue_response_binds_identity_profile_and_lifetime(): void
    {
        $request = self::issue();
        $response = self::response($request);
        $this->assertSame($response, ProviderCodec::decodeResponse(json_encode($response), $request));
        $response['result']['credential_expires_at']++;
        $this->expectExceptionMessage('PROVIDER_INVALID_RESPONSE');
        ProviderCodec::decodeResponse(json_encode($response), $request);
    }

    public function test_unavailable_store_is_unknown_and_does_not_mean_missing_operation(): void
    {
        $request = self::issue();
        $response = array_replace(self::response($request), [
            'status' => 'unknown', 'result' => null,
            'error' => ['code' => 'STORE_UNAVAILABLE', 'retryable' => true],
        ]);
        $this->assertSame($response, ProviderCodec::decodeResponse(json_encode($response), $request));
        $response['error']['code'] = 'OPERATION_UNKNOWN';
        $this->expectExceptionMessage('PROVIDER_INVALID_RESPONSE');
        ProviderCodec::decodeResponse(json_encode($response), $request);
    }

    #[DataProvider('invalidResponseChanges')]
    public function test_rejects_response_drift_or_secret_fields(string $path, mixed $value): void
    {
        $request = self::issue();
        $response = self::response($request);
        if (str_starts_with($path, 'result.')) {
            $response['result'][substr($path, 7)] = $value;
        } else {
            $response[$path] = $value;
        }
        $this->expectExceptionMessage('PROVIDER_INVALID_RESPONSE');
        ProviderCodec::decodeResponse(json_encode($response), $request);
    }

    public static function invalidResponseChanges(): array
    {
        return [
            'provider drift' => ['provider_id', 'other-provider'],
            'epoch drift' => ['provider_instance_epoch', '2'],
            'request drift' => ['request_id', 'other-request'],
            'operation drift' => ['operation_id', 'cp_'.str_repeat('f', 32)],
            'top-level secret' => ['secret', 'do-not-log'],
            'material in result' => ['result.credential_material', 'do-not-log'],
            'wrong subject' => ['result.access_subject_id', '2'],
            'wrong profile' => ['result.credential_profile_id', 'vmess-uuid/v1'],
            'numeric generation' => ['result.credential_generation', 1],
            'invalid generation' => ['result.credential_generation', '2'],
            'string timestamp' => ['result.issued_at', '1700000000'],
            'negative timestamp' => ['result.issued_at', -1],
            'null result' => ['result', null],
        ];
    }

    public function test_rejects_duplicate_keys_even_when_escaped_and_nested(): void
    {
        $request = self::issue();
        $json = json_encode(self::response($request));
        $json = str_replace('"issued_at":1700000000', '"issued_at":1700000000,"issued_\u0061t":1700000000', $json);
        $this->expectExceptionMessage('PROVIDER_INVALID_RESPONSE');
        ProviderCodec::decodeResponse($json, $request);
    }

    #[DataProvider('noncanonicalNumbers')]
    public function test_rejects_noncanonical_integer_json(string $number): void
    {
        $request = self::issue();
        $json = str_replace('"issued_at":1700000000', '"issued_at":'.$number, json_encode(self::response($request)));
        $this->expectExceptionMessage('PROVIDER_INVALID_RESPONSE');
        ProviderCodec::decodeResponse($json, $request);
    }

    public static function noncanonicalNumbers(): array
    {
        return [['1700000000.0'], ['1.7e9'], ['-0'], ['9223372036854775808']];
    }

    public function test_query_receipt_must_match_original_operation_hash_and_result(): void
    {
        $original = self::issue();
        $query = $original;
        $query['operation'] = 'query';
        $query['input'] = ['operation_id' => $query['operation_id']];
        $query['input_hash'] = ProviderCodec::inputHash('query', 'test-provider', '1', $query['input']);
        ProviderCodec::validateRequest($query, 1700000000);
        $response = self::response($query);
        $response['result'] = [
            'operation' => 'issue',
            'input_hash' => $original['input_hash'],
            'result' => self::response($original)['result'],
        ];
        $this->assertSame($response, ProviderCodec::decodeResponse(json_encode($response), $query, $original));
        $response['result']['input_hash'] = str_repeat('f', 64);
        $this->expectExceptionMessage('PROVIDER_INVALID_RESPONSE');
        ProviderCodec::decodeResponse(json_encode($response), $query, $original);
    }

    public function test_unknown_response_is_limited_to_missing_query_operation(): void
    {
        $request = self::issue();
        $request['operation'] = 'query';
        $request['input'] = ['operation_id' => $request['operation_id']];
        $request['input_hash'] = ProviderCodec::inputHash('query', 'test-provider', '1', $request['input']);
        $response = self::response($request);
        $response['status'] = 'unknown';
        $response['result'] = null;
        $response['error'] = ['code' => 'OPERATION_UNKNOWN', 'retryable' => true];
        $this->assertSame($response, ProviderCodec::decodeResponse(json_encode($response), $request));
        $response['error']['retryable'] = false;
        $this->expectExceptionMessage('PROVIDER_INVALID_RESPONSE');
        ProviderCodec::decodeResponse(json_encode($response), $request);
    }

    public function test_revoke_receipt_can_confirm_a_higher_fact_but_cannot_downgrade(): void
    {
        $request = self::issue();
        $request['operation'] = 'revoke';
        $request['input'] = [
            'installation_id' => $request['input']['installation_id'],
            'access_subject_id' => '1',
            'provider_subject_ref' => $request['input']['provider_subject_ref'],
            'revocation_fact_version' => '2',
            'effective_at' => 1700000010,
            'reason_code' => 'MANUAL',
        ];
        $request['input_hash'] = ProviderCodec::inputHash('revoke', 'test-provider', '1', $request['input']);
        ProviderCodec::validateRequest($request, 1700000000);
        $response = self::response($request);
        $response['result'] = [
            'access_subject_id' => '1',
            'provider_subject_ref' => $request['input']['provider_subject_ref'],
            'accepted_revocation_fact_version' => '3',
            'provider_revoked_at' => 1700000011,
        ];
        $this->assertSame($response, ProviderCodec::decodeResponse(json_encode($response), $request));
        $response['result']['accepted_revocation_fact_version'] = '1';
        $this->expectExceptionMessage('PROVIDER_INVALID_RESPONSE');
        ProviderCodec::decodeResponse(json_encode($response), $request);
    }

    public function test_rejects_oversized_body_without_including_it_in_error(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            ProviderCodec::decodeResponse(str_repeat('secret', 12000), self::issue());
            $this->fail('Oversized response accepted.');
        } catch (ProviderError $error) {
            $this->assertSame('PROVIDER_INVALID_RESPONSE', $error->getMessage());
            $this->assertTrue($error->retryable);
            $this->assertNull($error->getPrevious());
            $this->assertStringNotContainsString('secretsecret', print_r($error->getTrace(), true));
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public static function issue(): array
    {
        $input = [
            'installation_id' => '00000000-0000-0000-0000-000000000001',
            'access_subject_id' => '1',
            'subscription_id' => '2',
            'terms_id' => '3',
            'provider_subject_ref' => 'subject_'.str_repeat('a', 32),
            'expected_subject_version' => '1',
            'credential_profile_id' => 'vless-uuid/v1',
            'not_before_at' => 1700000000,
            'credential_expires_at' => 1700003600,
        ];
        return [
            'contract_version' => 'credential-provider/1',
            'operation' => 'issue',
            'request_id' => 'request-1',
            'operation_id' => 'cp_'.str_repeat('b', 32),
            'provider_id' => 'test-provider',
            'provider_instance_epoch' => '1',
            'idempotency_key' => 'business-key-1',
            'input_hash' => ProviderCodec::inputHash('issue', 'test-provider', '1', $input),
            'deadline_at' => 1700000030,
            'input' => $input,
        ];
    }

    private static function response(array $request): array
    {
        return [
            'contract_version' => 'credential-provider/1',
            'request_id' => $request['request_id'],
            'operation_id' => $request['operation_id'],
            'provider_id' => $request['provider_id'],
            'provider_instance_epoch' => $request['provider_instance_epoch'],
            'status' => 'succeeded',
            'result' => [
                'access_subject_id' => '1',
                'provider_subject_ref' => 'subject_'.str_repeat('a', 32),
                'credential_profile_id' => 'vless-uuid/v1',
                'credential_ref' => 'credential_'.str_repeat('c', 32),
                'credential_generation' => '1',
                'issued_at' => 1700000000,
                'not_before_at' => 1700000000,
                'credential_expires_at' => 1700003600,
            ],
            'error' => null,
        ];
    }
}
