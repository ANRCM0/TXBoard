<?php

namespace Tests\Unit\Domains\CredentialProvider;

use App\Domains\CredentialProvider\ProviderError;
use App\Domains\CredentialProvider\ProviderTransport;
use Tests\TestCase;

class ProviderTransportTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_configuration_exposes_only_bound_nonsecret_metadata(): void
    {
        $this->configure();
        $transport = new ProviderTransport();
        $configuration = $transport->configuration('test-provider');
        $this->assertSame(['provider_id', 'provider_instance_epoch', 'config_digest', 'profiles'], array_keys($configuration));
        $this->assertSame('1', $configuration['provider_instance_epoch']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $configuration['config_digest']);
        foreach ($this->files as $file) {
            $this->assertStringNotContainsString($file, json_encode($configuration));
        }
        $originalDigest = $configuration['config_digest'];
        config(['credential_provider.providers.test-provider.endpoint' => 'https://different.example:8443/operations']);
        $this->assertNotSame($originalDigest, $transport->configDigest('test-provider'));
    }

    public function test_registration_refuses_config_changes_before_network(): void
    {
        $this->configure();
        $transport = new ProviderTransport();
        $registration = $transport->configuration('test-provider');
        config(['credential_provider.providers.test-provider.endpoint' => 'https://different.example:8443/operations']);
        $request = ProviderCodecTest::issue();
        $request['deadline_at'] = time() + 30;
        $this->expectExceptionMessage('PROVIDER_REGISTRATION_MISMATCH');
        $transport->call($registration, $request);
    }

    public function test_metadata_uses_the_registered_identity_and_refuses_config_drift_before_network(): void
    {
        $this->configure();
        $transport = new ProviderTransport();
        $registration = $transport->configuration('test-provider');
        config(['credential_provider.providers.test-provider.endpoint' => 'https://different.example:8443/operations']);
        $this->expectExceptionMessage('PROVIDER_REGISTRATION_MISMATCH');
        $transport->metadata($registration, ProviderMetadataCodecTest::request());
    }

    public function test_http_endpoint_and_userinfo_are_refused(): void
    {
        $this->configure();
        foreach ([
            'http://localhost/operations',
            'https://user:secret@example.com/operations',
            'https://localhost/operations?endpoint=other',
            'https://localhost/redirect',
        ] as $endpoint) {
            config(['credential_provider.providers.test-provider.endpoint' => $endpoint]);
            try {
                (new ProviderTransport())->configuration('test-provider');
                $this->fail('Unsafe endpoint accepted.');
            } catch (ProviderError $error) {
                $this->assertSame('PROVIDER_CONFIG_INVALID', $error->getMessage());
            }
        }
    }

    public function test_client_key_must_match_certificate(): void
    {
        $this->configure();
        $otherKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($otherKey, $privateKey);
        config(['credential_provider.providers.test-provider.client_key' => $this->file($privateKey)]);
        $this->expectExceptionMessage('PROVIDER_CONFIG_INVALID');
        (new ProviderTransport())->configuration('test-provider');
    }

    public function test_moving_identical_tls_files_does_not_change_public_binding(): void
    {
        $this->configure();
        $transport = new ProviderTransport();
        $digest = $transport->configDigest('test-provider');
        $key = file_get_contents(config('credential_provider.providers.test-provider.client_key'));
        config(['credential_provider.providers.test-provider.client_key' => $this->file($key)]);
        $this->assertSame($digest, $transport->configDigest('test-provider'));
    }

    private function configure(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'provider-client-test'], $key);
        $certificate = openssl_csr_sign($csr, null, $key, 1);
        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $privateKeyPem);
        config(['credential_provider.providers.test-provider' => [
            'endpoint' => 'https://provider.example:8443/operations',
            'instance_epoch' => '1',
            'ca_file' => $this->file($certificatePem),
            'client_cert' => $this->file($certificatePem),
            'client_key' => $this->file($privateKeyPem),
            'profiles' => ['vless-uuid/v1', 'vmess-uuid/v1'],
        ]]);
    }

    private function file(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'tx-provider-');
        file_put_contents($file, $contents);
        chmod($file, 0600);
        $this->files[] = $file;
        return $file;
    }
}
