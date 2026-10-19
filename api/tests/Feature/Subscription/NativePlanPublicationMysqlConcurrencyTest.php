<?php

namespace Tests\Feature\Subscription;

use App\Domains\CredentialProvider\ProviderCodec;
use App\Domains\Subscription\Services\NativePlanAdministration;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeRuntimeTestCase;

/** Independent MySQL sessions publish against the same observed immutable revision. */
final class NativePlanPublicationMysqlConcurrencyTest extends NativeRuntimeTestCase
{
    public function test_two_administrators_cannot_publish_different_revisions_from_the_same_observation(): void
    {
        if (getenv('CORE_NATIVE_TEST_MYSQL') !== '1' || DB::getDriverName() !== 'mysql'
            || !function_exists('pcntl_fork') || !function_exists('posix_kill') || !function_exists('stream_socket_pair')) {
            $this->markTestSkipped('Requires dedicated native MySQL and independent POSIX processes.');
        }
        $this->activate();
        $actors = [];
        foreach (['one', 'two'] as $name) {
            [, $actor] = app(NativeIdentityService::class)->create('publisher-'.$name.'@example.test', 'publisher-password');
            DB::table('tx_user')->where('id', $actor->id)->update(['is_admin' => true]);
            $actors[] = $actor->id;
        }
        $input = ['name' => 'Initial', 'quota_gib' => 2, 'reset_method' => 2, 'currency' => 'CNY', 'prices_minor' => ['monthly' => '100']];
        $first = app(NativePlanAdministration::class)->publish($actors[0], $input, 'race-initial');
        $observed = $input + ['plan_id' => $first['plan_id'], 'expected_revision_id' => $first['publication']['revision_id']];
        $original = DB::table('tx_plan_revision')->where('id', $first['publication']['revision_id'])->first();
        foreach (array_keys(DB::getConnections()) as $connection) DB::purge($connection);
        $children = [];
        try {
            foreach ($actors as $index => $actorId) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                $this->assertIsArray($pair);
                [$parent, $child] = $pair;
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    fclose($parent);
                    foreach ($children as $older) fclose($older['socket']);
                    stream_set_timeout($child, 30);
                    try {
                        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                        DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
                        $this->send($child, 'READY');
                        if ($this->receive($child) !== 'GO') throw new \RuntimeException('PUBLICATION_RACE_SIGNAL_INVALID');
                        $result = app(NativePlanAdministration::class)->publish($actorId,
                            array_replace($observed, ['name' => 'Contender '.$index]), 'race-contender-'.$index);
                        $response = ['result' => $result];
                    } catch (\Throwable $failure) {
                        $response = ['error' => $failure->getMessage()];
                    }
                    $this->send($child, json_encode($response, JSON_THROW_ON_ERROR));
                    DB::disconnect();
                    fclose($child);
                    exit(0);
                }
                fclose($child);
                stream_set_timeout($parent, 30);
                $children[] = ['pid' => $pid, 'socket' => $parent, 'done' => false];
            }
            foreach ($children as $child) $this->assertSame('READY', $this->receive($child['socket']));
            foreach ($children as $child) $this->send($child['socket'], 'GO');
            $results = [];
            foreach ($children as $index => &$child) {
                $results[$index] = json_decode($this->receive($child['socket']), true, 32, JSON_THROW_ON_ERROR);
                pcntl_waitpid($child['pid'], $status);
                $child['done'] = true;
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            unset($child);
            $winners = array_filter($results, static fn (array $result): bool => isset($result['result']));
            $this->assertCount(1, $winners, json_encode($results, JSON_THROW_ON_ERROR));
            $losers = array_filter($results, static fn (array $result): bool => isset($result['error']));
            $this->assertSame(['NATIVE_PLAN_REVISION_CONFLICT'], array_values(array_column($losers, 'error')));
            $winner = array_key_first($winners);
            $this->assertSame('Contender '.$winner, DB::table('tx_plan')->where('id', $first['plan_id'])->value('name'));
            $this->assertSame(2, DB::table('tx_plan_revision')->where('plan_id', $first['plan_id'])->count());
            $this->assertSame(2, DB::table('tx_native_plan_request')->where('plan_id', $first['plan_id'])->count());
            $this->assertSame(1, DB::table('tx_plan_offer_availability as availability')->join('tx_plan_offer as offer', 'offer.id', '=', 'availability.offer_id')
                ->where('offer.plan_id', $first['plan_id'])->where('availability.status', 'published')->count());
            $this->assertEquals($original, DB::table('tx_plan_revision')->where('id', $original->id)->first());
            $replayed = app(NativePlanAdministration::class)->publish($actors[$winner],
                array_replace($observed, ['name' => 'Contender '.$winner]), 'race-contender-'.$winner);
            $this->assertSame(ProviderCodec::canonical($winners[$winner]['result']), ProviderCodec::canonical($replayed));
        } finally {
            foreach ($children as $child) {
                if (!$child['done']) { posix_kill($child['pid'], SIGKILL); pcntl_waitpid($child['pid'], $status); }
                fclose($child['socket']);
            }
            DB::purge();
        }
    }

    private function send($socket, string $value): void
    {
        if (fwrite($socket, $value."\n") !== strlen($value) + 1) throw new \RuntimeException('PUBLICATION_RACE_WRITE_FAILED');
        fflush($socket);
    }

    private function receive($socket): string
    {
        $line = fgets($socket);
        if ($line === false) throw new \RuntimeException('PUBLICATION_RACE_READ_FAILED');
        return rtrim($line, "\r\n");
    }
}
