<?php

namespace Tests\Feature\Subscription;

use App\Domains\CredentialProvider\ProviderCodec;
use App\Domains\CredentialProvider\ProviderError;
use App\Domains\CredentialProvider\ProviderTransport;
use Tests\TestCase;

final class NativeProviderTransportInteropTest extends TestCase
{
    protected function setUp(): void
    {
        if (!getenv('CORE_PROVIDER_INTEROP_ENDPOINT')) {
            $this->markTestSkipped('Requires the dedicated real mTLS Provider process.');
        }
        parent::setUp();
        config(['credential_provider.providers.primary-provider' => [
            'endpoint' => getenv('CORE_PROVIDER_INTEROP_ENDPOINT'),
            'instance_epoch' => '1', 'profiles' => ['vless-uuid/v1', 'vmess-uuid/v1'],
            'ca_file' => getenv('CORE_PROVIDER_INTEROP_CA'),
            'client_cert' => getenv('CORE_PROVIDER_INTEROP_CERT'),
            'client_key' => getenv('CORE_PROVIDER_INTEROP_KEY'),
        ]]);
    }

    public function test_unregistered_client_certificate_is_rejected_even_when_signed_by_trusted_ca(): void
    {
        config(['credential_provider.providers.primary-provider.client_cert' => getenv('CORE_PROVIDER_INTEROP_ALT_CERT'),
            'credential_provider.providers.primary-provider.client_key' => getenv('CORE_PROVIDER_INTEROP_ALT_KEY')]);
        $this->assertTransportRejected();
    }

    public function test_untrusted_server_certificate_is_rejected_without_body_or_tls_details(): void
    {
        config(['credential_provider.providers.primary-provider.ca_file' => getenv('CORE_PROVIDER_INTEROP_BAD_CA')]);
        $this->assertTransportRejected();
    }

    public function test_verified_client_can_query_a_missing_operation_without_issuing_a_credential(): void
    {
        $transport = new ProviderTransport();
        $response = $transport->call($transport->configuration('primary-provider'), $this->query());
        $this->assertSame('unknown', $response['status']);
        $this->assertSame('OPERATION_UNKNOWN', $response['error']['code']);
        $this->assertNull($response['result']);
    }

    private function assertTransportRejected(): void
    {
        $transport = new ProviderTransport();
        try {
            $transport->call($transport->configuration('primary-provider'), $this->query());
            $this->fail('Untrusted transport must reject');
        } catch (ProviderError $error) {
            $this->assertContains($error->codeName, ['PROVIDER_TRANSPORT_FAILED', 'PROVIDER_HTTP_FAILED']);
            $this->assertSame($error->codeName, $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }

    private function query(): array
    {
        $operation = 'cp_'.bin2hex(random_bytes(16));
        $input = ['operation_id' => $operation];
        return [
            'contract_version' => 'credential-provider/1', 'operation' => 'query', 'request_id' => 'tls-query',
            'operation_id' => $operation, 'provider_id' => 'primary-provider', 'provider_instance_epoch' => '1',
            'idempotency_key' => 'query', 'input_hash' => ProviderCodec::inputHash('query', 'primary-provider', '1', $input),
            'deadline_at' => time() + 10, 'input' => $input,
        ];
    }
}
