<?php

namespace Tests\Feature\Installation;

use App\Domains\CredentialProvider\NativeProviderTrust;
use App\Domains\CredentialProvider\RenderGrantCodec;
use App\Domains\CredentialProvider\ProviderTransport;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\InstallationAuthorityException;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeRuntimeTestCase;

final class NativeProviderTrustTest extends NativeRuntimeTestCase
{
    public function test_signer_rotation_is_explicit_persisted_and_replayable_without_changing_activation(): void
    {
        $this->activate();
        $activation = (array) DB::table('tx_native_runtime_activation')->first();
        $this->candidateKey();
        $service = app(NativeProviderTrust::class);
        $candidate = $service->preflight();
        $first = $service->rotate('trust-rotation', $candidate['previous_digest'], $candidate['configuration_digest']);
        $this->assertSame('1', $first['version']);
        $this->assertSame($first, $service->rotate('trust-rotation', $candidate['previous_digest'], $candidate['configuration_digest']));
        $this->assertSame($activation, (array) DB::table('tx_native_runtime_activation')->first());
        $this->assertSame(1, DB::table('tx_native_runtime_trust_transition')->count());
        DB::transaction(fn () => app(InstallationAuthority::class)->assertNativeMutation());
    }

    public function test_changing_key_files_or_environment_without_a_transition_fails_admission(): void
    {
        $this->activate();
        $this->candidateKey();
        $this->expectException(InstallationAuthorityException::class);
        $this->expectExceptionMessage('NATIVE_CONFIGURATION_CHANGED');
        DB::transaction(fn () => app(InstallationAuthority::class)->assertNativeMutation());
    }

    public function test_actual_tls_file_rotation_is_committed_by_digest_and_updates_registration(): void
    {
        $this->activate();
        $before = app(ProviderTransport::class)->configuration('runtime-provider');
        $this->candidateTransport();
        $candidate = app(ProviderTransport::class)->configuration('runtime-provider');
        $this->assertNotSame($before['config_digest'], $candidate['config_digest']);
        $service = app(NativeProviderTrust::class);
        $preflight = $service->preflight();
        $service->rotate('transport-files', $preflight['previous_digest'], $preflight['configuration_digest']);
        $this->assertSame($candidate['config_digest'],
            DB::table('tx_credential_provider_registration')->value('config_digest'));
        DB::transaction(fn () => app(InstallationAuthority::class)->assertNativeRead());
        // The persisted configuration includes pins, not PEM files or private material.
        $evidence = DB::table('tx_native_runtime_trust_transition')->value('configuration_snapshot');
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $evidence);
        $this->assertStringNotContainsString('BEGIN CERTIFICATE', $evidence);
    }

    public function test_actual_file_change_is_not_automatically_authorized(): void
    {
        $this->activate();
        $this->candidateTransport();
        $this->expectExceptionMessage('INSTALLATION_AUTHORITY_NATIVE_CONFIGURATION_CHANGED');
        DB::transaction(fn () => app(InstallationAuthority::class)->assertNativeRead());
    }

    public function test_a_changed_recipient_cannot_be_smuggled_into_a_trust_rotation(): void
    {
        $this->activate();
        $this->candidateKey();
        config(['native_provider_node.recipients.'.$this->node->id.'.recipient_sha256' => str_repeat('e', 64)]);
        $this->expectExceptionMessage('NATIVE_PROVIDER_TRUST_SCOPE_CHANGED');
        app(NativeProviderTrust::class)->preflight();
    }

    public function test_same_epoch_replacement_and_unrelated_recipient_changes_are_refused(): void
    {
        $this->activate();
        $this->candidateKey();
        config(['native_node_authorization.key_epoch' => '1']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('SIGNER_VERSION_INVALID');
        app(NativeProviderTrust::class)->preflight();
    }

    public function test_transport_rotation_cannot_change_provider_store_identity(): void
    {
        $this->activate();
        config(['credential_provider.providers.runtime-provider.instance_epoch' => '2']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('SCOPE_CHANGED');
        app(NativeProviderTrust::class)->preflight();
    }

    public function test_candidate_change_during_probe_does_not_commit_any_trust(): void
    {
        $this->activate();
        $this->candidateKey();
        $this->duringCapabilityProbe = static fn () => config(['native_node_authorization.key_id' => 'another-key']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('CONFIGURATION_CHANGED');
        try {
            app(NativeProviderTrust::class)->preflight();
        } finally {
            $this->assertSame(0, DB::table('tx_native_runtime_trust_transition')->count());
        }
    }

    public function test_tampered_transition_is_not_an_alternate_configuration_authority(): void
    {
        $this->activate();
        $this->candidateKey();
        $service = app(NativeProviderTrust::class);
        $candidate = $service->preflight();
        $service->rotate('trust-rotation', $candidate['previous_digest'], $candidate['configuration_digest']);
        DB::table('tx_native_runtime_trust_transition')->update(['previous_digest' => str_repeat('a', 64)]);
        $activation = json_decode(DB::table('tx_native_runtime_activation')->value('configuration_snapshot'),
            true, 32, JSON_THROW_ON_ERROR);
        try {
            DB::transaction(fn () => $service->currentLocked(self::INSTALLATION, $activation['configuration']));
            $this->fail('Tampered trust transition was accepted');
        } catch (DomainException $error) {
            $this->assertSame('NATIVE_PROVIDER_TRUST_EVIDENCE_INVALID', $error->getMessage());
        }
        // Installation admission deliberately sanitizes dependency failures.
        $this->expectException(InstallationAuthorityException::class);
        $this->expectExceptionMessage('INSTALLATION_AUTHORITY_STORAGE_FAILED');
        DB::transaction(fn () => app(InstallationAuthority::class)->assertNativeMutation());
    }

    private function candidateKey(): void
    {
        config(['native_node_authorization.key_id' => 'runtime-key-2', 'native_node_authorization.key_epoch' => '2',
            'native_node_authorization.private_seed' => RenderGrantCodec::base64url(str_repeat('s', 32))]);
    }

    private function candidateTransport(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $request = openssl_csr_new(['commonName' => 'rotated-runtime-client'], $key);
        $certificate = openssl_csr_sign($request, null, $key, 1);
        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $privatePem);
        $certificateFile = $this->file($certificatePem);
        config(['credential_provider.providers.runtime-provider.ca_file' => $certificateFile,
            'credential_provider.providers.runtime-provider.client_cert' => $certificateFile,
            'credential_provider.providers.runtime-provider.client_key' => $this->file($privatePem)]);
    }
}
