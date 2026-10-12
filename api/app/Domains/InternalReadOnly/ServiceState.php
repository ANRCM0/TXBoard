<?php

namespace App\Domains\InternalReadOnly;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ServiceState
{
    public function admit(array $identity): void
    {
        $now = time();
        $window = intdiv($now, 60) * 60;
        try {
            DB::transaction(function () use ($identity, $now, $window): void {
                // A committed unique row is shared by workers and survives process restarts.
                DB::table('tx_internal_readonly_nonces')->insert([
                    'kid' => $identity['kid'], 'jti' => $identity['jti'], 'expires_at' => $identity['expires_at'],
                ]);
                DB::table('tx_internal_readonly_windows')->upsert([
                    'service' => $identity['service'], 'window_start' => $window,
                    'requests' => 0, 'expires_at' => $window + 120,
                ], ['service', 'window_start'], ['expires_at']);
                $query = DB::table('tx_internal_readonly_windows')->where('service', $identity['service'])->where('window_start', $window);
                $state = $query->lockForUpdate()->first();
                if ($state === null || $state->requests >= config('internal_api_slice.requests_per_minute')) {
                    throw new SliceFailure('RATE_LIMITED', 429);
                }
                $query->increment('requests');
                // Bounded indexed cleanup; expiry is checked again on deletion. Never remove a live nonce.
                foreach (['tx_internal_readonly_nonces', 'tx_internal_readonly_audits'] as $table) {
                    $ids = DB::table($table)->where('expires_at', '<', $now)->orderBy('expires_at')->limit(100)->pluck('id');
                    if ($ids->isNotEmpty()) {
                        DB::table($table)->whereIn('id', $ids)->where('expires_at', '<', $now)->delete();
                    }
                }
                $oldWindows = DB::table('tx_internal_readonly_windows')->where('expires_at', '<', $now)
                    ->orderBy('expires_at')->limit(100)->get(['service', 'window_start']);
                foreach ($oldWindows as $old) {
                    DB::table('tx_internal_readonly_windows')->where('service', $old->service)
                        ->where('window_start', $old->window_start)->where('expires_at', '<', $now)->delete();
                }
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw new SliceFailure('SERVICE_REPLAY', 409);
        } catch (SliceFailure $failure) {
            throw $failure;
        } catch (\Throwable) {
            throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
        }
    }

    public function audit(array $identity, string $operation, int $actorId, string $requestId, int $status, ?string $errorCode): void
    {
        try {
            DB::table('tx_internal_readonly_audits')->insert([
                'service' => $identity['service'], 'operation' => $operation, 'actor_id' => $actorId,
                'request_id' => $requestId, 'status' => $status, 'error_code' => $errorCode,
                'expires_at' => time() + config('internal_api_slice.audit_retention_seconds'),
            ]);
        } catch (\Throwable) {
            throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
        }
    }
}
