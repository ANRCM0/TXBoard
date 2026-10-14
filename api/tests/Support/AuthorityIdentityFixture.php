<?php

namespace Tests\Support;

use App\Domains\AuthorityMigration\AuthorityFenceException;
use App\Domains\AuthorityMigration\AuthorityIdentityInventory;
use App\Domains\AuthorityMigration\AuthorityNativeInventory;
use App\Domains\AuthorityMigration\AuthorityWriterFence;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Never points a destructive fixture at an application database. */
trait AuthorityIdentityFixture
{
    private function identityMysql(): bool
    {
        return getenv('AUTHORITY_IDENTITY_MYSQL') === '1';
    }

    private function identityConnection(): string
    {
        return $this->identityMysql() ? 'identity_fence_mysql' : 'identity_fence_sqlite';
    }

    private function configureIdentityDatabase(): void
    {
        $name = $this->identityConnection();
        $connection = $this->identityMysql() ? config('database.connections.mysql')
            : array_replace(config('database.connections.sqlite'), ['database' => ':memory:']);
        if ($this->identityMysql()) {
            if (($connection['database'] ?? '') !== 'txboard_identity_fence_test'
                || ($connection['username'] ?? '') !== 'txboard_identity_fence'
                || !in_array($connection['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)
                || !empty($connection['url']) || !empty($connection['unix_socket'])) {
                throw new \RuntimeException('Identity fixture requires its dedicated loopback database and account.');
            }
        }
        config(['database.default' => $name, 'database.connections.'.$name => $connection]);
        DB::purge($name);
        if ($this->identityMysql()) $this->clearIdentityDatabase();
    }

    private function clearIdentityDatabase(): void
    {
        $connection = DB::connection($this->identityConnection());
        if ($connection->getDatabaseName() !== 'txboard_identity_fence_test') {
            throw new \RuntimeException('Refusing to clear any other database.');
        }
        $schema = Schema::connection($this->identityConnection());
        $schema->disableForeignKeyConstraints();
        try {
            foreach ($schema->getTableListing() as $table) $schema->drop($table);
        } finally {
            $schema->enableForeignKeyConstraints();
        }
    }

    private function fence(): AuthorityWriterFence
    {
        return app(AuthorityWriterFence::class);
    }

    private function native(): void
    {
        $this->fence()->initialize();
        $this->fence()->expand('identity-parent-inventory', '1');
        $this->fence()->extendFinance('identity-parent-finance', '2');
        $this->fence()->extendNative('identity-parent-native', '3');
        config(['authority_writer_fence' => ['enabled' => true, 'worker_epoch' => '4',
            'worker_role' => 'legacy', 'worker_scope' => AuthorityNativeInventory::SCOPE,
            'worker_version' => AuthorityNativeInventory::VERSION]]);
    }

    private function identity(): void
    {
        $this->native();
        $this->fence()->extendIdentity('identity-fixture', '4');
        config(['authority_writer_fence' => ['enabled' => true, 'worker_epoch' => '5',
            'worker_role' => 'legacy', 'worker_scope' => AuthorityIdentityInventory::SCOPE,
            'worker_version' => AuthorityIdentityInventory::VERSION]]);
    }

    private function stop(): void
    {
        $this->fence()->prepare('identity-stop', '5');
    }

    private function user(array $attributes = []): User
    {
        return User::withoutEvents(fn () => User::create(array_replace([
            'email' => bin2hex(random_bytes(6)).'@example.test',
            'password' => password_hash('FixturePassword77', PASSWORD_DEFAULT),
            'uuid' => bin2hex(random_bytes(16)), 'token' => bin2hex(random_bytes(16)),
            'u' => 0, 'd' => 0, 'balance' => 0, 'commission_balance' => 0,
            'transfer_enable' => 1000, 'reset_count' => 0, 'plan_id' => null,
            'expired_at' => null, 'next_reset_at' => null, 'last_reset_at' => null,
        ], $attributes)));
    }

    private function plan(): Plan
    {
        return Plan::create(['name' => 'Identity trial', 'group_id' => 1, 'transfer_enable' => 1,
            'prices' => ['monthly' => 1], 'reset_traffic_method' => 1, 'show' => true,
            'sell' => true, 'renew' => true, 'capacity_limit' => null]);
    }

    private function refused(callable $call, string $code): void
    {
        try {
            $call();
            $this->fail('Expected authority refusal '.$code);
        } catch (AuthorityFenceException $error) {
            $this->assertSame($code, $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }
}
