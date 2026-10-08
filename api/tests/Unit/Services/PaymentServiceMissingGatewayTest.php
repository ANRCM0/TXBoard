<?php

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Services\PaymentService;
use Tests\TestCase;

class PaymentServiceMissingGatewayTest extends TestCase
{
    public function test_unknown_payment_method_throws_domain_exception(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('payment method not found or disabled');

        new PaymentService('not_a_real_payment_gateway');
    }

    public function test_unavailable_payment_method_does_not_try_to_instantiate_null_class(): void
    {
        try {
            new PaymentService('retired_gateway_without_plugin');
            $this->fail('An unavailable payment method must fail.');
        } catch (ApiException $exception) {
            $this->assertSame('payment method not found or disabled', $exception->getMessage());
        }
    }
}
