<?php

namespace Tests\Support;

use App\Domains\AuthorityMigration\AuthorityFenceException;
use App\Domains\AuthorityMigration\AuthorityIdentityInventory;
use App\Domains\AuthorityMigration\AuthorityNativeInventory;
use App\Domains\AuthorityMigration\AuthorityTicketInventory;
use App\Domains\AuthorityMigration\AuthorityWriterFence;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Dedicated synthetic database; validate endpoint, database and account before every destructive reset. */
trait AuthorityTicketFixture
{
    private bool $ticketDatabaseConfigured = false;

    private function ticketMysql(): bool
    {
        return getenv('AUTHORITY_TICKET_MYSQL') === '1';
    }

    private function ticketConnection(): string
    {
        return $this->ticketMysql() ? 'ticket_fence_mysql' : 'ticket_fence_sqlite';
    }

    private function configureTicketDatabase(): void
    {
        $name = $this->ticketConnection();
        $connection = $this->ticketMysql() ? config('database.connections.mysql')
            : array_replace(config('database.connections.sqlite'), ['database' => ':memory:']);
        if ($this->ticketMysql() && (($connection['database'] ?? '') !== 'txboard_ticket_fence_test'
            || ($connection['username'] ?? '') !== 'txboard_ticket_fence'
            || !in_array($connection['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)
            || !empty($connection['url']) || !empty($connection['unix_socket']))) {
            throw new \RuntimeException('Ticket fixture requires its dedicated loopback database and account.');
        }
        config(['database.default' => $name, 'database.connections.'.$name => $connection]);
        DB::purge($name);
        $this->ticketDatabaseConfigured = true;
        if ($this->ticketMysql()) $this->clearTicketDatabase();
    }

    private function clearTicketDatabase(): void
    {
        $connection = DB::connection($this->ticketConnection());
        if ($connection->getDatabaseName() !== 'txboard_ticket_fence_test'
            || $connection->getConfig('username') !== 'txboard_ticket_fence'
            || !in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)
            || $connection->getConfig('url') || $connection->getConfig('unix_socket')) {
            throw new \RuntimeException('Refusing to clear any other database or endpoint.');
        }
        $schema = Schema::connection($this->ticketConnection());
        $schema->disableForeignKeyConstraints();
        try {
            foreach ($schema->getTableListing() as $table) $schema->drop($table);
        } finally {
            $schema->enableForeignKeyConstraints();
        }
    }

    private function cleanupTicketDatabase(): void
    {
        if (!$this->ticketDatabaseConfigured) return;
        if ($this->ticketMysql()) $this->clearTicketDatabase();
        DB::purge($this->ticketConnection());
    }

    private function fence(): AuthorityWriterFence
    {
        return app(AuthorityWriterFence::class);
    }

    private function native(): void
    {
        $this->fence()->initialize();
        $this->fence()->expand('ticket-parent-inventory', '1');
        $this->fence()->extendFinance('ticket-parent-finance', '2');
        $this->fence()->extendNative('ticket-parent-native', '3');
        $this->declare(4, AuthorityNativeInventory::SCOPE, AuthorityNativeInventory::VERSION);
    }

    private function identity(): void
    {
        $this->native();
        $this->fence()->extendIdentity('ticket-parent-identity', '4');
        $this->declare(5, AuthorityIdentityInventory::SCOPE, AuthorityIdentityInventory::VERSION);
    }

    private function tickets(): void
    {
        $this->identity();
        $this->fence()->extendTicket('ticket-fixture', '5');
        $this->declare(6, AuthorityTicketInventory::SCOPE, AuthorityTicketInventory::VERSION);
    }

    private function declare(int $epoch, string $scope, string $version): void
    {
        config(['authority_writer_fence' => ['enabled' => true, 'worker_epoch' => (string) $epoch,
            'worker_role' => 'legacy', 'worker_scope' => $scope, 'worker_version' => $version]]);
    }

    private function stop(): void
    {
        $this->fence()->prepare('ticket-stop', '6');
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

    private function ticket(User $user, array $attributes = []): Ticket
    {
        $ticket = Ticket::create(array_replace(['user_id' => $user->id, 'subject' => 'Synthetic support fixture',
            'level' => 1, 'status' => Ticket::STATUS_OPENING, 'reply_status' => Ticket::REPLY_STATUS_WAITING,
            'last_reply_user_id' => $user->id], $attributes));
        TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $user->id, 'message' => 'Synthetic initial message']);
        return $ticket;
    }

    private function rows(array $tables): array
    {
        $rows = [];
        foreach ($tables as $table) $rows[$table] = DB::table($table)->get()->toJson();
        return $rows;
    }

    private function authorityRows(): array
    {
        return $this->rows(['tx_authority_writer_fence', 'tx_authority_prepare_receipt',
            'tx_authority_inventory_upgrade', 'tx_authority_finance_upgrade', 'tx_authority_native_upgrade',
            'tx_authority_identity_upgrade', 'tx_authority_ticket_upgrade']);
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