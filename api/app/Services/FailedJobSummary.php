<?php

namespace App\Services;

/**
 * Shared, deliberately lossy presentation of Horizon failed-job diagnostics.
 * Never serialize the failed job payload or disclose raw exception strings.
 */
final class FailedJobSummary
{
    public static function jobName(string $payload): string
    {
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return '未知任务';
        }
        $name = (string) ($data['displayName'] ?? $data['job'] ?? '未知任务');
        $name = preg_replace('/[^A-Za-z0-9_\\\\.:-]/', '', $name) ?: '未知任务';

        return substr($name, 0, 120);
    }

    public static function summary(string $exception): string
    {
        return self::exception(strtok($exception, "\n") ?: $exception, 280);
    }

    public static function exception(string $exception, int $maxLength = 12000): string
    {
        $safe = preg_replace('/(Bearer\s+)[^\s]+/i', '$1[REDACTED]', $exception);
        $safe = preg_replace(
            '/((?:password|passwd|secret|api[_-]?key|authorization|access[_-]?token|token)\s*[:=]\s*)[^\s&,"\047]+/i',
            '$1[REDACTED]', $safe ?? ''
        );
        $safe = preg_replace('/(https?:\/\/)[^@\s\/]+@/i', '$1[REDACTED]@', $safe ?? '');

        return mb_substr($safe ?? '', 0, $maxLength);
    }
}
