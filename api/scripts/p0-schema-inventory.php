<?php

declare(strict_types=1);

// Read-only, metadata-only MySQL 8 schema evidence. Never SELECT actual rows.
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\Audit\MySqlSchemaInventory;
use Illuminate\Support\Facades\DB;

$options = getopt('', ['output:']);
if (!isset($options['output']) || !is_string($options['output'])) {
    fwrite(STDERR, "Usage: php scripts/p0-schema-inventory.php --output=/path/to/schema.json\n");
    exit(2);
}
$connection = DB::connection();
if ($connection->getDriverName() !== 'mysql') {
    fwrite(STDERR, "MySQL is required: schema evidence must match production engine.\n");
    exit(2);
}
$schema = $connection->getDatabaseName();
if (!is_string($schema) || $schema === '') {
    fwrite(STDERR, "Missing database schema name\n");
    exit(2);
}

$columns = DB::select(
    'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, DATA_TYPE AS data_type, IS_NULLABLE AS is_nullable
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = ?
     ORDER BY TABLE_NAME, ORDINAL_POSITION',
    [$schema]
);
$indexes = DB::select(
    'SELECT TABLE_NAME AS table_name, INDEX_NAME AS index_name, NON_UNIQUE AS non_unique,
            SEQ_IN_INDEX AS seq_in_index, COLUMN_NAME AS column_name
     FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = ?
     ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
    [$schema]
);
$foreign = DB::select(
    'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name,
            REFERENCED_TABLE_NAME AS referenced_table_name, REFERENCED_COLUMN_NAME AS referenced_column_name
     FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL
     ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION',
    [$schema]
);
$report = MySqlSchemaInventory::build($columns, $indexes, $foreign);
$failed = array_values(array_filter($report['invariants'], static fn (array $row): bool => !$row['passed']));
if (!$report['tables']) {
    fwrite(STDERR, "No tables found; refusing to export empty schema\n");
    exit(1);
}

$output = $options['output'];
$dir = dirname($output);
if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    throw new RuntimeException('Unable to create audit output directory');
}
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($output, $json, LOCK_EX) === false) {
    throw new RuntimeException('Failed to write schema inventory');
}
@chmod($output, 0600);
fwrite(STDOUT, sprintf(
    "P0 MySQL metadata: %d tables, %d checked uniqueness requirements, %d violations.\n",
    count($report['tables']), count($report['invariants']), count($failed)
));
foreach ($failed as $violation) {
    fwrite(STDERR, 'Missing unique index: ' . $violation['table'] . ' (' .
        implode(', ', $violation['unique_columns']) . ")\n");
}
if ($failed) exit(1);
