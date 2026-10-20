<?php

namespace Tests\Unit\Domains\CredentialProvider;

use App\Domains\CredentialProvider\ProviderCapabilityCodec;
use App\Domains\CredentialProvider\ProviderError;
use Tests\TestCase;

class ProviderCapabilityCodecTest extends TestCase
{
    public function test_response_preserves_actual_flags_and_closed_identity(): void
    {
        $request = $this->request();
        $response = $this->response();
        $this->assertSame($response, ProviderCapabilityCodec::decodeResponse(json_encode($response), $request));
        $response['node_delivery'] = false;
        $response['subscription_render'] = false;
        $response['profiles'] = ['vless-uuid/v1'];
        $this->assertSame($response, ProviderCapabilityCodec::decodeResponse(json_encode($response), $request));
    }

    public function test_response_refuses_missing_unknown_identity_and_capability_drift(): void
    {
        $invalid = [];
        foreach (array_keys($this->response()) as $field) {
            $response = $this->response();
            unset($response[$field]);
            $invalid[] = json_encode($response);
        }
        foreach ([
            ['secret' => 'must-not-leak'],
            ['contract_version' => 'credential-capabilities/1'],
            ['request_id' => 'another-request'],
            ['provider_id' => 'another-provider'],
            ['provider_instance_epoch' => 1],
            ['store_version' => '2'],
            ['store_version' => 1],
            ['node_delivery' => 1],
            ['subscription_render' => null],
            ['operations' => ['issue', 'query', 'revoke']],
            ['operations' => ['issue', 'query', 'rotate', 'revoke']],
            ['profiles' => []],
            ['profiles' => ['vmess-uuid/v1', 'vless-uuid/v1']],
            ['profiles' => ['vless-uuid/v1', 'vless-uuid/v1']],
            ['profiles' => ['unknown/v1']],
        ] as $changes) {
            $invalid[] = json_encode(array_replace($this->response(), $changes));
        }
        $invalid[] = rtrim(json_encode($this->response()), '}').',"request_\u0069d":"duplicate"}';
        foreach ($invalid as $json) {
            try {
                ProviderCapabilityCodec::decodeResponse($json, $this->request());
                $this->fail('Invalid capability response was accepted.');
            } catch (ProviderError $error) {
                $this->assertSame('PROVIDER_CAPABILITIES_INVALID', $error->getMessage());
                $this->assertTrue($error->retryable);
                $this->assertNull($error->getPrevious());
            }
        }
    }

    public function test_optional_template_engine_is_explicit_and_requires_the_enabled_renderer(): void
    {
        $legacy = $this->response();
        $this->assertSame($legacy, ProviderCapabilityCodec::decodeResponse(json_encode($legacy), $this->request()));
        $current = $legacy + ['subscription_template_engine' => 'tx-template/v1'];
        $this->assertSame($current, ProviderCapabilityCodec::decodeResponse(json_encode($current), $this->request()));
        foreach ([array_replace($current, ['subscription_template_engine' => 'unknown/v1']),
            array_replace($current, ['subscription_render' => false]),
            array_replace($current, ['subscription_template_engine' => null])] as $invalid) {
            try {
                ProviderCapabilityCodec::decodeResponse(json_encode($invalid), $this->request());
                $this->fail('Unsupported or contradictory template engine was accepted.');
            } catch (ProviderError $error) {
                $this->assertSame('PROVIDER_CAPABILITIES_INVALID', $error->codeName);
            }
        }
    }

    private function request(): array
    {
        return ['contract_version' => ProviderCapabilityCodec::VERSION, 'request_id' => 'capability-1',
            'provider_id' => 'test-provider', 'provider_instance_epoch' => '1'];
    }

    private function response(): array
    {
        return $this->request() + ['store_version' => 2, 'operations' => ['issue', 'query', 'revoke', 'rotate'],
            'node_delivery' => true, 'subscription_render' => true, 'profiles' => ['vless-uuid/v1', 'vmess-uuid/v1']];
    }
}
