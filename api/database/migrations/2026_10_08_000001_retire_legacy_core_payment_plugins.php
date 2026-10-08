<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RETIRED_PLUGIN_CODES = [
        'btcpay',
        'coinbase',
        'coin_payments',
        'mgate',
    ];

    private const RETIRED_PAYMENT_METHODS = [
        'BTCPay',
        'Coinbase',
        'CoinPayments',
        'MGate',
    ];

    public function up(): void
    {
        // Disabling is intentional: preserve all merchant configs, credentials,
        // orders and callback history for a future standalone plugin reinstall.
        if (Schema::hasTable('v2_plugins')) {
            DB::table('v2_plugins')
                ->whereIn('code', self::RETIRED_PLUGIN_CODES)
                ->update(['is_enabled' => false]);
        }

        if (Schema::hasTable('v2_payment')) {
            DB::table('v2_payment')
                ->whereIn('payment', self::RETIRED_PAYMENT_METHODS)
                ->update(['enable' => false]);
        }
    }

    public function down(): void
    {
        // Never silently re-enable retired payment integrations on rollback.
        // Previously enabled methods require review before manual reactivation.
    }
};
