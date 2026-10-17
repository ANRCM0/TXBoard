<?php

namespace Tests\Unit\Domains\InternalApi;

use App\Domains\InternalApi\OperationExecutor;
use App\Domains\InternalApi\OperationRegistry;
use App\Domains\InternalApi\SessionMutationOperations;
use App\Domains\InternalApi\SessionReadOperations;
use App\Http\Controllers\Txapi\AuthController;
use App\Models\User;
use Illuminate\Http\Request;
use LogicException;
use ReflectionMethod;
use Tests\TestCase;

final class SessionCommandDispatchTest extends TestCase
{
    public function test_commands_reject_any_target_outside_the_exact_compiled_tuples_before_receipt_or_io(): void
    {
        $base = OperationRegistry::all()['identity.sessions.revoke'];
        $actor = new User();
        $actor->id = 17;
        $cases = [
            ['identity.sessions.revoke', ['command' => 'CallerSelectedClass'], $actor],
            ['identity.sessions.revoke', ['method' => 'end'], $actor],
            ['identity.sessions.revoke', ['mode' => 'read'], $actor],
            ['identity.sessions.revoke', ['mode' => 'domain'], $actor],
            ['identity.sessions.revoke', ['controller' => AuthController::class], $actor],
            ['identity.sessions.revoke', [], null],
            ['commerce.orders.cancel', [], $actor],
            ['identity.session.end', [], $actor],
        ];
        foreach ($cases as [$operation, $changes, $current]) {
            $this->reject($operation, array_replace($base, $changes), $current, 'INTERNAL_SESSION_COMMAND_INVALID');
        }
        $end = OperationRegistry::all()['identity.session.end'];
        $this->reject('identity.session.end', array_replace($end, ['mode' => 'workflow']), $actor,
            'INTERNAL_SESSION_COMMAND_INVALID');
        $this->reject('identity.session.end', array_replace($end, ['method' => 'revoke']), $actor,
            'INTERNAL_SESSION_COMMAND_INVALID');
    }

    public function test_read_handler_admission_is_not_relaxed_to_allow_mutation_modes_or_dual_targets(): void
    {
        $base = OperationRegistry::all()['identity.sessions.list'];
        $actor = new User();
        $actor->id = 17;
        foreach ([['mode' => 'workflow'], ['mode' => 'once'], ['controller' => AuthController::class],
            ['command' => SessionMutationOperations::class]] as $changes) {
            $this->reject('identity.sessions.list', array_replace($base, $changes), $actor,
                'INTERNAL_READ_HANDLER_INVALID');
        }
        $this->reject('identity.sessions.list', $base, null, 'INTERNAL_READ_HANDLER_INVALID');
        $command = OperationRegistry::all()['identity.session.end'];
        $this->reject('identity.session.end', array_replace($command, ['handler' => SessionReadOperations::class]),
            $actor, 'INTERNAL_READ_HANDLER_INVALID');
    }

    private function reject(string $operation, array $spec, ?User $actor, string $message): void
    {
        try {
            (new ReflectionMethod(OperationExecutor::class, 'dispatch'))->invoke(new OperationExecutor(),
                Request::create('/internal', 'POST'), $operation, $spec, [], $actor);
            $this->fail('Invalid executable target reached receipt or invocation.');
        } catch (LogicException $error) {
            $this->assertSame($message, $error->getMessage());
        }
    }
}
