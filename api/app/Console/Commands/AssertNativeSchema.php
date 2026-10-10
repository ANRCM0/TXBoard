<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Database\NativeSchemaPreflight;
use Illuminate\Console\Command;

final class AssertNativeSchema extends Command
{
    protected $signature = 'txboard:assert-native-schema {--allow-unavailable : Allow first boot while database is offline}';
    protected $description = 'Refuse to boot with incompatible or partially initialized database schema';

    public function handle(): int
    {
        try {
            NativeSchemaPreflight::assertReady();
            return self::SUCCESS;
        } catch (\Illuminate\Database\SQLiteDatabaseDoesNotExistException $e) {
            if ($this->option('allow-unavailable')) {
                $this->warn('SQLite database file has not been initialized; allowing installer bootstrap.');
                return self::SUCCESS;
            }
            $this->error('SQLite database unavailable: ' . $e->getMessage());
            return self::FAILURE;
        } catch (\Illuminate\Database\QueryException|\PDOException $e) {
            if ($this->option('allow-unavailable')) {
                $this->warn('Database connection not ready; schema guard will run again during installation/update.');
                return self::SUCCESS;
            }
            $this->error('Database unavailable: ' . $e->getMessage());
            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Native schema validation failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
