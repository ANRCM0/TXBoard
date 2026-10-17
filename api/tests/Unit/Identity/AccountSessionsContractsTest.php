<?php

namespace Tests\Unit\Identity;

use App\Domains\Identity\Contracts\AccountSessions;
use App\Domains\InternalApi\MutationOperationResult;
use App\Domains\InternalApi\OperationRegistry;
use App\Domains\InternalApi\ReadOperationResult;
use App\Domains\InternalApi\SessionMutationOperations;
use App\Domains\InternalApi\SessionReadOperations;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Mockery;
use Tests\TestCase;

final class AccountSessionsContractsTest extends TestCase
{
    public function test_public_port_is_exact_scalar_contract_without_framework_or_http_types(): void
    {
        $contract = new \ReflectionClass(AccountSessions::class);
        $this->assertTrue($contract->isInterface());
        $this->assertSame(['listing', 'revoke', 'end'], array_map(static fn ($method) => $method->name, $contract->getMethods()));
        foreach (['listing' => [['int', '?int'], 'array'], 'revoke' => [['int', 'int'], 'bool'],
            'end' => [['int', '?int'], 'void']] as $name => [$parameters, $result]) {
            $method = $contract->getMethod($name);
            $this->assertSame($parameters, array_map(static fn ($parameter) => (string) $parameter->getType(), $method->getParameters()));
            $this->assertSame($result, (string) $method->getReturnType());
        }
        $limit = $contract->getMethod('listing')->getParameters()[1];
        $this->assertTrue($limit->isDefaultValueAvailable());
        $this->assertNull($limit->getDefaultValue());
        $this->assertFalse($contract->getMethod('end')->getParameters()[1]->isOptional());
    }

    public function test_fixed_adapters_supply_actual_actor_and_current_token_not_input_assertions(): void
    {
        $actor = new User();
        $actor->forceFill(['id' => 7]);
        $token = new PersonalAccessToken();
        $token->forceFill(['id' => 17]);
        $actor->withAccessToken($token);
        $port = Mockery::mock(AccountSessions::class);
        $port->shouldReceive('listing')->once()->with(7)->andReturn([['id' => 17]]);
        $port->shouldReceive('revoke')->once()->with(7, 31)->andReturn(true);
        $port->shouldReceive('revoke')->once()->with(7, 32)->andReturn(false);
        $port->shouldReceive('end')->once()->with(7, 17);
        $read = (new SessionReadOperations($port))->listing($actor, ['user_id' => '999', 'limit' => 1]);
        $this->assertInstanceOf(ReadOperationResult::class, $read);
        $this->assertSame([['id' => 17]], $read->data);
        $commands = new SessionMutationOperations($port);
        $result = $commands->revoke($actor, ['session_id' => '31', 'user_id' => '999']);
        $this->assertInstanceOf(MutationOperationResult::class, $result);
        $this->assertSame(['ok' => true], $result->data);
        $this->assertNull($commands->revoke($actor, ['session_id' => '32']));
        $this->assertSame(['ok' => true], $commands->end($actor, ['session_id' => '999', 'current_token_id' => '999'])->data);
    }

    public function test_end_passes_null_for_missing_or_transient_token_and_result_cannot_supply_status_or_authority(): void
    {
        $actor = new User();
        $actor->forceFill(['id' => 7]);
        $port = Mockery::mock(AccountSessions::class);
        $port->shouldReceive('end')->twice()->with(7, null);
        $commands = new SessionMutationOperations($port);
        $this->assertSame(['ok' => true], $commands->end($actor, [])->data);
        $actor->withAccessToken(new TransientToken());
        $this->assertSame(['ok' => true], $commands->end($actor, [])->data);
        $reflection = new \ReflectionClass(MutationOperationResult::class);
        $this->assertTrue($reflection->isReadOnly());
        $this->assertSame(['data' => ['ok' => true]], get_object_vars(new MutationOperationResult(['ok' => true])));
    }

    public function test_registry_exposes_only_three_fixed_session_targets_and_preserves_closed_inputs_and_retry_modes(): void
    {
        $registry = OperationRegistry::all();
        $expected = [
            'identity.sessions.list' => ['listing', 'read', SessionReadOperations::class, null, []],
            'identity.sessions.revoke' => ['revoke', 'workflow', null, SessionMutationOperations::class, ['session_id']],
            'identity.session.end' => ['end', 'once', null, SessionMutationOperations::class, []],
        ];
        foreach ($expected as $operation => [$method, $mode, $handler, $command, $fields]) {
            $spec = $registry[$operation];
            $this->assertSame($method, $spec['method']);
            $this->assertSame($mode, $spec['mode']);
            $this->assertNull($spec['controller']);
            $this->assertSame($handler, $spec['handler']);
            $this->assertSame($command, $spec['command']);
            $this->assertSame($fields, array_keys($spec['rules']));
            $this->assertSame(['user', 'admin'], $spec['domains']);
            $this->assertSame('authenticated', $spec['actor']);
        }
    }
}
