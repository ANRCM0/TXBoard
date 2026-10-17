<?php

namespace Tests\Integration\Modules;

use App\Core\Host\Modules\ModuleDeliveryWorker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\Support\ModuleRuntimeTestCase;

/** Explicit CI entrypoint; excluded from the default suites because this starts a real HTTPS peer. */
final class NotificationReceiverTest extends ModuleRuntimeTestCase
{
    public function test_real_receiver_restart_after_lost_acknowledgement_keeps_one_receipt_and_original_event(): void
    {
        $certificate = getenv('CORE_MODULE_RECEIVER_CERT');
        $privateKey = getenv('CORE_MODULE_RECEIVER_KEY');
        $this->assertIsString($certificate);
        $this->assertIsString($privateKey);
        $this->assertFileExists($certificate);
        $this->assertFileExists($privateKey);
        $tokenFile = $this->file('original-receiver-secret');
        $store = $this->file('');
        $server = null;
        $port = 0;
        $start = function () use (&$server, &$port, $certificate, $privateKey, $tokenFile, $store): void {
            $server = new Process(['python3', '-u', base_path('module-fixtures/notification-receiver.py'),
                '--certificate', $certificate, '--private-key', $privateKey, '--token-file', $tokenFile,
                '--store', $store, '--port', (string) $port, '--lose-first-ack']);
            $server->start();
            $output = '';
            for ($attempt = 0; $attempt < 100; ++$attempt) {
                $output .= $server->getIncrementalOutput();
                if (str_contains($output, "\n")) {
                    $report = json_decode(trim($output), true, 16, JSON_THROW_ON_ERROR);
                    $port = $report['port'];
                    return;
                }
                $this->assertTrue($server->isRunning(), 'Proof receiver exited before listening');
                usleep(100000);
            }
            $this->fail('Proof receiver did not start');
        };
        try {
            $start();
            $endpoint = 'https://127.0.0.1:'.$port.'/events';
            Http::globalOptions(['verify' => $certificate]);
            config(['core_modules.outbound_hosts.delivery_receipt' => ['127.0.0.1']]);
            $this->enableModule('epay');
            $this->enableModule('delivery_receipt', $this->receiverConfiguration($endpoint));
            $this->activate();
            $this->settle();
            $eventId = DB::table('tx_module_delivery')->value('event_key');
            $this->assertSame(1, app(ModuleDeliveryWorker::class)->run(10)['unknown']);
            $this->assertSame(1, DB::table('tx_payment_receipt')->count());
            $this->assertSame(1, DB::table('tx_money_journal')->count());
            $this->assertSame('unknown', DB::table('tx_module_delivery')->value('status'));
            $server->stop(1);
            $start();
            config(['core_modules.secrets.receiver' => 'rotated-receiver-secret']);
            $this->deploy('delivery_receipt', 'configure', $this->receiverConfiguration('https://unreachable.example.test/events'));
            DB::table('tx_module_delivery')->update(['next_attempt_at' => 0]);
            $this->assertSame(1, app(ModuleDeliveryWorker::class)->run(10)['accepted']);
            $receipt = Http::withToken('original-receiver-secret')->get($endpoint.'/'.$eventId)->throw()->json();
            $this->assertSame('accepted', $receipt['status']);
            $this->assertSame(1, $receipt['accepted_count']);
            $this->assertSame($eventId, $receipt['event_id']);
            $this->assertSame(1, DB::table('tx_ext_delivery_receipt')->count());
            $this->assertSame(1, DB::table('tx_module_delivery')->count());
        } finally {
            $server?->stop(1);
            foreach (['-wal', '-shm'] as $suffix) if (is_file($store.$suffix)) unlink($store.$suffix);
        }
    }
}
