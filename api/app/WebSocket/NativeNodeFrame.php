<?php

namespace App\WebSocket;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** Transport envelope; no bearer credentials, subscription tokens or secrets. */
final class NativeNodeFrame
{
    public const MAX_BYTES = 1048576;

    public static function encode(string $event, array $data = [], ?string $requestId = null): string
    {
        return json_encode([
            'protocol_version' => 1,
            'event' => $event,
            'data' => $data,
            'request_id' => $requestId ?? (string) Str::uuid(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public static function parse(mixed $raw): array
    {
        if (!is_string($raw) || strlen($raw) > self::MAX_BYTES) {
            throw new InvalidArgumentException('FRAME_TOO_LARGE');
        }
        try {
            $message = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('INVALID_FRAME');
        }
        if (!is_array($message) || array_is_list($message)
            || ($message['protocol_version'] ?? null) !== 1
            || !is_string($message['event'] ?? null)
            || !preg_match('/^[a-z][a-z0-9.]{0,63}$/D', $message['event'])
            || !is_array($message['data'] ?? null)
            || array_is_list($message['data']) && $message['data'] !== []
            || !is_string($message['request_id'] ?? null)
            || !preg_match('/^[A-Za-z0-9._:-]{1,80}$/D', $message['request_id'])) {
            throw new InvalidArgumentException('INVALID_FRAME');
        }
        return $message;
    }
}
