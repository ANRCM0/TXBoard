<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\NativeOrderPaymentQueries;
use App\Domains\Billing\NativePaymentAttempts;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativePaymentTestCase;

final class NativeOrderPaymentQueriesTest extends NativePaymentTestCase
{
    public function test_history_is_paginated_and_unresolved_status_covers_the_whole_order(): void
    {
        [$user, $order] = $this->purchase();
        $attempts = app(NativePaymentAttempts::class);
        $older = $attempts->prepare($user->id, $order->id, $this->payment->id, 'merchant-1', 'history-old');
        $attempts->markFailed($user->id, (int) $older['attempt_id']);
        $newer = $attempts->prepare($user->id, $order->id, $this->payment->id, 'merchant-1', 'history-new');
        $token = $user->createToken('history', ['*']);
        Auth::forgetGuards();
        Auth::guard('sanctum')->setUser($user->withAccessToken($token->accessToken));
        $queries = app(NativeOrderPaymentQueries::class);
        $counts = [DB::table('tx_payment_attempt')->count(), DB::table('tx_money_account')->count(), DB::table('tx_money_journal')->count()];
        $first = $queries->attempts($user->id, $order->trade_no, limit: 1);
        $this->assertSame([$newer['attempt_id']], array_column($first->items, 'attempt_id'));
        $this->assertTrue($first->meta['has_more']);
        $this->assertTrue($first->meta['has_unresolved']);
        $last = $queries->attempts($user->id, $order->trade_no, $first->meta['next_before_id'], 1);
        $this->assertSame([$older['attempt_id']], array_column($last->items, 'attempt_id'));
        $this->assertFalse($last->meta['has_more']);
        $this->assertTrue($last->meta['has_unresolved']);
        $attempts->markFailed($user->id, (int) $newer['attempt_id']);
        $this->assertFalse($queries->attempts($user->id, $order->trade_no)->meta['has_unresolved']);
        $this->assertSame($counts, [DB::table('tx_payment_attempt')->count(), DB::table('tx_money_account')->count(), DB::table('tx_money_journal')->count()]);
    }

    public function test_other_users_order_and_revoked_session_never_return_payment_history(): void
    {
        [$user, $order] = $this->purchase();
        [$other] = $this->purchase();
        $token = $other->createToken('history-other', ['*']);
        Auth::forgetGuards();
        Auth::guard('sanctum')->setUser($other->withAccessToken($token->accessToken));
        $queries = app(NativeOrderPaymentQueries::class);
        $this->assertNull($queries->attempts($other->id, $order->trade_no));
        $token->accessToken->delete();
        $this->expectException(AuthenticationException::class);
        $queries->attempts($other->id, $order->trade_no);
    }
}
