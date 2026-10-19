<?php

namespace Tests\Unit\Domains\CredentialProvider;

use App\Domains\CredentialProvider\RenderGrantCodec;
use DomainException;
use PHPUnit\Framework\TestCase;

final class RenderClientPolicyTest extends TestCase
{
    public function test_explicit_client_version_boundaries_are_closed_for_every_builtin_format(): void
    {
        foreach (RenderGrantCodec::CLIENT_FORMATS as $format => $rule) {
            RenderGrantCodec::format($format, $rule['min']);
            $this->addToAssertionCount(1);
            foreach ([null, '', $rule['before'], '0.0.0', '01.18.0', $rule['min']."\n"] as $version) {
                try { RenderGrantCodec::format($format, $version); $this->fail('Invalid version accepted: '.$format); }
                catch (DomainException $error) { $this->assertSame('NATIVE_SUBSCRIPTION_DELIVERY_INPUT_INVALID', $error->getMessage()); }
            }
        }
    }

    public function test_legacy_client_profiles_do_not_silently_widen_to_vless_or_drop_explicit_alpn(): void
    {
        $node = ['protocol' => 'vmess', 'transport' => 'tcp', 'tls' => ['server_name' => 'example.test', 'alpn' => []]];
        foreach (['clash/v1', 'surge-ios/v1', 'surge-mac/v1'] as $format) {
            $this->assertSame(['vmess-uuid/v1'], RenderGrantCodec::profiles($format));
            $this->assertTrue(RenderGrantCodec::compatibleNode($format, $node));
            $this->assertFalse(RenderGrantCodec::compatibleNode($format, array_replace($node, ['protocol' => 'vless'])));
        }
        $node['tls']['alpn'] = ['h2', 'http/1.1'];
        $this->assertFalse(RenderGrantCodec::compatibleNode('clash/v1', $node));
        foreach (['clash-meta/v1', 'sing-box/v1', 'shadowrocket/v1', 'surge-ios/v1', 'surge-mac/v1'] as $format) {
            $this->assertTrue(RenderGrantCodec::compatibleNode($format, $node));
        }
    }
}
