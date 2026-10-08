<?php

namespace Tests\Unit\Services;

use App\Services\TrafficUsage;
use Tests\TestCase;

class TrafficUsageTest extends TestCase
{
    public function test_accepts_valid_integer_counters_and_string_encoded_bytes(): void
    {
        $this->assertSame([7 => [0, 1024], 8 => [4096, 10]],
            TrafficUsage::normalize(['7' => [0, '1024'], '8' => [4096, 10]], '1.5'));
    }

    public function test_rejects_negative_decimal_overflow_malformed_uid_and_shape(): void
    {
        $payload = [
            1 => [-1, 2],
            2 => [1.5, 2],
            3 => [INF, 2],
            4 => ['9999999999999999999999', 0],
            5 => [1],
            6 => [1, 2, 3],
            'bad-user' => [1, 2],
            7 => [10, 20],
        ];
        $this->assertSame([7 => [10, 20]], TrafficUsage::normalize($payload, 1));
    }

    public function test_rejects_invalid_multiplier_without_any_billing(): void
    {
        foreach ([-1, 0, INF, NAN, 'oops', 1001] as $rate) {
            $this->assertSame([], TrafficUsage::normalize([7 => [100, 200]], $rate));
        }
    }

    public function test_enforces_safety_cap_even_with_large_valid_multiplier(): void
    {
        $this->assertSame([], TrafficUsage::normalize([9 => [(string) (TrafficUsage::MAX_REPORT_BYTES + 1), 0]], 1000));
        $this->assertSame([9 => [TrafficUsage::MAX_REPORT_BYTES, 0]],
            TrafficUsage::normalize([9 => [TrafficUsage::MAX_REPORT_BYTES, 0]], 1000));
    }

    public function test_rejects_user_ids_larger_than_php_int_max(): void
    {
        $this->assertSame([], TrafficUsage::normalize(['9223372036854775808' => [1, 1]], 1));
    }
}
