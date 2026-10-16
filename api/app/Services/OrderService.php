<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Models\OrderPurchaseTerms;
use App\Domains\Billing\OrderPurchaseTermsSnapshot;
use App\Domains\Billing\PurchasedOrderGrant;
use App\Services\Plugin\HookManager;
use App\Services\Subscription\LegacyUserMutationCapture;
use App\Services\Subscription\LegacyCaptureShadowException;
use App\Services\Subscription\PublishedOrderService;
use App\Utils\Helper;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Services\PlanService;

class OrderService
{
    const STR_TO_TIME = [
        Plan::PERIOD_MONTHLY => 1,
        Plan::PERIOD_QUARTERLY => 3,
        Plan::PERIOD_HALF_YEARLY => 6,
        Plan::PERIOD_YEARLY => 12,
        Plan::PERIOD_TWO_YEARLY => 24,
        Plan::PERIOD_THREE_YEARLY => 36
    ];
    public $order;
    public $user;
    private ?array $purchasedGrant = null;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    private function lockCurrentOrder(): ?Order
    {
        return Order::whereKey($this->order->id)
            ->lockForUpdate()
            ->first();
    }

    private function lockCurrentOrderWhenStatus(int $status): ?Order
    {
        return Order::whereKey($this->order->id)
            ->where('status', $status)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Create an order from a request.
     *
     * @param User $user
     * @param Plan $plan
     * @param string $period
     * @param string|null $couponCode
     * @return Order
     * @throws ApiException
     */
    public static function createFromRequest(
        User $user,
        Plan $plan,
        string $period,
        ?string $couponCode = null,
        ?string $idempotencyKey = null,
    ): Order {
        $userService = app(UserService::class);
        $newPeriod = PlanService::getPeriodKey($period);
        $body = [
            'plan_id' => (int) $plan->id,
            'period' => $newPeriod,
            'coupon_code' => $couponCode,
        ];

        return DB::transaction(function () use ($user, $plan, $newPeriod, $couponCode,
            $userService, $idempotencyKey, $body): Order {
            app(\App\Domains\AuthorityMigration\AuthorityWriterFence::class)->admit('order.create');
            // One user lock serializes concurrent creates across TXAPI, native
            // clients and Gateway. Claim-check always precedes order preflight.
            $user = User::lockForUpdate()->find($user->id);
            if (!$user) {
                throw new ApiException(__('The user does not exist'));
            }

            return \App\Domains\Billing\OrderIdempotency::run(
                (int) $user->id, $idempotencyKey, $body,
                function () use ($user, $plan, $newPeriod, $couponCode, $userService): Order {
                    $plan = Plan::whereKey($plan->id)->firstOrFail();
                    (new PlanService($plan))->validatePurchase($user, $newPeriod);
                    if ($userService->isNotCompleteOrderByUserId($user->id)) {
                        throw new ApiException(__('You have an unpaid or pending order, please try again later or cancel it'));
                    }

                    $capture = app(LegacyUserMutationCapture::class);
                    $captureBefore = $capture->beginOrderCreation((int) $user->id);

                    HookManager::call('order.create.before', [$user, $plan, $newPeriod, $couponCode]);
                    $order = new Order([
                        'user_id' => $user->id,
                        'plan_id' => $plan->id,
                        'period' => $newPeriod,
                        'trade_no' => Helper::generateOrderNo(),
                        'total_amount' => (int) round((float) $plan->prices[$newPeriod] * 100),
                        'purchase_terms_version' => 'tx-core.purchase-terms/v1',
                    ]);
                    $orderService = new self($order);
                    if ($couponCode) {
                        $orderService->applyCoupon($couponCode);
                    }
                    $orderService->setVipDiscount($user);
                    $orderService->setOrderType($user);
                    if ($user->balance && $order->total_amount > 0) {
                        $orderService->handleUserBalance($user, $userService);
                    }
                    $orderService->setInvite(user: $user);
                    if (!$order->save()) {
                        throw new ApiException(__('Failed to create order'));
                    }
                    try {
                        app(OrderPurchaseTermsSnapshot::class)->capture($order, $plan);
                    } catch (\InvalidArgumentException|\RuntimeException $exception) {
                        throw new ApiException('The selected plan cannot produce valid purchased terms', 409);
                    }
                    HookManager::call('order.create.after', $order);
                    HookManager::call('order.after_create', $order);
                    $capture->appendOrderCreation($captureBefore);
                    return $order;
                }
            );
        }, 3);
    }

    public function open(): void
    {
        // Dispatch from the stored marker, not a possibly stale queued Order.
        // Native fulfillment owns its account-first transaction and admission.
        if (Order::query()->useWritePdo()->whereKey($this->order->id)->value('purchase_terms_version') === PublishedOrderService::VERSION) {
            app(\App\Services\Subscription\SubscriptionFulfillmentService::class)->fulfill((int) $this->order->id);
            $this->order = Order::query()->findOrFail($this->order->id);
            return;
        }
        $openedOrder = DB::transaction(function () {
            app(\App\Domains\AuthorityMigration\AuthorityWriterFence::class)->admit('order.fulfill');
            $order = $this->lockCurrentOrderWhenStatus(Order::STATUS_PROCESSING);
            if (!$order) {
                return null;
            }
            if ($order->purchase_terms_version === PublishedOrderService::VERSION) {
                throw new \RuntimeException('Native order requires native fulfillment admission');
            }

            $terms = OrderPurchaseTerms::query()->where('order_id', $order->id)->first();
            $resolver = app(PurchasedOrderGrant::class);
            $this->purchasedGrant = $resolver->resolve($order, $terms);
            $plan = $this->purchasedGrant === null ? Plan::find($order->plan_id) : $resolver->plan($order, $terms);
            if (!$plan) {
                throw new \RuntimeException('订阅不存在');
            }

            HookManager::call('order.open.before', $order);

            $this->user = User::lockForUpdate()->find($order->user_id);

            $capture = app(LegacyUserMutationCapture::class);
            $captureBefore = $capture->beginOrderFulfillment((int) $order->user_id, (int) $order->id);

            if ($order->surplus_credit) {
                $this->user->balance += $order->surplus_credit;
            }

            if ($order->surplus_order_ids) {
                Order::whereIn('id', $order->surplus_order_ids)
                    ->update(['status' => Order::STATUS_DISCOUNTED]);
            }

            match ((string) $order->period) {
                Plan::PERIOD_ONETIME => $this->buyByOneTime($plan),
                Plan::PERIOD_RESET_TRAFFIC => $this->resetPurchasedTraffic(),
                default => $this->buyByPeriod($order, $plan),
            };

            $this->setSpeedLimit($plan->speed_limit);
            $this->setDeviceLimit($plan->device_limit);

            if (!$this->user->save()) {
                throw new \RuntimeException('用户信息保存失败');
            }

            if ($this->purchasedGrant !== null && $this->purchasedGrant['open_event_id'] === 1) {
                if (!app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER)) {
                    throw new \RuntimeException('ORDER_PURCHASE_RESET_FAILED');
                }
                $this->user->refresh();
            }
            $order->status = Order::STATUS_COMPLETED;
            if (!$order->save()) {
                throw new \RuntimeException('订单信息保存失败');
            }

            $capture->appendOrderFulfillment($captureBefore);

            return $order;
        });

        if (!$openedOrder) {
            return;
        }

        $order = $openedOrder;
        $this->order = $order;

        $eventId = match ((int) $order->type) {
            Order::TYPE_NEW_PURCHASE => admin_setting('new_order_event_id', 0),
            Order::TYPE_RENEWAL => admin_setting('renew_order_event_id', 0),
            Order::TYPE_UPGRADE => admin_setting('change_order_event_id', 0),
            default => 0,
        };

        if ($this->purchasedGrant === null && $eventId) {
            $this->openEvent($eventId);
        }

        HookManager::call('order.open.after', $order);
    }


