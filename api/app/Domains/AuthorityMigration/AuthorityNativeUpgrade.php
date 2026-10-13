<?php

namespace App\Domains\AuthorityMigration;

use Illuminate\Database\Eloquent\Model;

final class AuthorityNativeUpgrade extends Model
{
    protected $table='tx_authority_native_upgrade';protected $primaryKey='upgrade_key';
    public $incrementing=false;protected $keyType='string';public $timestamps=false;protected $guarded=[];
    protected static function booted():void
    {
        static::updating(fn()=>throw new AuthorityFenceException('AUTHORITY_NATIVE_RECEIPT_IMMUTABLE'));
        static::deleting(fn()=>throw new AuthorityFenceException('AUTHORITY_NATIVE_RECEIPT_IMMUTABLE'));
    }
}
