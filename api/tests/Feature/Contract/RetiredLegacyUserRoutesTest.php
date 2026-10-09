<?php

namespace Tests\Feature\Contract;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Batch 4: official Vue has moved to TXAPI. Only TXBoard-internal consumers
 * gate these retirements; independently deployed consumers must migrate.
 */
class RetiredLegacyUserRoutesTest extends TestCase
{
    public function test_migrated_v1_routes_are_not_registered(): void
    {
        $removedUser = ["/resetSecurity","/changePassword","/update","/getSubscribe","/getStat","/checkLogin","/getQuickLoginUrl","/getActiveSession","/removeActiveSession","/order/detail","/order/getPaymentMethod","/order/cancel","/plan/fetch","/notice/fetch","/ticket/reply","/ticket/close","/ticket/save","/ticket/fetch","/coupon/check","/knowledge/fetch","/stat/getTrafficLog","/invite/details"];
        $removedPassport = ["/auth/token2Login","/auth/forget","/auth/getQuickLoginUrl","/auth/loginWithMailLink"];
        $paths = array_merge(
            array_map(static fn ($path) => 'api/v1/user' . $path, $removedUser),
            array_map(static fn ($path) => 'api/v1/passport' . $path, $removedPassport)
        );
        $active = array_map(static fn ($r) => $r->uri(), Route::getRoutes()->getRoutes());
        foreach ($paths as $uri) {
            $this->assertNotContains($uri, $active, 'Deprecated V1 route still registered: ' . $uri);
        }
    }

    public function test_critical_routes_and_remaining_user_features_are_not_pruned(): void
    {
        $active = array_map(static fn ($r) => $r->uri(), Route::getRoutes()->getRoutes());
        foreach ([
            'api/v1/guest/payment/notify/{method}/{uuid}',
            'api/v1/guest/telegram/webhook',
            'api/v1/passport/auth/login',
            'api/v1/passport/comm/sendEmailVerify',
            'api/v1/guest/comm/config',
            'api/v1/user/order/save',
            'api/v1/user/order/checkout',
            'api/v1/user/order/check',
            'api/v1/user/order/fetch',
            'api/v1/user/server/fetch',
            'api/v1/user/knowledge/getCategory',
            'api/v1/user/invite/fetch',
            'api/v1/user/invite/save',
            'api/v1/user/ticket/withdraw',
            'api/v1/user/gift-card/redeem',
            'api/v1/user/comm/getStripePublicKey',
            'api/v2/passport/auth/login',
            'api/v2/guest/comm/config',
        ] as $uri) {
            $this->assertContains($uri, $active, 'Required internal route accidentally removed: ' . $uri);
        }
    }
}
