<?php

namespace Tests\Feature\Config;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `commission_transfer_limit` is optional: clearing the admin field must mean
 * "inherit the withdrawal minimum", not "no minimum". A stored empty string is
 * not null, so it used to bypass the admin_setting() default.
 */
class TransferMinimumTest extends TestCase
{
    use RefreshDatabase;

    public function test_unset_transfer_limit_inherits_the_withdrawal_minimum(): void
    {
        admin_setting(['commission_withdraw_limit' => 250]);

        $this->assertSame(250.0, admin_transfer_minimum());
    }

    public function test_blank_transfer_limit_inherits_the_withdrawal_minimum(): void
    {
        admin_setting(['commission_withdraw_limit' => 250, 'commission_transfer_limit' => '']);

        $this->assertSame(250.0, admin_transfer_minimum());
    }

    public function test_explicit_transfer_limit_overrides_the_withdrawal_minimum(): void
    {
        admin_setting(['commission_withdraw_limit' => 250, 'commission_transfer_limit' => 40]);

        $this->assertSame(40.0, admin_transfer_minimum());
    }

    public function test_explicit_zero_transfer_limit_disables_the_minimum(): void
    {
        admin_setting(['commission_withdraw_limit' => 250, 'commission_transfer_limit' => 0]);

        $this->assertSame(0.0, admin_transfer_minimum());
    }

    public function test_defaults_to_100_when_nothing_is_configured(): void
    {
        $this->assertSame(100.0, admin_transfer_minimum());
    }
}
