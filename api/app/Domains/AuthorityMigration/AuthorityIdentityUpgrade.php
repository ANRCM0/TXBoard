<?php

namespace App\Domains\AuthorityMigration;

use Illuminate\Database\Eloquent\Model;

final class AuthorityIdentityUpgrade extends Model
{
    protected $table='tx_authority_identity_upgrade';protected $primaryKey='upgrade_key';
    public $incrementing=false;protected $keyType='string';public $timestamps=false;protected $guarded=[];
    protected static function booted():void
    {
        static::updating(fn()=>throw new AuthorityFenceException('AUTHORITY_IDENTITY_RECEIPT_IMMUTABLE'));
        static::deleting(fn()=>throw new AuthorityFenceException('AUTHORITY_IDENTITY_RECEIPT_IMMUTABLE'));
    }
}
