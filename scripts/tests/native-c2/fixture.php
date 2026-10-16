<?php

use App\Domains\Installation\InstallationAuthority;
use App\Domains\UsageV2\UsageEvidence;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use App\Domains\Subscription\Services\NativeCredentialProviderService;
use App\Domains\Subscription\Services\PlanPublicationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function nativeC2Request(string $path, array $body, ?string $bearer = null, ?string $key = null,
    string $method = 'POST', array $statuses = [200]): array
{
    $root = getenv('NATIVE_PROVIDER_NODE_DIR');
    $handle = curl_init(getenv('NATIVE_PROVIDER_NODE_CORE').'/txapi/'.$path);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($bearer !== null) { $headers[] = 'Authorization: '.$bearer; }
    if ($key !== null) { $headers[] = 'Idempotency-Key: '.$key; }
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CAINFO => $root.'/ca.pem', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_3, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 20,
    ]);
    if ($method !== 'GET') { curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR)); }
    $raw = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if (!is_string($raw) || strlen($raw) > 1048576 || !in_array($status, $statuses, true)) {
        $code = 'NATIVE_C2_HTTP_'.$status;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $error = $decoded['error']['code'] ?? null;
        if (is_string($error) && preg_match('/\A[A-Z][A-Z0-9_]{0,79}\z/D', $error)) { $code .= '_'.$error; }
        throw new RuntimeException($code);
    }
    return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
}

function nativeC2Work(): void
{
    if (Artisan::call('core:native-work', ['--limit' => 100]) !== 0) {
        throw new RuntimeException('NATIVE_C2_WORKER_FAILED');
    }
}

function nativeC2Purchase(string $bearer, int $offerId, string $key): void
{
    $order = nativeC2Request('orders', ['offer_id' => $offerId], $bearer, $key, statuses: [201]);
    $trade = $order['data']['trade_no'] ?? null;
    if (!is_string($trade)) { throw new RuntimeException('NATIVE_C2_ORDER_MISSING'); }
    nativeC2Request('orders/'.$trade.'/checkout', [], $bearer);
    // Execute the actual queued production job, not a substituted fulfillment.
    for ($index = 0; $index < 4; $index++) {
        Artisan::call('queue:work', ['connection' => 'redis', '--once' => true, '--stop-when-empty' => true,
            '--queue' => 'order_handle', '--tries' => 1, '--timeout' => 20]);
        if ((int) Order::query()->where('trade_no', $trade)->value('status') === Order::STATUS_COMPLETED) {
            nativeC2Work();
            return;
        }
    }
    throw new RuntimeException('NATIVE_C2_REAL_QUEUE_FULFILLMENT_FAILED');
}

