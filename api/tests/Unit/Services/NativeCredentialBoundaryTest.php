<?php

namespace Tests\Unit\Services;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\InstallationAuthorityException;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\Txapi\ServerController;
use App\Models\Server;
use App\Models\User;
use App\Services\NodeSyncService;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class NativeCredentialBoundaryTest extends TestCase
{
    /** @dataProvider legacyCredentialEntrypoints */
    public function test_native_rejection_precedes_legacy_credentials_and_hooks(string $entrypoint): void
    {
        $authority = Mockery::mock(InstallationAuthority::class);
        $authority->shouldReceive('assertRuntime')->once()
            ->andThrow(new InstallationAuthorityException('INSTALLATION_AUTHORITY_NATIVE_NOT_READY'));
        $this->app->instance(InstallationAuthority::class, $authority);
        $user = new User();
        $user->forceFill(['id' => 1, 'uuid' => 'must-not-be-rendered', 'group_id' => 1]);
        $node = new Server();
        $node->forceFill(['id' => 1, 'group_ids' => ['1']]);
        $request = Request::create('/txapi/test');

        $this->expectException(InstallationAuthorityException::class);
        $this->expectExceptionMessage('INSTALLATION_AUTHORITY_NATIVE_NOT_READY');
        match ($entrypoint) {
            'node-rest-and-full' => ServerService::getNativeAvailableUsers($node),
            'node-legacy-reader' => ServerService::getAvailableUsers($node),
            'node-delta' => NodeSyncService::notifyUserChanged($user),
            'server-renderer' => ServerService::getAvailableServers($user),
            'server-api' => app(ServerController::class)->index($request, app(UserService::class)),
            'subscription' => app(SubscriptionController::class)->subscribe($request),
            'subscription-direct' => app(SubscriptionController::class)->doSubscribe($request, $user, [
                ['password' => 'must-not-be-delivered'],
            ]),
        };
    }

    public static function legacyCredentialEntrypoints(): array
    {
        return array_map(static fn (string $entrypoint): array => [$entrypoint], [
            'node-rest-and-full', 'node-legacy-reader', 'node-delta', 'server-renderer',
            'server-api', 'subscription', 'subscription-direct',
        ]);
    }
}
