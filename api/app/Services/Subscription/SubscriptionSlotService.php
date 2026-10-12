<?php

namespace App\Services\Subscription;

use App\Models\Subscription;
use App\Models\User;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Serializes shadow reservation changes. Legacy User writers remain authoritative. */
class SubscriptionSlotService
{
    public function reserve(int $userId, int $subscriptionId, ?int $now = null): Subscription
    {
        $now ??= time();
        return $this->withLockedAggregate($userId, $subscriptionId, $now,
            function (Subscription $subscription, object $slot) use ($now) {
                $this->reserveLocked($subscription, $slot, $now);
                return $subscription;
            });
    }

    public function transition(int $userId, int $subscriptionId, string $status, ?int $now = null): Subscription
    {
        if (!in_array($status, ['pending', 'active', 'suspended', 'expired', 'revoked'], true)) {
            throw new InvalidArgumentException('Invalid subscription status.');
        }
        $now ??= time();
        return $this->withLockedAggregate($userId, $subscriptionId, $now,
            function (Subscription $subscription, object $slot) use ($status, $now) {
                if ($subscription->status === 'revoked' && $status !== 'revoked') {
                    throw new DomainException('subscription_revoked');
                }
                $subscription->status = $status;
                if (in_array($status, ['active', 'suspended'], true)) {
                    $this->reserveLocked($subscription, $slot, $now);
                    $subscription->ended_at = null;
                } else {
                    if ($slot->subscription_id !== null && (int) $slot->subscription_id === $subscription->id) {
                        $this->writeSlot($slot, null, $now);
                    }
                    $subscription->ended_at = in_array($status, ['expired', 'revoked'], true)
                        ? ($subscription->ended_at ?? $now) : null;
                }
                if ($subscription->isDirty()) {
                    $subscription->row_version++;
                    $subscription->save();
                }
                return $subscription;
            });
    }

    public function release(int $userId, int $subscriptionId, ?int $now = null): void
    {
        $now ??= time();
        $this->withLockedAggregate($userId, $subscriptionId, $now,
            function (Subscription $subscription, object $slot) use ($now) {
                if ($subscription->reservesSlotAt($now)) {
                    throw new DomainException('subscription_still_reserves_slot');
                }
                if ($slot->subscription_id !== null && (int) $slot->subscription_id === $subscription->id) {
                    $this->writeSlot($slot, null, $now);
                }
            });
    }

    private function withLockedAggregate(int $userId, int $subscriptionId, int $now, Closure $action): mixed
    {
        if ($userId <= 0 || $subscriptionId <= 0 || $now < 0) {
            throw new InvalidArgumentException('Invalid shadow reservation identity or timestamp.');
        }
        return DB::transaction(function () use ($userId, $subscriptionId, $now, $action) {
            // All cooperating writers use User -> persistent slot -> sorted subscriptions.
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            DB::table('tx_subscription_slot')->insertOrIgnore([
                'user_id' => $userId, 'slot_no' => 1, 'subscription_id' => null,
                'reserved_at' => null, 'row_version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $slot = DB::table('tx_subscription_slot')->where('user_id', $userId)
                ->where('slot_no', 1)->lockForUpdate()->first();
            if ($slot === null) {
                throw new DomainException('subscription_slot_invalid');
            }
            $ids = [$subscriptionId];
            if ($slot->subscription_id !== null) {
                $ids[] = (int) $slot->subscription_id;
            }
            $subscriptions = Subscription::query()->where('user_id', $userId)->whereIn('id', $ids)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $subscription = $subscriptions->get($subscriptionId);
            if ($subscription === null) {
                throw (new ModelNotFoundException())->setModel(Subscription::class, [$subscriptionId]);
            }
            if ($slot->subscription_id !== null) {
                $occupant = $subscriptions->get((int) $slot->subscription_id);
                if ($occupant === null) {
                    throw new DomainException('subscription_slot_owner_invalid');
                }
                if (!$occupant->reservesSlotAt($now)) {
                    if (in_array($occupant->status, ['active', 'suspended'], true)
                        && $occupant->expires_at !== null && $occupant->expires_at <= $now) {
                        $occupant->status = 'expired';
                        $occupant->ended_at = $occupant->expires_at;
                        $occupant->row_version++;
                        $occupant->save();
                    }
                    $this->writeSlot($slot, null, $now);
                }
            }
            return $action($subscription, $slot);
        }, 5);
    }

    private function reserveLocked(Subscription $subscription, object $slot, int $now): void
    {
        if (!$subscription->reservesSlotAt($now)) {
            throw new DomainException('subscription_not_reservable');
        }
        if ($slot->subscription_id !== null && (int) $slot->subscription_id !== $subscription->id) {
            throw new DomainException('subscription_slot_occupied');
        }
        if ($slot->subscription_id === null) {
            $this->writeSlot($slot, $subscription->id, $now);
        }
    }

    private function writeSlot(object $slot, ?int $subscriptionId, int $now): void
    {
        $updated = DB::table('tx_subscription_slot')->where('user_id', $slot->user_id)
            ->where('slot_no', 1)->where('row_version', $slot->row_version)->update([
                'subscription_id' => $subscriptionId, 'reserved_at' => $subscriptionId === null ? null : $now,
                'row_version' => (int) $slot->row_version + 1, 'updated_at' => $now,
            ]);
        if ($updated !== 1) {
            throw new DomainException('subscription_slot_version_conflict');
        }
        $slot->subscription_id = $subscriptionId;
        $slot->reserved_at = $subscriptionId === null ? null : $now;
        $slot->row_version = (int) $slot->row_version + 1;
        $slot->updated_at = $now;
    }
}
