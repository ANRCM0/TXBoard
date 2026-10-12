<?php

namespace App\Domains\UsageV2;

/** Restricted ASCII/safe-integer schema; not a general RFC 8785 encoder. */
final class UsagePayload
{
    public const LIMIT = 1048576;
    public const SAFE_TIME = 9007199254740991;
    public const MAX_BYTES = '1125899906842624';
    private int $position = 0;
    private string $input = '';

    public function decode(string $raw): array
    {
        if ($raw === '' || strlen($raw) > self::LIMIT) { throw new UsageError('USAGE_JSON_SIZE', 413); }
        $this->input = $raw; $this->position = 0;
        $this->scanValue(0); $this->whitespace();
        if ($this->position !== strlen($raw)) { throw new UsageError('USAGE_JSON_INVALID'); }
        try { $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new UsageError('USAGE_JSON_INVALID'); }
        if (!is_array($value)) { throw new UsageError('USAGE_JSON_INVALID'); }
        return $value;
    }

    public function submission(string $raw): array
    {
        $value = $this->decode($raw); self::keys($value, ['payload','payload_digest']);
        $payload = $value['payload'];
        if (!is_array($payload)) { throw new UsageError('USAGE_PAYLOAD_INVALID'); }
        self::keys($payload, ['batch_id','ended_at','entries','node_id','protocol_version','source_epoch','started_at']);
        if ($payload['protocol_version'] !== 2 || !is_string($payload['batch_id']) || !preg_match('/^[A-Za-z0-9:_-]{8,80}$/D', $payload['batch_id'])) { throw new UsageError('USAGE_PAYLOAD_INVALID'); }
        self::decimal($payload['node_id'], false); self::decimal($payload['source_epoch'], false);
        self::time($payload['started_at']); self::time($payload['ended_at']);
        if ($payload['started_at'] >= $payload['ended_at']) { throw new UsageError('USAGE_INTERVAL_INVALID'); }
        if (!is_array($payload['entries']) || !array_is_list($payload['entries']) || count($payload['entries']) < 1 || count($payload['entries']) > 10000) { throw new UsageError('USAGE_ENTRY_LIMIT'); }
        $previous = null;
        foreach ($payload['entries'] as &$entry) {
            if (!is_array($entry)) { throw new UsageError('USAGE_ENTRY_INVALID'); }
            self::keys($entry, ['access_subject_id','auth_snapshot_id','raw_down_bytes','raw_up_bytes','tariff_revision_id','terms_id','usage_period_id']);
            foreach (['access_subject_id','auth_snapshot_id','tariff_revision_id','terms_id','usage_period_id'] as $key) { self::decimal($entry[$key], false); }
            foreach (['raw_down_bytes','raw_up_bytes'] as $key) { self::decimal($entry[$key], true); if (self::compare($entry[$key], self::MAX_BYTES) > 0) { throw new UsageError('USAGE_BYTES_OVERFLOW'); } }
            $tuple = implode("\0", array_map(fn ($key) => $entry[$key], ['access_subject_id','auth_snapshot_id','tariff_revision_id','usage_period_id']));
            if ($previous !== null && strcmp($tuple, $previous) <= 0) { throw new UsageError('USAGE_ENTRIES_NOT_SORTED_UNIQUE'); }
            $previous = $tuple; ksort($entry, SORT_STRING);
        }
        unset($entry); ksort($payload, SORT_STRING);
        $canonical = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $digest = 'sha256:'.hash('sha256', $canonical);
        if (!is_string($value['payload_digest']) || !hash_equals($digest, $value['payload_digest'])) { throw new UsageError('BATCH_PAYLOAD_CONFLICT', 409); }
        return ['payload' => $payload, 'canonical' => $canonical, 'digest' => $digest];
    }

    public function query(string $raw): array
    {
        $q = $this->decode($raw); self::keys($q, ['protocol_version','node_id','source_epoch','batch_id','payload_digest']);
        self::decimal($q['node_id'], false); self::decimal($q['source_epoch'], false);
        if ($q['protocol_version'] !== 2 || !is_string($q['batch_id']) || !preg_match('/^[A-Za-z0-9:_-]{8,80}$/D', $q['batch_id']) || !is_string($q['payload_digest']) || !preg_match('/^sha256:[a-f0-9]{64}$/D', $q['payload_digest'])) { throw new UsageError('USAGE_QUERY_INVALID'); }
        return $q;
    }

    public static function decimal(mixed $value, bool $zero): void
    {
        if (!is_string($value) || !preg_match($zero ? '/^(0|[1-9][0-9]*)$/D' : '/^[1-9][0-9]*$/D', $value) || self::compare($value, (string) PHP_INT_MAX) > 0) { throw new UsageError('USAGE_DECIMAL_INVALID'); }
    }
    public static function compare(string $a, string $b): int { return strlen($a) <=> strlen($b) ?: strcmp($a, $b); }
    public static function time(mixed $value): void { if (!is_int($value) || $value < 0 || $value > self::SAFE_TIME) { throw new UsageError('USAGE_TIME_INVALID'); } }
    public static function keys(array $value, array $expected): void { $actual = array_keys($value); sort($actual); sort($expected); if ($actual !== $expected) { throw new UsageError('USAGE_FIELDS_INVALID'); } }

    private function whitespace(): void { while (isset($this->input[$this->position]) && str_contains(" \t\r\n", $this->input[$this->position])) { $this->position++; } }
    private function stringToken(): string
    {
        $start = $this->position++; $escaped = false;
        while (isset($this->input[$this->position])) {
            $char = $this->input[$this->position++];
            if ($escaped) { $escaped = false; continue; }
            if ($char === '\\') { $escaped = true; continue; }
            if ($char === '"') {
                try { return json_decode(substr($this->input, $start, $this->position - $start), true, 32, JSON_THROW_ON_ERROR); }
                catch (\JsonException) { throw new UsageError('USAGE_JSON_INVALID'); }
            }
        }
        throw new UsageError('USAGE_JSON_INVALID');
    }
    private function scanValue(int $depth): void
    {
        if ($depth > 16) { throw new UsageError('USAGE_JSON_DEPTH'); }
        $this->whitespace(); $char = $this->input[$this->position] ?? '';
        if ($char === '"') { $this->stringToken(); return; }
        if ($char === '{' || $char === '[') {
            $object = $char === '{'; $end = $object ? '}' : ']'; $seen = []; $this->position++; $this->whitespace();
            if (($this->input[$this->position] ?? '') === $end) { $this->position++; return; }
            while (true) {
                $this->whitespace();
                if ($object) {
                    if (($this->input[$this->position] ?? '') !== '"') { throw new UsageError('USAGE_JSON_INVALID'); }
                    $key = $this->stringToken(); if (isset($seen[$key])) { throw new UsageError('USAGE_JSON_DUPLICATE_KEY'); } $seen[$key] = true;
                    $this->whitespace(); if (($this->input[$this->position++] ?? '') !== ':') { throw new UsageError('USAGE_JSON_INVALID'); }
                }
                $this->scanValue($depth + 1); $this->whitespace(); $next = $this->input[$this->position++] ?? '';
                if ($next === $end) { return; } if ($next !== ',') { throw new UsageError('USAGE_JSON_INVALID'); }
            }
        }
        if (preg_match('/\G(?:true|false|null|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/A', $this->input, $match, 0, $this->position)) { $this->position += strlen($match[0]); return; }
        throw new UsageError('USAGE_JSON_INVALID');
    }
}
