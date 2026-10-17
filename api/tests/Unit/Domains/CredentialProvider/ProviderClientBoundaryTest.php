<?php

namespace Tests\Unit\Domains\CredentialProvider;

use App\Domains\CredentialProvider\Contracts\ProviderClient;
use App\Domains\CredentialProvider\ProviderTransport;
use App\Domains\Subscription\Services\NativeCredentialProviderService;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class ProviderClientBoundaryTest extends TestCase
{
    public function test_default_client_is_scoped_and_recreated_for_the_next_work_item(): void
    {
        $client = app(ProviderClient::class);
        $this->assertInstanceOf(ProviderTransport::class, $client);
        $this->assertSame($client, app(ProviderClient::class));

        $this->app->forgetScopedInstances();
        $next = app(ProviderClient::class);
        $this->assertInstanceOf(ProviderTransport::class, $next);
        $this->assertNotSame($client, $next);
        $this->assertSame($next, app(ProviderClient::class));
    }

    public function test_business_service_uses_the_replaced_client_without_resolving_the_concrete_transport(): void
    {
        $client = Mockery::mock(ProviderClient::class);
        $client->shouldReceive('configuration')->once()->with('replacement-provider')
            ->andThrow(new RuntimeException('REPLACEMENT_CLIENT_USED'));
        $this->app->instance(ProviderClient::class, $client);
        $this->app->bind(ProviderTransport::class, static function (): never {
            throw new RuntimeException('CONCRETE_TRANSPORT_MUST_NOT_BE_USED');
        });

        $this->expectExceptionMessage('REPLACEMENT_CLIENT_USED');
        app(NativeCredentialProviderService::class)->register('replacement-provider', 'replacement-registration');
    }
}
