<?php

namespace Tests\Feature\Installation;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\Installation\InstallationAuthorityException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InstallationBootstrapMysqlTest extends TestCase
{
    public function test_twelve_independent_bootstrap_sessions_create_one_immutable_installation(): void
    {
        if (getenv('CORE_INSTALLATION_TEST_MYSQL') !== '1' || !function_exists('proc_open')) {
            $this->markTestSkipped('Requires its own empty MySQL bootstrap fixture database and child processes.');
        }
        $connection = config('database.connections.mysql');
        if ($connection['database'] !== 'txboard_c2_bootstrap_test') {
            throw new \RuntimeException('INSTALLATION_BOOTSTRAP_DEDICATED_DATABASE_REQUIRED');
        }
        config(['database.connections.installation_bootstrap_testing' => $connection,
            'database.default' => 'installation_bootstrap_testing',
            'core_installation.mode' => 'legacy', 'core_installation.worker_epoch' => '1']);
        DB::purge('installation_bootstrap_testing');
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));
        $this->assertSame(0, DB::table('tx_authority_writer_fence')->count(), 'Fixture must be empty, not a replay of an earlier run.');
        $env = array_replace(getenv(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => $connection['database'], 'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'], 'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'], 'DB_SOCKET' => (string) ($connection['unix_socket'] ?? ''),
            'CACHE_DRIVER' => 'array', 'SETTING_CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
            'CORE_INSTALLATION_MODE' => 'legacy', 'CORE_INSTALLATION_EPOCH' => '1',
        ]);
        $workers = [];
        try {
            for ($index = 0; $index < 12; $index++) {
                $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/InstallationBootstrap/worker.php')],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
                $this->assertIsResource($process);
                fclose($pipes[0]);
                stream_set_timeout($pipes[1], 60);
                stream_set_timeout($pipes[2], 60);
                $workers[] = [$process, $pipes[1], $pipes[2]];
            }
            $first = null;
            foreach ($workers as [$process, $stdout, $stderr]) {
                $output = stream_get_contents($stdout);
                $errors = stream_get_contents($stderr);
                $this->assertFalse(stream_get_meta_data($stdout)['timed_out']);
                fclose($stdout);
                fclose($stderr);
                $this->assertSame(0, proc_close($process), 'Independent bootstrap failed: '.$output.$errors);
                $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
                $first ??= $result;
                $this->assertSame($first, $result);
                $this->assertSame('native', $result['mode']);
                $this->assertSame('initializing', $result['phase']);
            }
            $this->assertSame(1, DB::table('tx_authority_writer_fence')->count());
            $this->assertSame($first, app(InstallationAuthority::class)->inspect());
            try {
                app(InstallationAuthority::class)->assertRuntime();
                $this->fail('A stale legacy process must not admit the persisted native installation.');
            } catch (InstallationAuthorityException $error) {
                $this->assertSame('INSTALLATION_AUTHORITY_WORKER_MISMATCH', $error->getMessage());
            }
        } finally {
            foreach ($workers as [$process, $stdout, $stderr]) {
                if (is_resource($stdout)) fclose($stdout);
                if (is_resource($stderr)) fclose($stderr);
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            DB::purge('installation_bootstrap_testing');
        }
    }
}
