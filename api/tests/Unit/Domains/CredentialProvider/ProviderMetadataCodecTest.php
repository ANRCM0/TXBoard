<?php

namespace Tests\Unit\Domains\CredentialProvider;

use App\Domains\CredentialProvider\ProviderCodec;
use App\Domains\CredentialProvider\ProviderError;
use App\Domains\CredentialProvider\ProviderMetadataCodec;
use PHPUnit\Framework\TestCase;

final class ProviderMetadataCodecTest extends TestCase
{
    public function test_closed_metadata_roundtrip_has_only_nonsecret_commitments(): void
    {
        $request = self::request();
        ProviderMetadataCodec::validateRequest($request);
        $response = self::response($request);
        $this->assertSame($response, ProviderMetadataCodec::decodeResponse(json_encode($response), $request));
    }

    public function test_secret_fields_duplicates_and_wrong_binding_are_refused(): void
    {
        $request = self::request();
        $good = self::response($request);
        $unknown = $good;
        $unknown['credentials'][0]['uuid'] = '11111111-1111-4111-8111-111111111111';
        $wrong = $good;
        $wrong['credentials'][0]['credential_generation'] = '2';
        $numeric = $good;
        $numeric['credentials'][0]['access_subject_id'] = 1;
        $hex = $good;
        $hex['credentials'][0]['credential_sha256'] = str_repeat('A', 64);
        $partial = $good;
        $partial['credentials'] = [];
        $extra = $good;
        $extra['credentials'][] = $extra['credentials'][0];
        foreach ([$unknown, $wrong, $numeric, $hex, $partial, $extra] as $response) {
            $this->refused(fn () => ProviderMetadataCodec::decodeResponse(json_encode($response), $request), 'PROVIDER_INVALID_RESPONSE');
        }
        $json = ProviderCodec::canonical($good);
        $duplicate = str_replace('"request_id":', '"request\u005fid":"other","request_id":', $json);
        $this->refused(fn () => ProviderMetadataCodec::decodeResponse($duplicate, $request), 'PROVIDER_INVALID_RESPONSE');
        $this->refused(fn () => ProviderMetadataCodec::decodeResponse(str_repeat(' ', 1048577), $request), 'PROVIDER_INVALID_RESPONSE');
    }

    public function test_request_does_not_accept_caller_selected_endpoint_or_secret_and_is_bounded(): void
    {
        $request = self::request();
        foreach ([
            $request + ['endpoint' => 'https://caller.example/credential-metadata'],
            array_replace($request, ['subjects' => array_fill(0, 129, $request['subjects'][0])]),
            array_replace($request, ['provider_instance_epoch' => '01']),
        ] as $bad) {
            $this->refused(fn () => ProviderMetadataCodec::validateRequest($bad), 'PROVIDER_METADATA_INVALID');
        }
        $request['subjects'][0]['credential_generation'] = 1;
        $this->refused(fn () => ProviderMetadataCodec::validateRequest($request), 'PROVIDER_METADATA_INVALID');
    }

    public function test_empty_complete_metadata_is_allowed_and_cannot_gain_extra_rows(): void
    {
        $request = self::request();
        $request['subjects'] = [];
        ProviderMetadataCodec::validateRequest($request);
        $response = $request;
        unset($response['subjects']);
        $response['credentials'] = [];
        $this->assertSame($response, ProviderMetadataCodec::decodeResponse(json_encode($response), $request));
    }

    public static function request(): array
    {
        return ['contract_version' => 'credential-delivery/1', 'request_id' => 'metadata-test',
            'provider_id' => 'test-provider', 'provider_instance_epoch' => '1', 'subjects' => [[
                'installation_id' => '11111111-1111-4111-8111-111111111111', 'access_subject_id' => '1',
                'subscription_id' => '2', 'terms_id' => '3', 'provider_subject_ref' => 'subject_'.str_repeat('a', 32),
                'credential_ref' => 'credential_'.str_repeat('b', 32), 'credential_generation' => '1',
                'credential_profile_id' => 'vless-uuid/v1',
            ]]];
    }

    private static function response(array $request): array
    {
        unset($request['subjects']);
        return $request + ['credentials' => [[
            'access_subject_id' => '1', 'credential_ref' => 'credential_'.str_repeat('b', 32),
            'credential_generation' => '1', 'credential_sha256' => str_repeat('c', 64), 'credential_expires_at' => 2000000000,
        ]]];
    }

    private function refused(callable $operation, string $code): void
    {
        try {
            $operation();
            $this->fail('Malformed metadata accepted');
        } catch (ProviderError $error) {
            $this->assertSame($code, $error->codeName);
        }
    }
}
