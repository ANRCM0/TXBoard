<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Initialize uTLS defaults for existing VLESS Reality server settings.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tx_server')) {
            return;
        }

        DB::table('tx_server')
            ->where('type', 'vless')
            ->orderBy('id')
            ->chunkById(200, function ($servers) {
                foreach ($servers as $server) {
                    $settings = json_decode($server->protocol_settings ?? '', true);
                    if (!is_array($settings) || (int) ($settings['tls'] ?? 0) != 2) {
                        continue;
                    }

                    $existing = $settings['utls'] ?? null;
                    if (is_array($existing) && ($existing['enabled'] ?? false) === true) {
                        continue;
                    }

                    $settings['utls'] = [
                        'enabled' => true,
                        'fingerprint' => is_array($existing) && !empty($existing['fingerprint'])
                            ? $existing['fingerprint']
                            : 'chrome',
                    ];

                    DB::table('tx_server')
                        ->where('id', $server->id)
                        ->update(['protocol_settings' => json_encode($settings)]);
                }
            });
    }

    public function down(): void
    {
    }
};
