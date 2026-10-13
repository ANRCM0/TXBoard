<?php

namespace App\Console\Commands;

use App\Models\CommissionLog;
use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\User;
use App\Services\Subscription\LegacyUserMutationCapture;
use Illuminate\Support\Facades\DB;

class CheckCommission extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:commission';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '返佣服务';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            $this->autoCheck();
            $this->autoPayCommission();
        } catch (\App\Domains\AuthorityMigration\AuthorityFenceException) {
            $this->error('AUTHORITY_COMMISSION_FENCE_REJECTED');
            return self::FAILURE;
        }
    }

    public function autoCheck()
    {
        if ((int)admin_setting('commission_auto_check_enable', 1)) {
            if (config('authority_writer_fence.enabled') !== false) {
                DB::transaction(function (): void {
                    app(\App\Domains\AuthorityMigration\AuthorityWriterFence::class)->admit('commission.review.cron');
            Order::where('commission_status', 0)
                ->where('invite_user_id', '!=', NULL)
                ->where('status', 3)
                ->where('updated_at', '<=', strtotime('-3 day', time()))
                ->update([
                    'commission_status' => 1
                ]);
                });
            } else {
            Order::where('commission_status', 0)
                ->where('invite_user_id', '!=', NULL)
                ->where('status', 3)
                ->where('updated_at', '<=', strtotime('-3 day', time()))
                ->update([
                    'commission_status' => 1
                ]);
            }
        }
    }

    public function autoPayCommission()
    {
        $orders = Order::where('commission_status', 1)
            ->where('invite_user_id', '!=', NULL)
            ->get();
        foreach ($orders as $order) {
            try{
                DB::beginTransaction();
                if (config('authority_writer_fence.enabled') !== false) app(\App\Domains\AuthorityMigration\AuthorityWriterFence::class)->admit('commission.distribute');
                if (!$this->payHandle($order->invite_user_id, $order)) {
                    DB::rollBack();
                    continue;
                }
                $order->commission_status = 2;
                if (!$order->save()) {
                    DB::rollBack();
                    continue;
                }
                DB::commit();
            } catch (\App\Domains\AuthorityMigration\AuthorityFenceException $e) {
                if (DB::transactionLevel() > 0) DB::rollBack();
                \Illuminate\Support\Facades\Log::error('AUTHORITY_COMMISSION_FENCE_REJECTED');
                throw $e;
            } catch (\Exception $e){
                DB::rollBack();
                throw $e;
            }
        }
    }

    public function payHandle($inviteUserId, Order $order)
    {
        if (config('authority_writer_fence.enabled') !== false) app(\App\Domains\AuthorityMigration\AuthorityAdmissionRegistry::class)->current()->assertValid();
        $level = 3;
        if ((int)admin_setting('commission_distribution_enable', 0)) {
            $commissionShareLevels = [
                0 => (int)admin_setting('commission_distribution_l1'),
                1 => (int)admin_setting('commission_distribution_l2'),
                2 => (int)admin_setting('commission_distribution_l3')
            ];
        } else {
            $commissionShareLevels = [
                0 => 100
            ];
        }
        for ($l = 0; $l < $level; $l++) {
            $inviter = config('legacy_capture_shadow.enabled') === false
                ? User::find($inviteUserId)
                : User::lockForUpdate()->find($inviteUserId);
            if (!$inviter) continue;
            if (!isset($commissionShareLevels[$l])) continue;
            $commissionBalance = $order->commission_balance * ($commissionShareLevels[$l] / 100);
            if (!$commissionBalance) continue;
            $capture = app(LegacyUserMutationCapture::class);
            $captureBefore = $capture->beginCommissionDistribution((int) $inviter->id, (int) $order->id);
            if ((int)admin_setting('withdraw_close_enable', 0)) {
                $inviter->increment('balance', $commissionBalance);
            } else {
                $inviter->increment('commission_balance', $commissionBalance);
            }
            if (!$inviter->save()) {
                DB::rollBack();
                return false;
            }
            CommissionLog::create([
                'invite_user_id' => $inviteUserId,
                'user_id' => $order->user_id,
                'trade_no' => $order->trade_no,
                'order_amount' => $order->total_amount,
                'get_amount' => $commissionBalance
            ]);
            $inviteUserId = $inviter->invite_user_id;
            // update order actual commission balance
            $order->actual_commission_balance = $order->actual_commission_balance + $commissionBalance;
            $capture->appendCommissionDistribution($captureBefore);
        }
        return true;
    }

}
