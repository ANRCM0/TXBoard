<?php

namespace Plugin\OfferReservation;

use App\Extensions\Contracts\DeploymentModule;
use App\Extensions\Contracts\ExtensionModule;
use App\Extensions\Contracts\ExtensionRequest;
use App\Extensions\Contracts\ExtensionResponse;
use App\Extensions\Contracts\ExtensionFailure;
use App\Extensions\Contracts\RemovalGuard;
use App\Extensions\SDK\Capabilities;
use App\Domains\Subscription\Contracts\PurchaseRejected;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class Plugin extends AbstractPlugin implements DeploymentModule, ExtensionModule, RemovalGuard
{
    public function packageVersion(): string { return '1.0.0'; }

    public function prepareSchema(string $deploymentId, int $fromVersion, int $toVersion): void
    {
        if ($toVersion !== 1 || !in_array($fromVersion, [0, 1], true)) throw new ExtensionFailure('MODULE_SCHEMA_VERSION_CONFLICT');
        if (!Schema::hasTable('tx_ext_offer_account')) Schema::create('tx_ext_offer_account', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary();
        });
        if (!Schema::hasTable('tx_ext_offer_reservation')) Schema::create('tx_ext_offer_reservation', function (Blueprint $table): void {
            $table->char('reservation_id', 64)->primary();
            $table->uuid('installation_id');
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('offer_id');
            $table->uuid('request_key');
            $table->char('request_hash', 64);
            $table->boolean('pay_with_wallet');
            $table->string('status', 24);
            $table->string('trade_no', 128)->nullable();
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
        });
        if (!Schema::hasColumns('tx_ext_offer_reservation', ['reservation_id', 'installation_id', 'user_id', 'offer_id', 'request_key',
            'request_hash', 'pay_with_wallet', 'status', 'trade_no', 'created_at', 'updated_at'])) throw new ExtensionFailure('MODULE_SCHEMA_VERSION_CONFLICT');
    }

    public function handle(ExtensionRequest $request): ExtensionResponse
    {
        $capabilities = new Capabilities($this->pluginCode);
        if ($request->operation === 'reservations.list') {
            $rows = DB::table('tx_ext_offer_reservation')->useWritePdo()->where('user_id', $request->userId)
                ->orderByDesc('created_at')->orderBy('reservation_id')->limit(50)->get()->map(fn (object $row) => $this->result($row))->all();
            return new ExtensionResponse(['reservations' => $rows]);
        }
        if ($request->operation === 'reservation.create') {
            $key = $request->idempotencyKey ?? throw new ExtensionFailure('MODULE_IDEMPOTENCY_KEY_REQUIRED', 400);
            $id = hash('sha256', $request->userId.':'.$key);
            $hash = hash('sha256', json_encode([$request->input['offer_id'], $request->input['pay_with_wallet']], JSON_THROW_ON_ERROR));
            $row = DB::transaction(function () use ($request, $capabilities, $key, $id, $hash): object {
                $installation = $capabilities->admitLocked();
                DB::table('tx_ext_offer_account')->insertOrIgnore(['user_id' => $request->userId]);
                DB::table('tx_ext_offer_account')->where('user_id', $request->userId)->lockForUpdate()->first();
                $row = DB::table('tx_ext_offer_reservation')->where('reservation_id', $id)->lockForUpdate()->first();
                if ($row !== null) {
                    if ($row->request_hash !== $hash || $row->installation_id !== $installation['installation_id']) throw new ExtensionFailure('MODULE_RESERVATION_CONFLICT');
                    return $row;
                }
                $open = DB::table('tx_ext_offer_reservation')->where('user_id', $request->userId)->whereNotIn('status', ['completed', 'refused'])
                    ->lockForUpdate()->get(['reservation_id'])->count();
                if ($open >= $this->getConfig('max_open', 1)) throw new ExtensionFailure('MODULE_RESERVATION_CAPACITY');
                $values = ['reservation_id' => $id, 'installation_id' => $installation['installation_id'], 'user_id' => $request->userId,
                    'offer_id' => $request->input['offer_id'], 'request_key' => $key, 'request_hash' => $hash,
                    'pay_with_wallet' => $request->input['pay_with_wallet'], 'status' => 'held', 'trade_no' => null,
                    'created_at' => time(), 'updated_at' => time()];
                DB::table('tx_ext_offer_reservation')->insert($values);
                return (object) $values;
            }, 3);
        } elseif ($request->operation === 'reservation.recover') {
            $row = DB::transaction(function () use ($request, $capabilities): object {
                $installation = $capabilities->admitLocked(true);
                $row = DB::table('tx_ext_offer_reservation')->where('reservation_id', $request->input['reservation_id'])
                    ->where('user_id', $request->userId)->sharedLock()->first();
                if ($row === null || $row->installation_id !== $installation['installation_id']) throw new ExtensionFailure('MODULE_RESERVATION_NOT_FOUND', 404);
                return $row;
            }, 3);
        } else throw new ExtensionFailure('MODULE_OPERATION_NOT_FOUND', 404);
        if (in_array($row->status, ['completed', 'refused'], true)) return new ExtensionResponse($this->result($row));
        try {
            // Owner transactions stay outside this module's SQL. Lost results replay the original permanent key.
            $purchase = $capabilities->purchases()->purchase($request->userId, (int) $row->offer_id, $row->request_key);
        } catch (PurchaseRejected) {
            if ($row->trade_no === null) $this->save($row, 'refused');
            return new ExtensionResponse($this->result($this->load($row)));
        } catch (\Throwable) {
            $this->save($row, 'unknown');
            return new ExtensionResponse($this->result($this->load($row)), 202);
        }
        $row->trade_no = $purchase->tradeNo;
        $this->save($row, 'purchased');
        if ((bool) $row->pay_with_wallet) {
            try {
                $payment = $capabilities->checkout()->start($request->userId, $row->trade_no, requestKey: $row->request_key, wallet: true);
                if (($payment['type'] ?? null) !== -1 || ($payment['data'] ?? null) !== true) throw new ExtensionFailure('MODULE_CHECKOUT_RESULT_UNKNOWN');
            } catch (\Throwable) {
                $this->save($row, 'unknown');
                return new ExtensionResponse($this->result($this->load($row)), 202);
            }
        }
        $this->save($row, 'completed');
        return new ExtensionResponse($this->result($this->load($row)));
    }

    public function assertDrained(): void
    {
        if (Schema::hasTable('tx_ext_offer_reservation') && DB::table('tx_ext_offer_reservation')->whereNotIn('status', ['completed', 'refused'])->exists()) {
            throw new ExtensionFailure('MODULE_RESERVATION_DRAIN_REQUIRED');
        }
    }

    private function save(object $row, string $status): void
    {
        DB::transaction(function () use ($row, $status): void {
            $installation = (new Capabilities($this->pluginCode))->admitLocked(true);
            if ($installation['installation_id'] !== $row->installation_id) throw new ExtensionFailure('MODULE_INSTALLATION_CONFLICT');
            $current = DB::table('tx_ext_offer_reservation')->where('reservation_id', $row->reservation_id)->lockForUpdate()->first();
            if ($current === null || in_array($current->status, ['completed', 'refused'], true)) return;
            DB::table('tx_ext_offer_reservation')->where('reservation_id', $row->reservation_id)->update([
                'status' => $status, 'trade_no' => $row->trade_no ?? $current->trade_no, 'updated_at' => time()]);
        }, 3);
    }

    private function load(object $row): object
    {
        return DB::table('tx_ext_offer_reservation')->useWritePdo()->where('reservation_id', $row->reservation_id)->first();
    }

    private function result(object $row): array
    {
        return ['reservation_id' => $row->reservation_id, 'offer_id' => (string) $row->offer_id, 'status' => $row->status,
            'trade_no' => $row->trade_no, 'pay_with_wallet' => (bool) $row->pay_with_wallet];
    }
}