try {
    $app = require __DIR__.'/bootstrap.php';
    $mode = $argv[1] ?? '';
    if ($mode === 'trust') {
        $seed = base64_decode(strtr(getenv('NATIVE_NODE_AUTHORIZATION_PRIVATE_SEED'), '-_', '+/'), true);
        $key = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));
        echo json_encode([
            'protocol_version' => 1, 'core_instance_id' => '11111111-1111-4111-8111-111111111111',
            'key_id' => 'provider-node-key', 'key_epoch' => '1', 'algorithm' => 'Ed25519',
            'public_key' => rtrim(strtr(base64_encode($key), '+/', '-_'), '='),
        ], JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    if ($mode === 'seed') {
        Artisan::call('migrate', ['--force' => true]);
        User::withoutEvents(fn () => User::create([
            'email' => 'native-admin@example.test', 'password' => password_hash('native-admin-password', PASSWORD_DEFAULT),
            'uuid' => '', 'token' => '', 'banned' => false, 'is_admin' => true,
            'transfer_enable' => 0, 'u' => 0, 'd' => 0, 'balance' => 0, 'commission_balance' => 0,
        ]));
        app(InstallationAuthority::class)->initialize(
            '11111111-1111-4111-8111-111111111111', 'native', 'native-c2-bootstrap');
        config(['core_installation.mode' => 'native', 'core_installation.worker_epoch' => '1']);
        $group = ServerGroup::unguarded(fn () => ServerGroup::create(['name' => 'Native runtime']));
        $plan = Plan::withoutEvents(fn () => Plan::create([
            'name' => 'Native C2 API plan', 'group_id' => $group->id,
            'transfer_enable' => 2, 'speed_limit' => 30, 'device_limit' => 4, 'capacity_limit' => null,
            'prices' => ['monthly' => '0.00', 'reset_traffic' => '0.00'], 'reset_traffic_method' => 2,
            'show' => true, 'sell' => true, 'renew' => true,
        ]));
        $publication = app(PlanPublicationService::class)->publish($plan->id, 'native-c2-publish', 'USD', 'UTC');
        $offer = collect($publication['offers'])->firstWhere('offer_code', 'monthly');
        $origin = (int) getenv('NATIVE_PROVIDER_NODE_ORIGIN_PORT');
        $nodes = [];
        foreach (['NATIVE_PROVIDER_NODE_PORT', 'NATIVE_PROVIDER_NODE_SECONDARY_PORT'] as $portName) {
            $port = (int) getenv($portName);
            if ($port < 1024 || $origin < 1024) { throw new RuntimeException('NATIVE_C2_PORT_INVALID'); }
            $nodes[] = Server::withoutEvents(fn () => Server::create([
                'name' => 'Native C2 VLESS', 'type' => 'vless', 'host' => '127.0.0.1',
                'port' => $port, 'server_port' => $port, 'rate' => 1, 'enabled' => true, 'show' => true,
                'group_ids' => [(string) $group->id], 'protocol_settings' => ['tls' => 0, 'network' => 'tcp'],
                'custom_routes' => [[
                    'network' => 'tcp', 'ip_cidr' => ['127.0.0.1/32'], 'port' => [$origin], 'outbound' => 'direct',
                ]],
            ]));
        }
        if ((int) $nodes[0]->id !== 1 || (int) $nodes[1]->id !== 2) { throw new RuntimeException('NATIVE_C2_FRESH_DATABASE_REQUIRED'); }
        admin_setting(['server_token' => 'synthetic-native-c2-node-bearer', 'secure_path' => 'native-c2-admin',
            'server_pull_interval' => 60, 'server_push_interval' => 5, 'stop_register' => 0,
            'email_verify' => 0, 'captcha_enable' => 0]);
        app(NativeCredentialProviderService::class)->register('primary-provider', 'native-c2-provider');
        $authority = app(InstallationAuthority::class);
        $preflight = $authority->readiness();
        $activated = $authority->activate('native-c2-activate', '1', $preflight['configuration_digest']);
        if ($activated['phase'] !== 'open' || $activated['authority_epoch'] !== '2') {
            throw new RuntimeException('NATIVE_C2_ACTIVATION_FAILED');
        }
        file_put_contents($directory.'/fixture.json', json_encode([
            'node_id' => 1, 'secondary_node_id' => 2, 'port' => (int) getenv('NATIVE_PROVIDER_NODE_PORT'),
            'secondary_port' => (int) getenv('NATIVE_PROVIDER_NODE_SECONDARY_PORT'), 'origin_port' => $origin,
            'offer_id' => $offer['offer_id'], 'token' => 'synthetic-native-c2-node-bearer',
        ], JSON_THROW_ON_ERROR));
        echo "Real native activation committed at epoch 2; no admission substitution.\n";
        exit(0);
    }
    $fixture = json_decode(file_get_contents($directory.'/fixture.json'), true, 32, JSON_THROW_ON_ERROR);
    if ($mode === 'account') {
        $registration = nativeC2Request('auth/register', [
            'email' => 'native-c2-user@example.test', 'password' => 'native-user-password',
        ], statuses: [201]);
        $bearer = $registration['data']['auth_data'];
        file_put_contents($directory.'/user-bearer', $bearer);
        chmod($directory.'/user-bearer', 0600);
        $admin = nativeC2Request('auth/admin/login', [
            'email' => 'native-admin@example.test', 'password' => 'native-admin-password',
        ]);
        file_put_contents($directory.'/admin-bearer', $admin['data']['auth_data']);
        chmod($directory.'/admin-bearer', 0600);
        nativeC2Request('plans', [], method: 'GET');
        nativeC2Purchase($bearer, (int) $fixture['offer_id'], 'native-c2-first-order');
        $user = User::query()->where('email', 'native-c2-user@example.test')->firstOrFail();
        $subscriptionId = DB::table('tx_subscription_slot')->where('user_id', $user->id)->value('subscription_id');
        $subject = DB::table('tx_access_subject')->where('subscription_id', $subscriptionId)
            ->where('credential_profile_id', 'vless-uuid/v1')->where('status', 'active')->first();
        if (!$subject || $user->uuid !== '' || $user->token !== '') { throw new RuntimeException('NATIVE_C2_ACCOUNT_ACCESS_FAILED'); }
        $fixture += ['user_id' => (int) $user->id, 'subscription_id' => (string) $subscriptionId,
            'subject_id' => (string) $subject->id,
            'initial_period_id' => (string) DB::table('tx_usage_period')->where('subscription_id', $subscriptionId)->value('id')];
        file_put_contents($directory.'/fixture.json', json_encode($fixture, JSON_THROW_ON_ERROR));
        echo "Real HTTP registration, free checkout, queued fulfillment and Provider issuance passed.\n";
        exit(0);
    }
    if ($mode === 'work') {
        nativeC2Work();
        echo "Native durable worker scanned.\n";
        exit(0);
    }
    if ($mode === 'renew') {
        nativeC2Purchase(file_get_contents($directory.'/user-bearer'), (int) $fixture['offer_id'], 'native-c2-renew-order');
        echo "Native HTTP renewal and durable rebind completed.\n";
        exit(0);
    }
    if ($mode === 'rotate') {
        $subject = DB::table('tx_access_subject')->where('id', $fixture['subject_id'])->first();
        nativeC2Request('me/access-subjects/'.$fixture['subject_id'].'/rotate',
            ['expected_version' => (string) $subject->row_version], file_get_contents($directory.'/user-bearer'), 'native-c2-manual-rotate');
        nativeC2Work();
        $cutoffs = DB::table('tx_auth_snapshot as snapshot')
            ->join('tx_node_usage_source as source', 'source.id', '=', 'snapshot.source_id')
            ->where('snapshot.subscription_id', $fixture['subscription_id'])->where('source.node_id', 1)
            ->whereNotNull('snapshot.revoked_at')->orderBy('snapshot.id')->get([
                'snapshot.id', 'snapshot.revoked_at', 'snapshot.usage_period_id', 'source.source_epoch',
            ])->map(fn ($grant) => [
                'auth_snapshot_id' => (string) $grant->id, 'cutoff_at' => (int) $grant->revoked_at,
                'usage_period_id' => (string) $grant->usage_period_id, 'source_epoch' => (string) $grant->source_epoch,
            ])->all();
        echo json_encode(['cutoffs' => $cutoffs], JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    if (in_array($mode, ['reset', 'revoke'], true)) {
        $subscription = DB::table('tx_subscription')->where('id', $fixture['subscription_id'])->first();
        nativeC2Request('admin/native-c2-admin/subscriptions/'.$fixture['subscription_id'].'/operations/'.$mode,
            ['expected_version' => (string) $subscription->row_version], file_get_contents($directory.'/admin-bearer'),
            'native-c2-'.$mode);
        nativeC2Work();
        echo "Native HTTP lifecycle operation completed.\n";
        exit(0);
    }
    if ($mode === 'ban') {
        nativeC2Request('admin/native-c2-admin/native/accounts/'.$fixture['user_id'].'/status',
            ['banned' => true, 'expected_banned' => false], file_get_contents($directory.'/admin-bearer'), method: 'PATCH');
        nativeC2Work();
        echo "Native account ban completed.\n";
        exit(0);
    }
    if ($mode === 'state' || $mode === 'verify') {
        $periods = DB::table('tx_usage_period')->where('subscription_id', $fixture['subscription_id'])->orderBy('id')->get();
        $subject = DB::table('tx_access_subject')->where('id', $fixture['subject_id'])->first();
        $state = [
            'period_count' => $periods->count(), 'billable_bytes' => (string) $periods->sum('billable_bytes'),
            'periods' => $periods->map(fn ($period) => [
                'id' => (string) $period->id, 'status' => $period->status, 'billable_bytes' => (string) $period->billable_bytes,
            ])->all(),
            'generation' => (string) $subject->credential_generation,
            'settled_batches' => DB::table('tx_usage_batch')->where('status', 'settled')->count(),
            'quarantined_batches' => DB::table('tx_usage_batch')->where('status', 'quarantined')->count(),
            'reservation_count' => DB::table('tx_native_usage_reservation')->count(),
        ];
        if ($mode === 'state') {
            echo json_encode($state, JSON_THROW_ON_ERROR)."\n";
            exit(0);
        }
        $user = User::query()->findOrFail($fixture['user_id']);
        if ($user->uuid !== '' || $user->token !== '' || (int) $user->u !== 0 || (int) $user->d !== 0
            || (int) $user->transfer_enable !== 0 || (int) $state['billable_bytes'] <= 0
            || $state['settled_batches'] < 2 || $state['period_count'] < 2 || $state['quarantined_batches'] < 1
            || DB::table('tx_usage_batch')->whereNotIn('status', ['settled', 'quarantined'])->exists()
            || DB::table('tx_native_runtime_activation')->count() !== 1
            || app(InstallationAuthority::class)->inspect()['phase'] !== 'open') {
            throw new RuntimeException('NATIVE_C2_FINAL_EVIDENCE_INVALID');
        }
        $totals = [];
        foreach ($periods as $period) { $totals[(string) $period->id] = ['up' => '0', 'down' => '0']; }
        $fields = ['subscription_id', 'usage_period_id', 'access_subject_id', 'auth_snapshot_id', 'tariff_revision_id',
            'terms_id', 'raw_up_bytes', 'raw_down_bytes', 'billed_up_bytes', 'billed_down_bytes',
            'numerator', 'denominator', 'rounding_revision'];
        foreach (DB::table('tx_usage_batch')->where('status', 'settled')->orderBy('id')->get() as $batch) {
            $frozen = json_decode($batch->frozen_entries, true, 32, JSON_THROW_ON_ERROR);
            $payload = json_decode($batch->canonical_payload, true, 32, JSON_THROW_ON_ERROR);
            $ledger = DB::table('tx_usage_entry')->where('batch_id', $batch->id)->orderBy('entry_no')->get();
            if (count($frozen) !== $ledger->count() || count($frozen) !== count($payload['entries'])) {
                throw new RuntimeException('NATIVE_C2_LEDGER_COUNT_INVALID');
            }
            foreach ($frozen as $index => $entry) {
                $row = $ledger[$index];
                if ((int) $row->entry_no !== $index) { throw new RuntimeException('NATIVE_C2_LEDGER_ORDER_INVALID'); }
                foreach ($fields as $field) {
                    if ((string) $row->$field !== (string) $entry[$field]) {
                        throw new RuntimeException('NATIVE_C2_LEDGER_BINDING_INVALID');
                    }
                }
                foreach ($payload['entries'][$index] as $field => $value) {
                    if ((string) $entry[$field] !== (string) $value) {
                        throw new RuntimeException('NATIVE_C2_LEDGER_SOURCE_INVALID');
                    }
                }
                $pid = (string) $entry['usage_period_id'];
                if (!isset($totals[$pid]) || (string) $entry['subscription_id'] !== $fixture['subscription_id']
                    || (string) $entry['billed_up_bytes'] !== (string) $entry['raw_up_bytes']
                    || (string) $entry['billed_down_bytes'] !== (string) $entry['raw_down_bytes']) {
                    throw new RuntimeException('NATIVE_C2_PERIOD_DESTINATION_INVALID');
                }
                $totals[$pid]['up'] = bcadd($totals[$pid]['up'], (string) $entry['billed_up_bytes'], 0);
                $totals[$pid]['down'] = bcadd($totals[$pid]['down'], (string) $entry['billed_down_bytes'], 0);
            }
        }
        $reservationCount = 0;
        foreach (DB::table('tx_usage_batch')->where('status', 'quarantined')->orderBy('id')->get() as $batch) {
            $frozen = json_decode($batch->frozen_entries, true, 32, JSON_THROW_ON_ERROR);
            $payload = json_decode($batch->canonical_payload, true, 32, JSON_THROW_ON_ERROR);
            if ($batch->reason_code !== 'USAGE_NATIVE_AUTHORIZATION_CUTOFF' || $batch->settled_at !== null
                || DB::table('tx_usage_entry')->where('batch_id', $batch->id)->exists()
                || count($frozen) !== count($payload['entries']) || $frozen === []
                || UsageEvidence::hash($frozen) !== $batch->frozen_hash
                || 'sha256:'.hash('sha256', $batch->canonical_payload) !== $batch->payload_digest) {
                throw new RuntimeException('NATIVE_C2_QUARANTINE_PROOF_INVALID');
            }
            $reserved = [];
            $cutoff = false;
            foreach ($frozen as $index => $entry) {
                foreach ($payload['entries'][$index] as $field => $value) {
                    if ((string) $entry[$field] !== (string) $value) {
                        throw new RuntimeException('NATIVE_C2_QUARANTINE_SOURCE_INVALID');
                    }
                }
                $periodId = (string) $entry['usage_period_id'];
                $grant = DB::table('tx_auth_snapshot')->where('id', $entry['auth_snapshot_id'])->first();
                if (!isset($totals[$periodId]) || (string) $entry['subscription_id'] !== $fixture['subscription_id']
                    || (string) $entry['billed_up_bytes'] !== (string) $entry['raw_up_bytes']
                    || (string) $entry['billed_down_bytes'] !== (string) $entry['raw_down_bytes']
                    || !$grant || (string) $grant->usage_period_id !== $periodId
                    || (string) $grant->subscription_id !== $fixture['subscription_id']
                    || (string) $grant->terms_id !== (string) $entry['terms_id']
                    || (int) $batch->started_at < (int) $grant->issued_at
                    || (int) $batch->ended_at > (int) $grant->lease_expires_at) {
                    throw new RuntimeException('NATIVE_C2_QUARANTINE_ORIGINAL_GRANT_INVALID');
                }
                $cutoff = $cutoff || ($grant->revoked_at !== null && (int) $batch->ended_at > (int) $grant->revoked_at);
                $reserved[$periodId] = bcadd($reserved[$periodId] ?? '0',
                    bcadd((string) $entry['billed_up_bytes'], (string) $entry['billed_down_bytes'], 0), 0);
            }
            $rows = DB::table('tx_native_usage_reservation')->where('batch_id', $batch->id)->get();
            if (!$cutoff || $rows->count() !== count($reserved)) {
                throw new RuntimeException('NATIVE_C2_QUARANTINE_RESERVATION_COUNT_INVALID');
            }
            foreach ($rows as $row) {
                if (!isset($reserved[(string) $row->usage_period_id])
                    || (string) $row->reserved_bytes !== $reserved[(string) $row->usage_period_id]) {
                    throw new RuntimeException('NATIVE_C2_QUARANTINE_RESERVATION_INVALID');
                }
                $reservationCount++;
            }
        }
        if ($state['reservation_count'] !== $reservationCount) {
            throw new RuntimeException('NATIVE_C2_SETTLED_RESERVATION_NOT_RELEASED');
        }
        foreach ($periods as $period) {
            $total = $totals[(string) $period->id];
            if ((string) $period->upload_bytes !== $total['up'] || (string) $period->download_bytes !== $total['down']
                || (string) $period->billable_bytes !== bcadd($total['up'], $total['down'], 0)
                || bccomp((string) $period->billable_bytes, '0', 0) !== 1) {
                throw new RuntimeException('NATIVE_C2_PERIOD_TOTAL_INVALID');
            }
        }
        $replays = json_decode(file_get_contents($directory.'/offline-replay.json'), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($replays) || $replays === []) { throw new RuntimeException('NATIVE_C2_REPLAY_EVIDENCE_MISSING'); }
        foreach ($replays as $replay) {
            $batch = DB::table('tx_usage_batch')->where('node_id', $replay['node_id'])
                ->where('source_epoch', $replay['source_epoch'])->where('batch_id', $replay['batch_id'])->first();
            $entries = $batch ? DB::table('tx_usage_entry')->where('batch_id', $batch->id)->get() : collect();
            if (!$batch || $batch->status !== 'settled' || $batch->payload_digest !== $replay['payload_digest']
                || $entries->isEmpty()
                || $entries->contains(fn ($entry) => (string) $entry->usage_period_id !== $fixture['initial_period_id'])
                || $replay['period_ids'] !== [$fixture['initial_period_id']]) {
                throw new RuntimeException('NATIVE_C2_REPLAY_DESTINATION_INVALID');
            }
        }
        $captures = json_decode(file_get_contents($directory.'/rotation-quarantine.json'), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($captures) || $captures === []) {
            throw new RuntimeException('NATIVE_C2_ROTATION_CUTOFF_CAPTURE_MISSING');
        }
        foreach ($captures as $capture) {
            $payload = $capture['submission']['payload'];
            $receipt = $capture['receipt'] ?? [];
            $batch = DB::table('tx_usage_batch')->where('node_id', $payload['node_id'])
                ->where('source_epoch', $payload['source_epoch'])->where('batch_id', $payload['batch_id'])->first();
            if (!$batch || $batch->status !== 'quarantined' || isset($capture['local_quarantine'])
                || $batch->payload_digest !== $capture['submission']['payload_digest']
                || UsageEvidence::hash(json_decode($batch->canonical_payload, true, 32, JSON_THROW_ON_ERROR))
                    !== UsageEvidence::hash($payload)
                || ($receipt['receipt_status'] ?? null) !== 'persisted'
                || ($receipt['settlement_status'] ?? null) !== 'quarantined'
                || ($receipt['reason_code'] ?? null) !== 'USAGE_NATIVE_AUTHORIZATION_CUTOFF'
                || ($receipt['receipt_id'] ?? null) !== $batch->receipt_id
                || ($receipt['payload_digest'] ?? null) !== $batch->payload_digest
                || ($receipt['accepted_at'] ?? null) !== (int) $batch->accepted_at
                || (string) $capture['session']['node_id'] !== (string) $batch->node_id
                || (string) $capture['session']['source_epoch'] !== (string) $batch->source_epoch
                || count($capture['session']['grants']) !== 1
                || $capture['session']['grants'][0]['auth_snapshot_id'] !== $payload['entries'][0]['auth_snapshot_id']
                || $capture['session']['grants'][0]['usage_period_id'] !== $payload['entries'][0]['usage_period_id']) {
                throw new RuntimeException('NATIVE_C2_REAL_CUTOFF_RECEIPT_MISMATCH');
            }
        }
        echo "Settled batches match original Period ledgers; cutoff captures retain real Core receipts and exact original-period reservations.\n";
        exit(0);
    }
    throw new RuntimeException('NATIVE_C2_FIXTURE_MODE_INVALID');
} catch (Throwable $error) {
    $code = preg_match('/\A[A-Z][A-Z0-9_]{1,160}\z/D', $error->getMessage())
        ? $error->getMessage() : 'UNCLASSIFIED';
    fwrite(STDERR, 'NATIVE_C2_FIXTURE_FAILED '.get_class($error).' '
        .basename($error->getFile()).':'.$error->getLine().' '.$code."\n");
    exit(1);
}
