<?php

namespace App\Domains\AuthorityMigration;

use Illuminate\Database\Eloquent\Model;

final class AuthorityPostcommitIntent extends Model
{
    protected $table='tx_authority_postcommit_intent';protected $primaryKey='intent_id';
    public $incrementing=false;protected $keyType='string';public $timestamps=false;protected $guarded=[];
    protected static function booted():void
    {
        static::updating(fn()=>throw new AuthorityFenceException('AUTHORITY_POSTCOMMIT_INTENT_IMMUTABLE'));
        static::deleting(fn()=>throw new AuthorityFenceException('AUTHORITY_POSTCOMMIT_INTENT_IMMUTABLE'));
    }
}
