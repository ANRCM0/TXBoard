<?php

// Disposable B5.2/B5.3/B5.4/B5.5 fixture; never registered as an HTTP endpoint.
$api = realpath($argv[1] ?? '');
if (!$api || !is_file($api.'/vendor/autoload.php')) throw new RuntimeException('Pass isolated Core api');
require $api.'/vendor/autoload.php';
$app = require $api.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'txboard_internal_test') {
    throw new RuntimeException('Exclusive disposable txboard_internal_test required');
}
$user = App\Models\User::query()->where('email', 'internal-user@example.test')->firstOrFail();
$order = App\Models\Order::query()->where('trade_no', 'internal-discounted-order')->firstOrFail();
match ($argv[2] ?? '') {
    'email-prepare' => admin_setting(['captcha_enable' => 0, 'email_whitelist_enable' => 0,
        'email_whitelist_suffix' => ['example.test'], 'email_host' => '',
        'app_name' => 'Local Email Fixture', 'app_url' => 'https://example.test']),
    'email-whitelist' => admin_setting(['captcha_enable' => 0, 'email_whitelist_enable' => 1,
        'email_whitelist_suffix' => ['other.test']]),
    'email-state' => (function (): void {
        $email = 'internal-user@example.test';
        $codeKey = App\Utils\CacheKey::get('EMAIL_VERIFY_CODE', $email);
        $cooldownKey = App\Utils\CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $email);
        $cache = Illuminate\Support\Facades\Cache::store('redis');
        $prefix = $cache->getStore()->getPrefix();
        $redis = $cache->getStore()->connection();
        $code = $cache->get($codeKey);
        $audits = Illuminate\Support\Facades\DB::table('tx_internal_readonly_audits')
            ->where('operation', 'auth.email-code.send')->get();
        if ($audits->contains(fn ($row) => (int) $row->actor_id !== 0)) {
            throw new RuntimeException('Invalid email audit actor');
        }
        echo json_encode(['mail_rows' => App\Models\MailLog::count(),
            'audit_rows' => $audits->count(), 'audit_actor_ids' => $audits->isEmpty() ? [] : [0],
            'code_present' => (is_int($code) || is_string($code)) && preg_match('/\A[0-9]{6}\z/', (string) $code) === 1,
            'code_ttl' => (int) $redis->ttl($prefix.$codeKey), 'cooldown_ttl' => (int) $redis->ttl($prefix.$cooldownKey),
            'business' => ['users' => App\Models\User::count(), 'orders' => App\Models\Order::count(),
                'sessions' => Laravel\Sanctum\PersonalAccessToken::count(),
                'balances' => App\Models\User::orderBy('id')->get(['id', 'balance', 'commission_balance'])->toArray()]]); exit;
    })(),
    'login-prepare' => admin_setting(['captcha_enable' => 0, 'password_limit_enable' => 1,
        'password_limit_count' => 5, 'password_limit_expire' => 60, 'stop_register' => 1,
        'login_with_mail_link_enable' => 0, 'try_out_plan_id' => 0]),
    'login-captcha' => admin_setting(['captcha_enable' => 1, 'captcha_type' => 'turnstile']),
    'login-rate-reset' => Illuminate\Support\Facades\Cache::store('redis')->flush(),
    'login-state' => (function () use ($user): void {
        $rows = $user->tokens()->orderBy('id')->get();
        $last = $rows->last();
        foreach ($rows as $token) {
            if (!preg_match('/\A[0-9a-f]{64}\z/D', $token->token)) throw new RuntimeException('Unexpected persisted token shape');
        }
        echo json_encode(['user_tokens' => $rows->count(), 'last_id' => $last?->id,
            'last_year_ttl' => $last && $last->expires_at && $last->expires_at->diffInDays(now(), true) >= 364,
            'last_abilities' => $last?->abilities, 'last_login_at' => $user->fresh()->last_login_at]); exit;
    })(),
    'public-prepare' => (function (): void {
        App\Models\Plan::whereKey(1)->update(['capacity_limit' => null]);
        foreach ([['Public second', true, true, null, 2], ['Hidden public plan', false, true, null, 0],
                  ['Unsold public plan', true, false, null, 0], ['Full public plan', true, true, 0, 0]] as [$name, $show, $sell, $cap, $sort]) {
            App\Models\Plan::create(['name' => $name, 'group_id' => 1, 'transfer_enable' => 2,
                'show' => $show, 'sell' => $sell, 'renew' => true, 'capacity_limit' => $cap, 'sort' => $sort,
                'content' => 'Public fixture content', 'tags' => ['public'], 'prices' => ['monthly' => 3.17, 'quarterly' => 9]]);
        }
        admin_setting(['app_name' => 'Internal public fixture', 'captcha_enable' => 1, 'captcha_type' => 'turnstile',
            'turnstile_site_key' => 'public-site-key', 'turnstile_secret_key' => 'must-never-return-provider-secret']);
    })(),
    'public-overflow' => App\Models\Plan::whereKey(1)->update(['transfer_enable' => 10000000]),
    'public-restore' => App\Models\Plan::whereKey(1)->update(['transfer_enable' => 1, 'content' => '']),
    'public-oversize' => (function (): void {
        App\Models\Plan::whereKey(1)->update(['content' => str_repeat('x', 64000)]);
        App\Models\Plan::where('name', 'Public second')->update(['content' => str_repeat('y', 3000)]);
    })(),
    'public-audit' => (function (): void {
        $rows = Illuminate\Support\Facades\DB::table('tx_internal_readonly_audits')->whereIn('operation', ['public.site-config.get', 'public.plan.list'])->get();
        if ($rows->isEmpty() || $rows->contains(fn ($row) => (int) $row->actor_id !== 0)) throw new RuntimeException('Anonymous audit marker invalid');
        echo json_encode(['public_audit_rows' => $rows->count(), 'actor_ids' => [0]]); exit;
    })(),
    'decorate' => (function () use ($user, $order): void {
        $user->update(['telegram_id' => -1234567, 'plan_id' => $order->plan_id, 'balance' => 1234,
            'commission_balance' => 567, 'u' => 100, 'd' => 200, 'transfer_enable' => 1073741824, 'expired_at' => time()+86400]);
        $order->update(['payment_id' => 7, 'balance_amount' => 300, 'discount_amount' => 150]);
    })(),
    'display-reads' => (function () use ($user): void {
        foreach ([[$user->id, 0], [$user->id, 1], [$user->id+100, 0]] as [$owner, $status]) {
            App\Models\Ticket::create(['user_id' => $owner, 'subject' => 'Fixture ticket', 'level' => 1, 'status' => $status]);
        }
        App\Models\User::create(['email' => 'internal-invited@example.test', 'password' => 'fixture-only',
            'uuid' => (string) Illuminate\Support\Str::uuid(), 'token' => 'private-invited-token', 'invite_user_id' => $user->id]);
        foreach ([['Disabled', false, 0, 0, 0], ['Fixed method', true, 2, 17, 2.5], ['Zero method', true, 1, 0, 0]]
                 as [$name, $enabled, $sort, $fee, $percent]) {
            App\Models\Payment::create(['uuid' => str_replace('-', '', (string) Illuminate\Support\Str::uuid()), 'name' => $name, 'payment' => 'NotAProvider', 'enable' => $enabled,
                'sort' => $sort, 'handling_fee_fixed' => $fee, 'handling_fee_percent' => $percent,
                'config' => ['secret' => 'must-never-return-provider-secret']]);
        }
    })(),
    'notice-reads' => (function (): void {
        foreach ([['Hidden', 0, false], ['First visible', 1, true], ['Second visible', 1, true], ['Last visible', 2, true]] as [$name, $sort, $show]) {
            $n = App\Models\Notice::create(['title' => $name, 'content' => '<p>Fixture content</p>', 'tags' => ['fixture'], 'sort' => $sort, 'show' => $show]);
            Illuminate\Support\Facades\DB::table('tx_notice')->where('id', $n->id)->update(['created_at' => 1791763200]);
        }
    })(),
    'summary-plan-missing' => $user->update(['plan_id' => $order->plan_id + 100]),
    'summary-plan-restore' => $user->update(['plan_id' => $order->plan_id]),
    'notice-oversize' => (function (): void {
        $updated = Illuminate\Support\Facades\DB::table('tx_notice')->where('title', 'Second visible')->update(['content' => str_repeat('x', 64000)]);
        // Both rows fit native TEXT while their combined response exceeds the envelope cap.
        $updated += Illuminate\Support\Facades\DB::table('tx_notice')->where('title', 'First visible')->update(['content' => str_repeat('x', 2000)]);
        if ($updated !== 2) throw new RuntimeException('Oversize fixture did not update both visible rows');
    })(),
    'notice-restore' => Illuminate\Support\Facades\DB::table('tx_notice')->whereIn('title', ['Second visible', 'First visible'])->update(['content' => '<p>Fixture content</p>']),
    'write-prepare' => (function () use ($user): void {
        Illuminate\Support\Facades\DB::table('tx_order')->where('user_id', $user->id)->whereIn('status', [0, 1])->update(['status' => 2]);
        $user->update(['balance' => 300]);
        App\Models\Plan::query()->update(['capacity_limit' => null]);
    })(),
    'write-state' => (function () use ($user): void {
        $order = App\Models\Order::where('user_id', $user->id)->orderByDesc('id')->firstOrFail();
        echo json_encode(['balance' => (int) $user->fresh()->balance, 'trade_no' => $order->trade_no,
            'wallet_reserved' => (int) $order->balance_amount, 'provider_due' => (int) $order->total_amount,
            'claims' => Illuminate\Support\Facades\DB::table('idempotency_keys')->where('user_id', $user->id)->count()]);
        exit;
    })(),
    'write-other-state' => (function (): void {
        $other = App\Models\User::where('email', 'internal-other@example.test')->firstOrFail();
        $orders = App\Models\Order::where('user_id', $other->id)->get();
        echo json_encode(['orders' => $orders->count(), 'trade_no' => $orders->first()?->trade_no,
            'claims' => Illuminate\Support\Facades\DB::table('idempotency_keys')->where('user_id', $other->id)->count()]);
        exit;
    })(),
    'write-expire' => (function () use ($user): void {
        $order = App\Models\Order::where('user_id', $user->id)->orderByDesc('id')->firstOrFail();
        if (!(new App\Services\OrderService($order))->cancel()) throw new RuntimeException('Fixture order cancellation failed');
        Illuminate\Support\Facades\DB::table('idempotency_keys')->where('user_id', $user->id)->update(['expires_at' => time()-1]);
    })(),
    'audit-write-fail' => Illuminate\Support\Facades\DB::unprepared("CREATE TRIGGER internal_write_audit_fault BEFORE INSERT ON tx_internal_readonly_audits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture-audit-failure'"),
    'audit-write-restore' => Illuminate\Support\Facades\DB::unprepared('DROP TRIGGER internal_write_audit_fault'),
    'overflow' => $user->update(['u' => 9007199254740992]),
    'restore-values' => $user->update(['u' => 100]),
    'plan-missing' => $order->update(['plan_id' => $order->plan_id + 100]),
    'list-orders' => (function () use ($user, $order): void {
        foreach ([['internal-list-unpaid', 0, $order->plan_id, $user->id],
                  ['internal-list-missing-plan', 4, $order->plan_id + 100, $user->id],
                  ['internal-list-foreign', 4, $order->plan_id, $user->id + 100]] as [$trade, $status, $plan, $owner]) {
            App\Models\Order::create(['user_id' => $owner, 'plan_id' => $plan, 'trade_no' => $trade,
                'period' => App\Models\Plan::PERIOD_MONTHLY, 'type' => 1, 'status' => $status,
                'total_amount' => 1200, 'callback_no' => 'must-never-return-callback']);
        }
        Illuminate\Support\Facades\DB::table('tx_order')->update(['created_at' => 1791763200]);
    })(),
    default => throw new RuntimeException('Unknown fixture action'),
};
echo json_encode(['ok' => true]);
