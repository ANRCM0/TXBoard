<?php

namespace App\Domains\CredentialProvider;

use Throwable;

class ProviderTransport
{
    public function configuration(string $providerId): array
    {
        $configuration = $this->operatorConfiguration($providerId);
        return [
            'provider_id' => $providerId,
            'provider_instance_epoch' => $configuration['instance_epoch'],
            'config_digest' => $configuration['config_digest'],
            'profiles' => $configuration['profiles'],
        ];
    }

    public function configDigest(string $providerId): string
    {
        return $this->configuration($providerId)['config_digest'];
    }

    public function call(#[\SensitiveParameter] array $registration, #[\SensitiveParameter] array $request): array
    {
        ProviderCodec::validateRequest($request);
        $providerId = $registration['provider_id'] ?? null;
        if (!is_string($providerId) || $providerId !== $request['provider_id']) {
            throw new ProviderError('PROVIDER_REGISTRATION_MISMATCH');
        }
        $configuration = $this->operatorConfiguration($providerId);
        if (($registration['provider_instance_epoch'] ?? null) !== $configuration['instance_epoch']
            || $request['provider_instance_epoch'] !== $configuration['instance_epoch']
            || !is_string($registration['config_digest'] ?? null)
            || !hash_equals($configuration['config_digest'], $registration['config_digest'])) {
            throw new ProviderError('PROVIDER_REGISTRATION_MISMATCH');
        }
        if ($request['operation'] === 'issue'
            && !in_array($request['input']['credential_profile_id'], $configuration['profiles'], true)) {
            throw new ProviderError('PROVIDER_PROFILE_UNSUPPORTED');
        }
        $response = $this->exchange($configuration, $request, ProviderCodec::MAX_BYTES, $request['deadline_at'] - time());
        return ProviderCodec::decodeResponse($response, $request, $registration['original_request'] ?? null);
    }

    public function metadata(array $registration, array $request): array
    {
        ProviderMetadataCodec::validateRequest($request);
        $providerId = $registration['provider_id'] ?? null;
        if (!is_string($providerId) || $providerId !== $request['provider_id']) {
            throw new ProviderError('PROVIDER_REGISTRATION_MISMATCH');
        }
        $configuration = $this->operatorConfiguration($providerId);
        if (($registration['provider_instance_epoch'] ?? null) !== $configuration['instance_epoch']
            || $request['provider_instance_epoch'] !== $configuration['instance_epoch']
            || !is_string($registration['config_digest'] ?? null)
            || !hash_equals($configuration['config_digest'], $registration['config_digest'])) {
            throw new ProviderError('PROVIDER_REGISTRATION_MISMATCH');
        }
        foreach ($request['subjects'] as $subject) {
            if (!in_array($subject['credential_profile_id'], $configuration['profiles'], true)) {
                throw new ProviderError('PROVIDER_PROFILE_UNSUPPORTED');
            }
        }
        $configuration['endpoint'] = substr($configuration['endpoint'], 0, -strlen('/operations')).'/credential-metadata';
        return ProviderMetadataCodec::decodeResponse(
            $this->exchange($configuration, $request, ProviderMetadataCodec::MAX_BYTES, 25), $request);
    }

    private function exchange(array $configuration, array $request, int $maxBytes, int $remaining): string
    {
        if (!extension_loaded('curl') || !defined('CURL_SSLVERSION_TLSv1_3')) {
            throw new ProviderError('PROVIDER_TRANSPORT_UNAVAILABLE');
        }
        if ($remaining <= 0 || $remaining > 30) {
            throw new ProviderError('PROVIDER_DEADLINE_EXCEEDED', true);
        }
        $response = '';
        $responseBytes = 0;
        $headerBytes = 0;
        $tooLarge = false;
        $handle = curl_init();
        if ($handle === false) {
            throw new ProviderError('PROVIDER_TRANSPORT_UNAVAILABLE');
        }
        try {
            curl_setopt_array($handle, [
                CURLOPT_URL => $configuration['endpoint'],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => ProviderCodec::canonical($request),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Expect:'],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROXY => '',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_3,
                CURLOPT_CAINFO => $configuration['ca_file'],
                CURLOPT_SSLCERT => $configuration['client_cert'],
                CURLOPT_SSLKEY => $configuration['client_key'],
                CURLOPT_CONNECTTIMEOUT_MS => min(5000, $remaining * 1000),
                CURLOPT_TIMEOUT_MS => $remaining * 1000,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $chunk) use (&$headerBytes, &$tooLarge, $maxBytes): int {
                    $headerBytes += strlen($chunk);
                    if ($headerBytes > $maxBytes) {
                        $tooLarge = true;
                        return 0;
                    }
                    return strlen($chunk);
                },
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$response, &$responseBytes, &$tooLarge, $maxBytes): int {
                    $responseBytes += strlen($chunk);
                    if ($responseBytes > $maxBytes) {
                        $tooLarge = true;
                        return 0;
                    }
                    $response .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $completed = curl_exec($handle);
            if ($tooLarge) {
                throw new ProviderError('PROVIDER_RESPONSE_TOO_LARGE', true);
            }
            if ($completed === false) {
                throw new ProviderError('PROVIDER_TRANSPORT_FAILED', true);
            }
            if (curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new ProviderError('PROVIDER_HTTP_FAILED', true);
            }
            $contentType = curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
            if (!is_string($contentType) || preg_match('/^application\/json(?:\s*;|$)/i', $contentType) !== 1) {
                throw new ProviderError('PROVIDER_INVALID_RESPONSE', true);
            }
            return $response;
        } catch (ProviderError $error) {
            throw $error;
        } catch (Throwable) {
            throw new ProviderError('PROVIDER_TRANSPORT_FAILED', true);
        } finally {
            curl_close($handle);
        }
    }

    private function operatorConfiguration(string $providerId): array
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $providerId) !== 1) {
            throw new ProviderError('PROVIDER_CONFIG_INVALID');
        }
        $configuration = config('credential_provider.providers.'.$providerId);
        if (!is_array($configuration)) {
            throw new ProviderError('PROVIDER_NOT_CONFIGURED');
        }
        $keys = array_keys($configuration);
        sort($keys, SORT_STRING);
        if ($keys !== ['ca_file', 'client_cert', 'client_key', 'endpoint', 'instance_epoch', 'profiles']) {
            throw new ProviderError('PROVIDER_CONFIG_INVALID');
        }
        $epoch = $configuration['instance_epoch'];
        if (!is_string($epoch) || preg_match('/^[1-9][0-9]{0,18}$/D', $epoch) !== 1
            || (strlen($epoch) === 19 && strcmp($epoch, '9223372036854775807') > 0)) {
            throw new ProviderError('PROVIDER_CONFIG_INVALID');
        }
        $endpoint = $configuration['endpoint'];
        $url = is_string($endpoint) ? parse_url($endpoint) : false;
        if (!is_array($url) || ($url['scheme'] ?? null) !== 'https'
            || empty($url['host']) || ($url['path'] ?? null) !== '/operations'
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || preg_match('/[^\x21-\x7e]/', $endpoint) === 1 || str_contains($endpoint, '\\')) {
            throw new ProviderError('PROVIDER_CONFIG_INVALID');
        }
        $profiles = $configuration['profiles'];
        if (!is_array($profiles) || !array_is_list($profiles) || count($profiles) < 1 || count($profiles) > 2
            || count(array_unique($profiles, SORT_REGULAR)) !== count($profiles)) {
            throw new ProviderError('PROVIDER_CONFIG_INVALID');
        }
        foreach ($profiles as $profile) {
            if (!in_array($profile, ['vless-uuid/v1', 'vmess-uuid/v1'], true)) {
                throw new ProviderError('PROVIDER_CONFIG_INVALID');
            }
        }
        sort($profiles, SORT_STRING);
        $configuration['profiles'] = $profiles;
        foreach (['ca_file', 'client_cert', 'client_key'] as $key) {
            $path = $configuration[$key];
            $resolved = is_string($path) ? realpath($path) : false;
            if ($resolved === false || !is_file($resolved) || !is_readable($resolved)
                || filesize($resolved) > 1048576) {
                throw new ProviderError('PROVIDER_CONFIG_INVALID');
            }
            $configuration[$key] = $resolved;
        }
        $certificate = @file_get_contents($configuration['client_cert']);
        $privateKey = @file_get_contents($configuration['client_key']);
        if ($certificate === false || $privateKey === false
            || !@openssl_x509_check_private_key($certificate, $privateKey)) {
            throw new ProviderError('PROVIDER_CONFIG_INVALID');
        }
        $caHash = @hash_file('sha256', $configuration['ca_file']);
        $certificateHash = @hash_file('sha256', $configuration['client_cert']);
        if (!is_string($caHash) || !is_string($certificateHash)) {
            throw new ProviderError('PROVIDER_CONFIG_INVALID');
        }
        $configuration['config_digest'] = hash('sha256', ProviderCodec::canonical([
            'provider_id' => $providerId,
            'endpoint' => $endpoint,
            'provider_instance_epoch' => $epoch,
            'profiles' => $profiles,
            'ca_sha256' => $caHash,
            'client_certificate_sha256' => $certificateHash,
        ]));
        return $configuration;
    }
}
