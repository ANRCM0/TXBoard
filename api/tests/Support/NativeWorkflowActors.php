<?php

namespace Tests\Support;

use App\Core\Host\Execution\InvocationScope;
use App\Models\User;
use App\Services\Auth\NativeIdentityService;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

trait NativeWorkflowActors
{
    private function workflowActor(bool $admin = false, array $permissions = []): User
    {
        [, $user] = app(NativeIdentityService::class)->create(Str::uuid().'@example.test', 'workflow-password');
        if ($admin) $user->forceFill(['is_admin' => true])->saveOrFail();
        foreach ($permissions as $permission) DB::table('tx_internal_actor_grant')->insert([
            'user_id' => $user->id, 'permission' => $permission, 'created_at' => time()]);
        return $user;
    }

    private function authenticateWorkflowActor(User $user): PersonalAccessToken
    {
        $token = $user->createToken('workflow-owner', ['*']);
        Auth::forgetGuards();
        Auth::guard('sanctum')->setUser($user->withAccessToken($token->accessToken));
        return $token->accessToken;
    }

    private function invokeWorkflow(Closure $operation): mixed
    {
        return app(InvocationScope::class)->run('internal', $operation);
    }

    private function workflowHttpFailure(int $status, Closure $operation): void
    {
        try { $operation(); $this->fail('Expected HTTP '.$status); }
        catch (HttpExceptionInterface $error) { $this->assertSame($status, $error->getStatusCode()); }
    }

    private function workflowKey(): string { return (string) Str::uuid(); }
}
