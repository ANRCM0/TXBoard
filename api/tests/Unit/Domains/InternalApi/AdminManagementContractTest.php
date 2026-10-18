<?php

namespace Tests\Unit\Domains\InternalApi;

use App\Domains\InternalApi\AdminOperationBindings;
use App\Domains\InternalApi\OperationRegistry;
use App\Domains\InternalApi\OperationSchemas;
use PHPUnit\Framework\TestCase;

final class AdminManagementContractTest extends TestCase
{
    public function test_every_bound_administrator_adapter_is_closed_typed_and_role_scoped(): void
    {
        foreach (AdminOperationBindings::all() as $id => $binding) {
            $spec = OperationRegistry::all()[$id];
            $contract = OperationSchemas::operation($id);
            $this->assertSame(['admin'], $spec['domains'], $id);
            $this->assertSame('admin', $spec['actor'], $id);
            $this->assertNotEmpty($spec['permission'], $id);
            $this->assertFalse($contract['input']['additionalProperties'], $id);
            $this->assertTrue((new \ReflectionMethod($binding['controller'], $binding['method']))->isPublic(), $id);
            foreach ($binding['parameters'] as $field) {
                $this->assertContains($field, $contract['input']['required'], $id);
            }
            $this->assertFalse(OperationSchemas::input($id, ['controller' => 'caller-selected']), $id);
        }
    }

    public function test_management_inputs_keep_identifiers_and_money_exact_and_reject_legacy_mutations(): void
    {
        $this->assertTrue(OperationSchemas::input('identity.accounts.status.update', [
            'user_id' => '9007199254740993', 'expected_banned' => false, 'banned' => true]));
        $this->assertFalse(OperationSchemas::input('identity.accounts.status.update', [
            'user_id' => 9007199254740993, 'expected_banned' => false, 'banned' => true]));
        $this->assertFalse(OperationSchemas::input('identity.accounts.create', [
            'email' => 'user@example.test', 'password' => 'valid-password', 'balance' => '100']));
        $this->assertFalse(OperationSchemas::input('billing.refunds.create', [
            'user_id' => '1', 'source_type' => 'receipt', 'source_id' => '2',
            'amount_minor' => '9223372036854775808', 'reason' => 'manual']));
        $this->assertFalse(OperationSchemas::input('content.notices.sort', ['ids' => [1, 2]]));
        $this->assertTrue(OperationSchemas::input('content.notices.sort', ['ids' => ['1', '2']]));
    }
}
