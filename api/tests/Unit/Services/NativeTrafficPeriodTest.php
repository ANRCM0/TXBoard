<?php

namespace Tests\Unit\Services;

use App\Domains\Network\NativeTrafficPeriod;
use App\Domains\Network\NodeReportError;
use Tests\TestCase;

class NativeTrafficPeriodTest extends TestCase
{
    public function test_period_survives_snapshot_refresh_and_verifies_prior_epochs(): void
    {
        $period = app(NativeTrafficPeriod::class);
        $token = $period->issue(12, 0);
        $this->assertSame($token, $period->issue(12, 0));
        $this->assertSame(0, $period->verify($token, 12, 3));
        $this->assertSame(3, $period->verify($period->issue(12, 3), 12, 3));
    }

    public function test_tampered_other_user_future_and_noncanonical_tokens_are_rejected(): void
    {
        $period = app(NativeTrafficPeriod::class);
        foreach ([[$period->issue(13, 0), 12, 1], [$period->issue(12, 2), 12, 1],
            [str_replace('.12.', '.012.', $period->issue(12, 0)), 12, 1],
            [substr($period->issue(12, 0), 0, -1) . 'Z', 12, 1]] as [$token, $uid, $epoch]) {
            try {
                $period->verify($token, $uid, $epoch);
                $this->fail('Invalid period accepted');
            } catch (NodeReportError $error) {
                $this->assertSame('INVALID_TRAFFIC_PERIOD', $error->errorCode);
            }
        }
    }
    public function test_application_key_change_cannot_relabel_old_signed_period(): void
    {
        $period = app(NativeTrafficPeriod::class);
        $old = $period->issue(12, 0);
        config()->set('app.key', 'base64:' . base64_encode(str_repeat('Z', 32)));
        $this->expectException(NodeReportError::class);
        $period->verify($old, 12, 0);
    }

}
