<?php

declare(strict_types=1);

/**
 * Destructive DDL ONLY against dedicated ephemeral probe tables.
 * Never touch application tables or production databases.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\Database\AtomicNativeRename;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (getenv('APP_ENV') !== 'testing' || DB::connection()->getDriverName() !== 'mysql') {
    throw new RuntimeException('Only allowed on MySQL with APP_ENV=testing');
}
$legacy = ['v2_rename_probe_a', 'v2_rename_probe_b'];
$native = ['tx_rename_probe_a', 'tx_rename_probe_b'];
foreach (array_merge($legacy, $native) as $table) {
    if (Schema::hasTable($table)) {
        throw new RuntimeException('Probe table already exists; refusing to alter: ' . $table);
    }
}
$plan = [
    'schemaVersion' => 1, 'kind' => 'native-table-cutover-plan',
    'executable' => true, 'requiresManualApproval' => false, 'blockers' => [],
    'proposedRenames' => [
        ['from' => $legacy[0], 'to' => $native[0]],
        ['from' => $legacy[1], 'to' => $native[1]],
    ],
];
try {
    foreach ($legacy as $i => $table) {
        DB::statement('CREATE TABLE `' . $table . '` (id BIGINT PRIMARY KEY, amount BIGINT NOT NULL) ENGINE=InnoDB');
        DB::table($table)->insert(['id' => $i + 1, 'amount' => ($i + 1) * 127]);
    }
    $before = array_map(fn ($table) => DB::table($table)->first(), $legacy);
    DB::statement(AtomicNativeRename::sql($plan, $legacy, 'up'));
    foreach ($native as $i => $table) {
        if (!Schema::hasTable($table) || Schema::hasTable($legacy[$i])) {
            throw new RuntimeException('Forward table rename failed');
        }
        if ((int) DB::table($table)->value('amount') !== (int) $before[$i]->amount) {
            throw new RuntimeException('Forward rename lost row values');
        }
    }
    DB::statement(AtomicNativeRename::sql($plan, $native, 'down'));
    foreach ($legacy as $i => $table) {
        if (!Schema::hasTable($table) || Schema::hasTable($native[$i])) {
            throw new RuntimeException('Reverse table rename failed');
        }
        if ((int) DB::table($table)->value('amount') !== (int) $before[$i]->amount) {
            throw new RuntimeException('Reverse rename lost row values');
        }
    }
    echo "Atomic MySQL two-table forward/rollback roundtrip passed.\n";
} finally {
    // Cleanup only the reserved probe names. No application tables are touched.
    foreach (array_merge($legacy, $native) as $table) {
        if (Schema::hasTable($table)) {
            Schema::drop($table);
        }
    }
}
