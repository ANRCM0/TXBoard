<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Database\NativeSchemaPreflight;
use Illuminate\Console\Command;

final class AssertNativeSchema extends Command
{
    protected $signature = 'txboard:assert-native-schema';
    protected $description = 'Refuse to boot with incompatible or partially initialized database schema';

    public function handle(): int
    {
        try {
            NativeSchemaPreflight::assertReady();
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Native schema validation failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
