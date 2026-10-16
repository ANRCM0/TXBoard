<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Domains\Installation\InstallationAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Command;

class CheckTicket extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:ticket';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '工单检查任务';

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
        $cutoff = time() - 24 * 3600;
        Ticket::where('status', 0)
            ->where('updated_at', '<=', $cutoff)
            ->where('reply_status', Ticket::REPLY_STATUS_REPLIED)
            ->lazyById(200)
            ->each(function ($ticket) use ($cutoff) {
                DB::transaction(static function () use ($ticket, $cutoff): void {
                    app(InstallationAuthority::class)->assertLegacyWriter();
                    $current = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->first();
                    if ($current === null || (int) $current->status !== Ticket::STATUS_OPENING
                        || (int) $current->updated_at > $cutoff
                        || (int) $current->reply_status !== Ticket::REPLY_STATUS_REPLIED
                        || (int) $current->user_id === (int) $current->last_reply_user_id) {
                        return;
                    }
                    $current->status = Ticket::STATUS_CLOSED;
                    $current->saveOrFail();
                });
            });
    }
}
