<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class WalletRecharge extends Model
{
    
    use \App\Support\Database\ResolvesNativeEloquentTable;
protected $table = 'v2_wallet_recharge';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'amount_minor' => 'integer',
        'fee_minor' => 'integer',
        'status' => 'integer',
        'paid_at' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];

    public const STATUS_PENDING = 0;
    public const STATUS_PAID = 1;
}
