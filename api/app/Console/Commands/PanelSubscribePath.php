<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PanelSubscribePath extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'panel:subscribe-path
        {--export : Print a shell assignment for the gateway environment}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Print the panel subscription path so the gateway config can be rendered from it';

    /**
     * Execute the console command.
     *
     * The gateway (web/Caddyfile) matches a single path segment for
     * /{subscribe_path}/{token}. Rendering SUBSCRIBE_PATH from this command
     * keeps both sides in sync instead of relying on a hand-edited env value.
     */
    public function handle(): int
    {
        $path = trim((string) admin_setting('subscribe_path', 's'), "/ \t\n\r\0\x0B");

        if ($path === '' || str_contains($path, '/')) {
            $this->error('The panel subscribe_path must be a single non-empty path segment.');
            return self::FAILURE;
        }

        if ($this->option('export')) {
            $this->line('TXBOARD_SUBSCRIBE_PATH=' . escapeshellarg($path));
            return self::SUCCESS;
        }

        $this->line($path);

        return self::SUCCESS;
    }
}
