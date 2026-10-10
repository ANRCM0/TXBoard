<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Commission Log
        if (!Schema::hasTable('tx_commission_log')) {
            Schema::create('tx_commission_log', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('invite_user_id');
                $table->integer('user_id');
                $table->char('trade_no', 36);
                $table->integer('order_amount');
                $table->integer('get_amount');
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        // Invite Code
        if (!Schema::hasTable('tx_invite_code')) {
            Schema::create('tx_invite_code', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('user_id');
                $table->char('code', 32);
                $table->boolean('status')->default(false);
                $table->integer('pv')->default(0);
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        // Knowledge
        if (!Schema::hasTable('tx_knowledge')) {
            Schema::create('tx_knowledge', function (Blueprint $table) {
                $table->integer('id', true);
                $table->char('language', 5)->comment('語言');
                $table->string('category')->comment('分類名');
                $table->string('title')->comment('標題');
                $table->text('body')->comment('內容');
                $table->integer('sort')->nullable()->comment('排序');
                $table->boolean('show')->default(false)->comment('顯示');
                $table->integer('created_at')->comment('創建時間');
                $table->integer('updated_at')->comment('更新時間');
            });
        }

        // Plan
        if (!Schema::hasTable('tx_plan')) {
            Schema::create('tx_plan', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('group_id');
                $table->integer('transfer_enable');
                $table->string('name');
                $table->integer('speed_limit')->nullable();
                $table->boolean('show')->default(false);
                $table->integer('sort')->nullable();
                $table->boolean('renew')->default(true);
                $table->text('content')->nullable();
                $table->integer('month_price')->nullable();
                $table->integer('quarter_price')->nullable();
                $table->integer('half_year_price')->nullable();
                $table->integer('year_price')->nullable();
                $table->integer('two_year_price')->nullable();
                $table->integer('three_year_price')->nullable();
                $table->integer('onetime_price')->nullable();
                $table->integer('reset_price')->nullable();
                $table->integer('reset_traffic_method')->nullable()->comment('重置流量方式:null跟随系统设置、0每月1号、1按月重置、2不重置、3每年1月1日、4按年重置');
                $table->integer('capacity_limit')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        // Server Group
        if (!Schema::hasTable('tx_server_group')) {
            Schema::create('tx_server_group', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('name');
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        // Server Route
        if (!Schema::hasTable('tx_server_route')) {
            Schema::create('tx_server_route', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('remarks');
                $table->text('match');
                $table->string('action', 11);
                $table->string('action_value')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        // stat server
        if (!Schema::hasTable('tx_stat_server')) {
            Schema::create('tx_stat_server', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('server_id')->index('server_id')->comment('节点id');
                $table->char('server_type', 11)->comment('节点类型');
                $table->bigInteger('u');
                $table->bigInteger('d');
                $table->char('record_type', 1)->comment('d day m month');
                $table->integer('record_at')->index('record_at')->comment('记录时间');
                $table->integer('created_at');
                $table->integer('updated_at');

                $table->unique(['server_id', 'server_type', 'record_at'], 'server_id_server_type_record_at');
            });
        }

        // User
        if (!Schema::hasTable('tx_user')) {
            Schema::create('tx_user', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('invite_user_id')->nullable();
                $table->bigInteger('telegram_id')->nullable();
                $table->string('email', 64)->unique('email');
                $table->string('password', 64);
                $table->char('password_algo', 10)->nullable();
                $table->char('password_salt', 10)->nullable();
                $table->integer('balance')->default(0);
                $table->integer('discount')->nullable();
                $table->tinyInteger('commission_type')->default(0)->comment('0: system 1: period 2: onetime');
                $table->integer('commission_rate')->nullable();
                $table->integer('commission_balance')->default(0);
                $table->integer('t')->default(0);
                $table->bigInteger('u')->default(0);
                $table->bigInteger('d')->default(0);
                $table->bigInteger('transfer_enable')->default(0);
                $table->boolean('banned')->default(false);
                $table->boolean('is_admin')->default(false);
                $table->integer('last_login_at')->nullable();
                $table->boolean('is_staff')->default(false);
                $table->integer('last_login_ip')->nullable();
                $table->string('uuid', 36);
                $table->integer('group_id')->nullable();
                $table->integer('plan_id')->nullable();
                $table->integer('speed_limit')->nullable();
                $table->tinyInteger('remind_expire')->nullable()->default(1);
                $table->tinyInteger('remind_traffic')->nullable()->default(1);
                $table->char('token', 32);
                $table->bigInteger('expired_at')->nullable()->default(0);
                $table->text('remarks')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        // Mail Log
        if (!Schema::hasTable('tx_mail_log')) {
            Schema::create('tx_mail_log', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('email', 64);
                $table->string('subject');
                $table->string('template_name');
                $table->text('error')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        // Log
        if (!Schema::hasTable('tx_log')) {
            Schema::create('tx_log', function (Blueprint $table) {
                $table->integer('id', true);
                $table->text('title');
                $table->string('level', 11)->nullable();
                $table->string('host')->nullable();
                $table->string('uri');
                $table->string('method', 11);
                $table->text('data')->nullable();
                $table->string('ip', 128)->nullable();
                $table->text('context')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        // Stat
        if (!Schema::hasTable('tx_stat')) {
            Schema::create('tx_stat', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('record_at');
                $table->char('record_type', 1);
                $table->integer('order_count')->comment('订单数量');
                $table->integer('order_total')->comment('订单合计');
                $table->integer('commission_count');
                $table->integer('commission_total')->comment('佣金合计');
                $table->integer('paid_count');
                $table->integer('paid_total');
                $table->integer('register_count');
                $table->integer('invite_count');
                $table->string('transfer_used_total', 32);
                $table->integer('created_at');
                $table->integer('updated_at');

                if (config('database.default') !== 'sqlite') {
                    $table->unique(['record_at']);
                }
            });
        }

        // stat user
        if (!Schema::hasTable('tx_stat_user')) {
            Schema::create('tx_stat_user', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('user_id');
                $table->decimal('server_rate', 10);
                $table->bigInteger('u');
                $table->bigInteger('d');
                $table->char('record_type', 2);
                $table->integer('record_at');
                $table->integer('created_at');
                $table->integer('updated_at');

                // 如果是不是sqlite才添加多个索引
                if (config('database.default') !== 'sqlite') {
                    $table->index(['user_id', 'server_rate', 'record_at']);
                    $table->unique(['server_rate', 'user_id', 'record_at'], 'server_rate_user_id_record_at');
                }
            });
        }

        // ticket message
        if (!Schema::hasTable('tx_ticket_message')) {
            Schema::create('tx_ticket_message', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('user_id');
                $table->integer('ticket_id');
                $table->text('message');
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        // Order
        if (!Schema::hasTable('tx_order')) {
            Schema::create('tx_order', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('invite_user_id')->nullable();
                $table->integer('user_id');
                $table->integer('plan_id');
                $table->integer('coupon_id')->nullable();
                $table->integer('payment_id')->nullable();
                $table->integer('type')->comment('1新购2续费3升级');
                $table->string('period');
                $table->string('trade_no', 36)->unique('trade_no');
                $table->string('callback_no')->nullable();
                $table->integer('total_amount');
                $table->integer('handling_amount')->nullable();
                $table->integer('discount_amount')->nullable();
                $table->integer('surplus_amount')->nullable()->comment('剩余价值');
                $table->integer('refund_amount')->nullable()->comment('退款金额');
                $table->integer('balance_amount')->nullable()->comment('使用余额');
                $table->text('surplus_order_ids')->nullable()->comment('折抵订单');
                $table->integer('status')->default(0)->comment('0待支付1开通中2已取消3已完成4已折抵');
                $table->integer('commission_status')->default(false)->comment('0待确认1发放中2有效3无效');
                $table->integer('commission_balance')->default(0);
                $table->integer('actual_commission_balance')->nullable()->comment('实际支付佣金');
                $table->integer('paid_at')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        // Payment
        if (!Schema::hasTable('tx_payment')) {
            Schema::create('tx_payment', function (Blueprint $table) {
                $table->integer('id', true);
                $table->char('uuid', 32);
                $table->string('payment', 16);
                $table->string('name');
                $table->string('icon')->nullable();
                $table->text('config');
                $table->string('notify_domain', 128)->nullable();
                $table->integer('handling_fee_fixed')->nullable();
                $table->decimal('handling_fee_percent', 5)->nullable();
                $table->boolean('enable')->default(false);
                $table->integer('sort')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        // Coupon
        if (!Schema::hasTable('tx_coupon')) {
            Schema::create('tx_coupon', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('code');
                $table->string('name');
                $table->integer('type');
                $table->integer('value');
                $table->boolean('show')->default(false);
                $table->integer('limit_use')->nullable();
                $table->integer('limit_use_with_user')->nullable();
                $table->string('limit_plan_ids')->nullable();
                $table->string('limit_period')->nullable();
                $table->integer('started_at');
                $table->integer('ended_at');
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        // Notice
        if (!Schema::hasTable('tx_notice')) {
            Schema::create('tx_notice', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('title');
                $table->text('content');
                $table->boolean('show')->default(false);
                $table->string('img_url')->nullable();
                $table->string('tags')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        // Ticket
        if (!Schema::hasTable('tx_ticket')) {
            Schema::create('tx_ticket', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('user_id');
                $table->string('subject');
                $table->integer('level');
                $table->integer('status')->default(0)->comment('0:已开启 1:已关闭');
                $table->integer('reply_status')->default(1)->comment('0:待回复 1:已回复');
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        // Server Hysteria
        if (!Schema::hasTable('tx_server_hysteria')) {
            Schema::create('tx_server_hysteria', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('group_id');
                $table->string('route_id')->nullable();
                $table->string('name');
                $table->integer('parent_id')->nullable();
                $table->string('host');
                $table->string('port', 11);
                $table->integer('server_port');
                $table->string('tags')->nullable();
                $table->string('rate', 11);
                $table->boolean('show')->default(false);
                $table->integer('sort')->nullable();
                $table->integer('up_mbps');
                $table->integer('down_mbps');
                $table->string('server_name', 64)->nullable();
                $table->boolean('insecure')->default(false);
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        // Server Shadowsocks
        if (!Schema::hasTable('tx_server_shadowsocks')) {
            autoIncrement:
            Schema::create('tx_server_shadowsocks', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('group_id');
                $table->string('route_id')->nullable();
                $table->integer('parent_id')->nullable();
                $table->string('tags')->nullable();
                $table->string('name');
                $table->string('rate', 11);
                $table->string('host');
                $table->string('port', 11);
                $table->integer('server_port');
                $table->string('cipher');
                $table->char('obfs', 11)->nullable();
                $table->string('obfs_settings')->nullable();
                $table->tinyInteger('show')->default(0);
                $table->integer('sort')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }
        // Server Trojan
        if (!Schema::hasTable('tx_server_trojan')) {
            Schema::create('tx_server_trojan', function (Blueprint $table) {
                $table->integer('id', true)->comment('节点ID');
                $table->string('group_id')->comment('节点组');
                $table->string('route_id')->nullable();
                $table->integer('parent_id')->nullable()->comment('父节点');
                $table->string('tags')->nullable()->comment('节点标签');
                $table->string('name')->comment('节点名称');
                $table->string('rate', 11)->comment('倍率');
                $table->string('host')->comment('主机名');
                $table->string('port', 11)->comment('连接端口');
                $table->integer('server_port')->comment('服务端口');
                $table->boolean('allow_insecure')->default(false)->comment('是否允许不安全');
                $table->string('server_name')->nullable();
                $table->boolean('show')->default(false)->comment('是否显示');
                $table->integer('sort')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        // Server Vless
        if (!Schema::hasTable('tx_server_vless')) {
            Schema::create('tx_server_vless', function (Blueprint $table) {
                $table->integer('id', true);
                $table->text('group_id');
                $table->text('route_id')->nullable();
                $table->string('name');
                $table->integer('parent_id')->nullable();
                $table->string('host');
                $table->integer('port');
                $table->integer('server_port');
                $table->integer('tls');
                $table->text('tls_settings')->nullable();
                $table->string('flow', 64)->nullable();
                $table->string('network', 11);
                $table->text('network_settings')->nullable();
                $table->text('tags')->nullable();
                $table->string('rate', 11);
                $table->boolean('show')->default(false);
                $table->integer('sort')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        // Server Vmess
        if (!Schema::hasTable('tx_server_vmess')) {
            Schema::create('tx_server_vmess', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('group_id');
                $table->string('route_id')->nullable();
                $table->string('name');
                $table->integer('parent_id')->nullable();
                $table->string('host');
                $table->string('port', 11);
                $table->integer('server_port');
                $table->tinyInteger('tls')->default(0);
                $table->string('tags')->nullable();
                $table->string('rate', 11);
                $table->string('network', 11);
                $table->text('rules')->nullable();
                $table->text('networkSettings')->nullable();
                $table->text('tlsSettings')->nullable();
                $table->text('ruleSettings')->nullable();
                $table->text('dnsSettings')->nullable();
                $table->boolean('show')->default(false);
                $table->integer('sort')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tx_commission_log');
        Schema::dropIfExists('tx_plan');
        Schema::dropIfExists('tx_user');
        Schema::dropIfExists('tx_mail_log');
        Schema::dropIfExists('tx_log');
        Schema::dropIfExists('tx_stat');
        Schema::dropIfExists('tx_order');
        Schema::dropIfExists('tx_coupon');
        Schema::dropIfExists('tx_notice');
        Schema::dropIfExists('tx_ticket');
        Schema::dropIfExists('tx_settings');
        Schema::dropIfExists('tx_ticket_message');
        Schema::dropIfExists('tx_invite_code');
        Schema::dropIfExists('tx_knowledge');
        Schema::dropIfExists('tx_server_group');
        Schema::dropIfExists('tx_server_route');
        Schema::dropIfExists('tx_stat_server');
        Schema::dropIfExists('tx_stat_user');
        Schema::dropIfExists('tx_server_hysteria');
        Schema::dropIfExists('tx_server_shadowsocks');
        Schema::dropIfExists('tx_server_trojan');
        Schema::dropIfExists('tx_server_vless');
        Schema::dropIfExists('tx_server_vmess');
    }
};
