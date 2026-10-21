<?php

namespace Tests\Unit\Core;

use App\Core\Security\AdminAuditSanitizer;
use App\Domains\InternalApi\OperationExecutor;
use PHPUnit\Framework\TestCase;

final class NativeNetworkSecretProjectionTest extends TestCase
{
    public function test_only_the_explicit_machine_credential_projection_can_preserve_its_root_token(): void
    {
        $reflection = new \ReflectionClass(OperationExecutor::class);
        $executor = $reflection->newInstanceWithoutConstructor();
        $clean = $reflection->getMethod('clean');
        $value = ['id' => 12, 'token' => 'machine-value', 'password' => 'not-admitted',
            'details' => ['token' => 'nested-not-admitted', 'label' => 'safe']];
        self::assertSame(['id' => '12', 'details' => ['label' => 'safe']], $clean->invoke($executor, $value));
        self::assertSame(['id' => '12', 'token' => 'machine-value', 'details' => ['label' => 'safe']],
            $clean->invoke($executor, $value, false, true, true));
    }

    public function test_encoded_protocol_configuration_is_redacted_in_nested_and_historical_audit_data(): void
    {
        $input = ['input' => ['node_id' => '9007199254740993',
            'configuration_json' => '{"protocol_settings":{"private_key":"private-material"}}',
            'input_json' => '{"password":"extension-material"}'], 'remark' => 'safe'];
        $safe = AdminAuditSanitizer::redact($input);
        self::assertSame('[REDACTED]', $safe['input']['configuration_json']);
        self::assertSame('[REDACTED]', $safe['input']['input_json']);
        self::assertSame('9007199254740993', $safe['input']['node_id']);
        self::assertSame('safe', $safe['remark']);
        self::assertStringNotContainsString('extension-material', AdminAuditSanitizer::safeJson(json_encode($input)));
        self::assertStringNotContainsString('private-material', AdminAuditSanitizer::safeJson(json_encode($input)));
    }
}