    public function setOrderType(User $user)
    {
        $order = $this->order;
        if ($order->period === Plan::PERIOD_RESET_TRAFFIC) {
            $order->type = Order::TYPE_RESET_TRAFFIC;
        } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id && ($user->expired_at > time() || $user->expired_at === NULL)) {
            if (!(int) admin_setting('plan_change_enable', 1))
                throw new ApiException('目前不允许更改订阅，请联系客服或提交工单操作');
            $order->type = Order::TYPE_UPGRADE;
            if ((int) admin_setting('surplus_enable', 1))
                $this->getSurplusValue($user, $order);
            if ($order->surplus_amount >= $order->total_amount) {
                $order->surplus_credit = (int) ($order->surplus_amount - $order->total_amount);
                $order->total_amount = 0;
            } else {
                $order->total_amount = (int) ($order->total_amount - $order->surplus_amount);
            }
        } else if (($user->expired_at === null || $user->expired_at > time()) && $order->plan_id == $user->plan_id) { // 用户订阅未过期或按流量订阅 且购买订阅与当前订阅相同 === 续费
            $order->type = Order::TYPE_RENEWAL;
        } else { // 新购
            $order->type = Order::TYPE_NEW_PURCHASE;
        }
    }

    public function setVipDiscount(User $user)
    {
        $order = $this->order;
        $subtotal = (int) $order->total_amount;
        $couponDiscount = (int) ($order->discount_amount ?? 0);
        $vipDiscount = $user->discount
            ? (int) round($subtotal * ((float) $user->discount / 100))
            : 0;
        $discount = min($subtotal, max(0, $couponDiscount + $vipDiscount));
        $order->discount_amount = $discount;
        $order->total_amount = $subtotal - $discount;
    }

    public function setInvite(User $user): void
    {
        $order = $this->order;
        if ($user->invite_user_id && ($order->total_amount <= 0))
            return;
        $order->invite_user_id = $user->invite_user_id;
        $inviter = User::find($user->invite_user_id);
        if (!$inviter)
            return;
        $commissionType = (int) $inviter->commission_type;
        if ($commissionType === User::COMMISSION_TYPE_SYSTEM) {
            $commissionType = (bool) admin_setting('commission_first_time_enable', true) ? User::COMMISSION_TYPE_ONETIME : User::COMMISSION_TYPE_PERIOD;
        }
        $isCommission = false;
        switch ($commissionType) {
            case User::COMMISSION_TYPE_PERIOD:
                $isCommission = true;
                break;
            case User::COMMISSION_TYPE_ONETIME:
                $isCommission = !$this->haveValidOrder($user);
                break;
        }

        if (!$isCommission)
            return;
        if ($inviter->commission_rate) {
            $order->commission_balance = $order->total_amount * ($inviter->commission_rate / 100);
        } else {
            $order->commission_balance = $order->total_amount * (admin_setting('invite_commission', 10) / 100);
        }
    }

    private function haveValidOrder(User $user): Order|null
    {
        return Order::where('user_id', $user->id)
            ->whereNotIn('status', [Order::STATUS_PENDING, Order::STATUS_CANCELLED])
            ->first();
    }

    private function getSurplusValue(User $user, Order $order)
    {
        if ($user->expired_at === NULL) {
            $lastOneTimeOrder = Order::where('user_id', $user->id)
                ->where('period', Plan::PERIOD_ONETIME)
                ->where('status', Order::STATUS_COMPLETED)
                ->orderBy('id', 'DESC')
                ->first();
            if (!$lastOneTimeOrder)
                return;
            $nowUserTraffic = Helper::transferToGB($user->transfer_enable);
            if (!$nowUserTraffic)
                return;
            $paidTotalAmount = ($lastOneTimeOrder->total_amount + $lastOneTimeOrder->balance_amount);
            if (!$paidTotalAmount)
                return;
            $trafficUnitPrice = $paidTotalAmount / $nowUserTraffic;
            $notUsedTraffic = $nowUserTraffic - Helper::transferToGB($user->u + $user->d);
            $result = $trafficUnitPrice * $notUsedTraffic;
            $order->surplus_amount = (int) ($result > 0 ? $result : 0);
            $order->surplus_order_ids = Order::where('user_id', $user->id)
                ->where('period', '!=', Plan::PERIOD_RESET_TRAFFIC)
                ->where('status', Order::STATUS_COMPLETED)
                ->pluck('id')
                ->all();
        } else {
            $orders = Order::query()
                ->where('user_id', $user->id)
                ->whereNotIn('period', [Plan::PERIOD_RESET_TRAFFIC, Plan::PERIOD_ONETIME])
                ->where('status', Order::STATUS_COMPLETED)
                ->get();

            if ($orders->isEmpty()) {
                $order->surplus_amount = 0;
                $order->surplus_order_ids = [];
                return;
            }

            $orderAmountSum = $orders->sum(fn($item) => $item->total_amount + $item->balance_amount + $item->surplus_amount - $item->surplus_credit);
            $orderMonthSum = $orders->sum(fn($item) => self::STR_TO_TIME[PlanService::getPeriodKey($item->period)] ?? 0);
            $firstOrderAt = $orders->min('created_at');
            $expiredAt = Carbon::createFromTimestamp($firstOrderAt)->addMonths($orderMonthSum);

            $now = now();
            $totalSeconds = $expiredAt->timestamp - $firstOrderAt;
            $remainSeconds = max(0, $expiredAt->timestamp - $now->timestamp);
            $cycleRatio = $totalSeconds > 0 ? $remainSeconds / $totalSeconds : 0;

            $plan = Plan::find($user->plan_id);
            $totalTraffic = $plan?->transfer_enable * $orderMonthSum;
            $usedTraffic = Helper::transferToGB($user->u + $user->d);
            $remainTraffic = max(0, $totalTraffic - $usedTraffic);
            $trafficRatio = $totalTraffic > 0 ? $remainTraffic / $totalTraffic : 0;

            $ratio = $cycleRatio;
            if (admin_setting('change_order_event_id', 0) == 1) {
                $ratio = min($cycleRatio, $trafficRatio);
            }


            $order->surplus_amount = (int) max(0, $orderAmountSum * $ratio);
            $order->surplus_order_ids = $orders->pluck('id')->all();
        }
    }

    public function paid(string $callbackNo, ?int $paymentId = null, ?int $verifiedAmountMinor = null)
    {
        try {
            $native = Order::query()->useWritePdo()->whereKey($this->order->id)
                ->value('purchase_terms_version') === PublishedOrderService::VERSION;
            $transition = function (?Order $order = null) use ($callbackNo, $paymentId, $verifiedAmountMinor, $native) {
                if (!$native) {
                    app(\App\Domains\AuthorityMigration\AuthorityWriterFence::class)->admit('order.paid');
                    $order = $this->lockCurrentOrder();
                    if ($order?->purchase_terms_version === PublishedOrderService::VERSION) {
                        throw new \RuntimeException('Native order requires native payment admission');
                    }
                }
                if (!$order) {
                    throw new \RuntimeException('Order not found.');
                }
                // Provider identity + signed amount must be checked under the
                // same order lock as PENDING -> PROCESSING. This closes the
                // race with simultaneous checkout method changes or callbacks.
                if ($paymentId !== null && (int) $order->payment_id !== $paymentId) {
                    throw new \RuntimeException('Payment provider mismatch');
                }
                if ($verifiedAmountMinor !== null && (
                    $verifiedAmountMinor <= 0
                    || (int) $order->total_amount + (int) ($order->handling_amount ?? 0) !== $verifiedAmountMinor
                )) {
                    throw new \RuntimeException('Verified payment amount mismatch');
                }
                if ((int) $order->status !== Order::STATUS_PENDING) {
                    // A different provider transaction must never be accepted
                    // even when both requests observed PENDING before locking.
                    $sameCallback = in_array((int) $order->status, [
                        Order::STATUS_PROCESSING, Order::STATUS_COMPLETED,
                    ], true) && $order->callback_no !== null
                        && hash_equals((string) $order->callback_no, $callbackNo);
                    if (!$sameCallback) {
                        throw new \RuntimeException('Payment transition conflict');
                    }
                    return [$order, false];
                }

                $order->status = Order::STATUS_PROCESSING;
                $order->paid_at = time();
                $order->callback_no = $callbackNo;
                if (!$order->save()) {
                    throw new \RuntimeException('Failed to save order status.');
                }

                return [$order, true];
            };
            [$order, $shouldDispatch] = $native
                ? app(PublishedOrderService::class)->transitionOrder((int) $this->order->id, $transition)
                : DB::transaction(fn () => $transition());

            $this->order = $order;

            if ($shouldDispatch) {
                // The paid state is already committed. A queue outage must not
                // turn a valid payment callback into a failure response.
                // check:order will recover PROCESSING orders when it runs.
                try {
                    // Dispatch immediately, while we are inside the try/catch.
                    // PendingDispatch dispatches from its destructor, which can
                    // defer exceptions beyond the intended error boundary.
                    Bus::dispatch((new OrderHandleJob($order->trade_no))->onConnection('redis'));
                } catch (\Throwable $exception) {
                    Log::error('Paid order queued for scheduler recovery after dispatch failure', [
                        'order_id' => $order->id,
                        'trade_no' => $order->trade_no,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        } catch (\App\Domains\AuthorityMigration\AuthorityFenceException) {
            Log::error('AUTHORITY_ORDER_PAID_FENCE_REJECTED');
            return false;
        } catch (\Exception $e) {
            Log::error($e);
            return false;
        }
        return true;
    }

    public function cancel(): bool
    {
        try {
            if (Order::query()->useWritePdo()->whereKey($this->order->id)
                ->value('purchase_terms_version') === PublishedOrderService::VERSION) {
                $cancelled = app(PublishedOrderService::class)->transitionOrder((int) $this->order->id,
                    static function (Order $order): ?Order {
                        if ((int) $order->status !== Order::STATUS_PENDING) return null;
                        $order->status = Order::STATUS_CANCELLED;
                        $order->saveOrFail();
                        return $order;
                    });
                if ($cancelled === null) return false;
                $this->order = $cancelled;
                return true;
            }
            $cancelledOrder = DB::transaction(function () {
                app(\App\Domains\AuthorityMigration\AuthorityWriterFence::class)->admit('order.cancel');
                $order = $this->lockCurrentOrderWhenStatus(Order::STATUS_PENDING);
                if (!$order) {
                    return null;
                }
                if ($order->purchase_terms_version === PublishedOrderService::VERSION) {
                    throw new \RuntimeException('Native order requires native cancellation admission');
                }

                HookManager::call('order.cancel.before', $order);

                $order->status = Order::STATUS_CANCELLED;
                if (!$order->save()) {
                    throw new \RuntimeException('Failed to save order status.');
                }
                $capture = null;
                $captureBefore = null;
                if ($order->balance_amount) {
                    $capture = app(LegacyUserMutationCapture::class);
                    $captureBefore = $capture->beginOrderCancellationRefund(
                        (int) $order->user_id, (int) $order->id, $order->getRawOriginal('balance_amount'));
                    $userService = new UserService();
                    if (!$userService->addBalance($order->user_id, $order->balance_amount)) {
                        throw new \RuntimeException('Failed to add balance.');
                    }
                }

                // A coupon slot was reserved at order creation, not at payment.
                // Cancellation must release it exactly once while PENDING is
                // locked; duplicate cancel/late webhook cannot restore twice.
                if ($order->coupon_id !== null) {
                    $coupon = Coupon::whereKey($order->coupon_id)->lockForUpdate()->first();
                    if ($coupon && $coupon->limit_use !== null) {
                        $coupon->limit_use = (int) $coupon->limit_use + 1;
                        $coupon->saveOrFail();
                    }
                }
                $capture?->appendOrderCancellationRefund($captureBefore);
                return $order;
            });

            if (!$cancelledOrder) {
                return false;
            }

            $this->order = $cancelledOrder;
            HookManager::call('order.cancel.after', $cancelledOrder);
            return true;
        } catch (\App\Domains\AuthorityMigration\AuthorityFenceException) {
            Log::error('AUTHORITY_ORDER_CANCEL_FENCE_REJECTED');
            return false;
        } catch (LegacyCaptureShadowException) {
            Log::error('LEGACY_ORDER_CANCELLATION_CAPTURE_FAILED');
            return false;
        } catch (\Exception $e) {
            Log::error('ORDER_CANCELLATION_FAILED');
            return false;
        }
    }

    private function setSpeedLimit($speedLimit)
    {
        $this->user->speed_limit = $speedLimit;
    }

    private function setDeviceLimit($deviceLimit)
    {
        $this->user->device_limit = $deviceLimit;
    }

    private function resetPurchasedTraffic(): bool
    {
        $result = app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER);
        if ($this->purchasedGrant !== null && !$result) {
            throw new \RuntimeException('ORDER_PURCHASE_RESET_FAILED');
        }
        return $result;
    }

    private function buyByPeriod(Order $order, Plan $plan)
    {
        // change plan process
        if ((int) $order->type === Order::TYPE_UPGRADE) {
            $this->user->expired_at = time();
        }
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        // 从一次性转换到循环或者新购的时候，重置流量
        if ($this->user->expired_at === NULL || $order->type === Order::TYPE_NEW_PURCHASE)
            $this->resetPurchasedTraffic();
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = $this->getTime($order->period, $this->user->expired_at);
    }

    private function buyByOneTime(Plan $plan)
    {
        $this->resetPurchasedTraffic();
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = NULL;
    }

    /**
     * 计算套餐到期时间
     * @param string $periodKey
     * @param int $timestamp
     * @return int
     * @throws ApiException
     */
    private function getTime(string $periodKey, ?int $timestamp = null): int
    {
        $timestamp = $timestamp < time() ? time() : $timestamp;
        if ($this->purchasedGrant !== null) {
            $policy = $this->purchasedGrant['calendar_policy'];
            return Carbon::createFromTimestamp($timestamp, $policy['timezone'])
                ->settings(['monthOverflow' => $policy['month_overflow']])
                ->addMonths($this->purchasedGrant['duration_months'])->timestamp;
        }
        $periodKey = PlanService::getPeriodKey($periodKey);

        if (isset(self::STR_TO_TIME[$periodKey])) {
            $months = self::STR_TO_TIME[$periodKey];
            return Carbon::createFromTimestamp($timestamp)->addMonths($months)->timestamp;
        }

        throw new ApiException('无效的套餐周期');
    }

    private function openEvent($eventId)
    {
        switch ((int) $eventId) {
            case 0:
                break;
            case 1:
                app(TrafficResetService::class)->performReset($this->user, TrafficResetLog::SOURCE_ORDER);
                break;
        }
    }

    protected function applyCoupon(string $couponCode): void
    {
        $couponService = new CouponService($couponCode);
        if (!$couponService->use($this->order)) {
            throw new ApiException(__('Coupon failed'));
        }
        $this->order->coupon_id = $couponService->getId();
    }

    /**
     * Summary of handleUserBalance
     * @param User $user
     * @param UserService $userService
     * @return void
     */
    protected function handleUserBalance(User $user, UserService $userService): void
    {
        $remainingBalance = $user->balance - $this->order->total_amount;

        if ($remainingBalance >= 0) {
            if (!$userService->addBalance($this->order->user_id, -$this->order->total_amount)) {
                throw new ApiException(__('Insufficient balance'));
            }
            $this->order->balance_amount = $this->order->total_amount;
            $this->order->total_amount = 0;
        } else {
            if (!$userService->addBalance($this->order->user_id, -$user->balance)) {
                throw new ApiException(__('Insufficient balance'));
            }
            $this->order->balance_amount = $user->balance;
            $this->order->total_amount = $this->order->total_amount - $user->balance;
        }
    }
}
