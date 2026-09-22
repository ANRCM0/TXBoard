<?php

namespace Tests\Unit\Services\Module;

use App\Services\Module\ModuleHealthDetails;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ModuleHealthDetailsTest extends TestCase
{
    public function test_health_details_are_bounded_runtime_boolean_checks(): void
    {
        $details = ModuleHealthDetails::fromChecks([
            'schedule' => true,
            'websocket_server' => null,
        ], 1790112000);

        $this->assertSame([
            'checks' => [
                'schedule' => true,
                'websocket_server' => null,
            ],
            'observed_at' => 1790112000,
        ], $details->toArray());
    }

    public function test_arbitrary_health_text_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ModuleHealthDetails([
            'runtime' => 'redis password=secret',
        ], 1790112000);
    }

    public function test_non_positive_observed_at_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ModuleHealthDetails::fromChecks([
            'runtime' => true,
        ], 0);
    }

    public function test_invalid_health_check_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ModuleHealthDetails([
            '../runtime' => true,
        ], 1790112000);
    }
}
