<?php

namespace App\Domains\AuthorityMigration;

use Illuminate\Database\Eloquent\Model;

final class AuthorityPrepareReceipt extends Model
{
    protected $table='tx_authority_prepare_receipt';
    protected $primaryKey='prepare_key';
    public $incrementing=false;
    protected $keyType='string';
    public $timestamps=false;
    protected $guarded=[];
    protected static function booted():void
    {
        static::updating(fn()=>throw new AuthorityFenceException('AUTHORITY_FENCE_RECEIPT_IMMUTABLE'));
        static::deleting(fn()=>throw new AuthorityFenceException('AUTHORITY_FENCE_RECEIPT_IMMUTABLE'));
    }
}
