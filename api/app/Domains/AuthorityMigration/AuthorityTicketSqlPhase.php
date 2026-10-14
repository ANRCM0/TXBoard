<?php

namespace App\Domains\AuthorityMigration;

use Illuminate\Support\Facades\DB;

/** Source-only closed operations; delegates exactly one original native SQL phase. */
final class AuthorityTicketSqlPhase
{
    public const USER_TICKET_CREATE='user.ticket.create';
    public const USER_TICKET_REPLY='user.ticket.reply';
    public const USER_TICKET_CLOSE='user.ticket.close';
    public const ADMIN_TICKET_REPLY='admin.ticket.reply';
    public const ADMIN_TICKET_CLOSE='admin.ticket.close';
    public const TICKET_CLOSE_CRON='ticket.close.cron';

    public static function enabled():bool { return config('authority_writer_fence.enabled')!==false; }
    public function run(string $writer,callable $phase,int $attempts=1):mixed
    {
        if(!in_array($writer,array_diff(AuthorityTicketInventory::WRITERS,AuthorityIdentityInventory::WRITERS),true))
            throw new AuthorityFenceException('AUTHORITY_TICKET_WRITER_INVALID');
        if(!self::enabled())return $phase();
        if(DB::transactionLevel()>0){
            app(AuthorityAdmissionRegistry::class)->current()->assertValid();
            return DB::transaction(function()use($writer,$phase){app(AuthorityWriterFence::class)->admit($writer);return $phase();},$attempts);
        }
        return DB::transaction(function()use($writer,$phase){app(AuthorityWriterFence::class)->admit($writer);return $phase();},$attempts);
    }
}
