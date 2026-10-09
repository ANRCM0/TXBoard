<?php

declare(strict_types=1);

// Safe to run on staging/production: SELECT COUNT and grouped aggregates only.
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domains\Billing\FinancialInvariantAudit;
use Illuminate\Support\Facades\DB;

$options = getopt('', ['output:']);
if (!isset($options['output']) || !is_string($options['output'])) {
    fwrite(STDERR, "Usage: php scripts/p3-billing-audit.php --output=artifacts/p3-billing-audit.json\n");
    exit(2);
}
if (!in_array(DB::connection()->getDriverName(), ['mysql', 'sqlite'], true)) {
    fwrite(STDERR, "Unsupported database driver\n");
    exit(2);
}

$result = app(FinancialInvariantAudit::class)->snapshot();
$output = $options['output'];
$dir = dirname($output);
if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    throw new RuntimeException('Could not create audit directory');
}
if (file_put_contents($output, json_encode($result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    LOCK_EX) === false) {
    throw new RuntimeException('Could not write audit output');
}
@chmod($output, 0600);
fwrite(STDOUT, sprintf(
    "P3 billing audit: %d orders; %d invariant anomalies. External provider reconciliation still required.\n",
    $result['orders_scanned'], array_sum($result['violations'])
));
if (!$result['passed']) {
    exit(1);
}
