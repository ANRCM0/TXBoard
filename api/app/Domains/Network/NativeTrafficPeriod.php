<?php

namespace App\Domains\Network;

use RuntimeException;

/** A Core-issued observed reset epoch; this is no offline authorization lease. */
final class NativeTrafficPeriod
{
    public function issue(int $userId, int $epoch): string
    {
        if ($userId <= 0 || $epoch < 0) throw new RuntimeException('TRAFFIC_PERIOD_IDENTITY_INVALID');
        $identity = "p1.{$userId}.{$epoch}";
        return $identity . '.' . hash_hmac('sha256', 'tx-native-traffic-period-v1|' . $identity, $this->key());
    }

    public function verify(string $token, int $userId, int $currentEpoch): int
    {
        if (strlen($token) > 130 || !preg_match('/^p1\.([1-9][0-9]{0,18})\.(0|[1-9][0-9]{0,18})\.([a-f0-9]{64})$/D', $token, $m)) {
            throw new NodeReportError('INVALID_TRAFFIC_PERIOD', 'Invalid traffic period');
        }
        foreach ([$m[1], $m[2]] as $integer) {
            if (strlen($integer) > strlen((string) PHP_INT_MAX)
                || (strlen($integer) === strlen((string) PHP_INT_MAX) && strcmp($integer, (string) PHP_INT_MAX) > 0)) {
                throw new NodeReportError('INVALID_TRAFFIC_PERIOD', 'Invalid traffic period');
            }
        }
        $epoch = (int) $m[2];
        if ((int) $m[1] !== $userId || $userId <= 0 || $currentEpoch < 0 || $epoch > $currentEpoch
            || !hash_equals($this->issue($userId, $epoch), $token)) {
            throw new NodeReportError('INVALID_TRAFFIC_PERIOD', 'Invalid traffic period');
        }
        return $epoch;
    }

    private function key(): string
    {
        $configured = (string) config('app.key', '');
        if (str_starts_with($configured, 'base64:')) {
            $key = base64_decode(substr($configured, 7), true);
        } else {
            $key = $configured;
        }
        if (!is_string($key) || strlen($key) < 32) throw new RuntimeException('TRAFFIC_PERIOD_KEY_UNAVAILABLE');
        return $key;
    }
}
