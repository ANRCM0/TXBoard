<?php

namespace App\Domains\Network;

use App\Models\Server;
use Illuminate\Validation\ValidationException;

/** Editable node projection; counters, timestamps and telemetry are never writable. */
final class NativeNodeConfiguration
{
    public const FIELDS = [
        'type', 'name', 'host', 'port', 'server_port', 'code', 'group_ids', 'route_ids',
        'parent_id', 'machine_id', 'show', 'enabled', 'rate', 'rate_time_enable',
        'rate_time_ranges', 'tags', 'transfer_enable', 'protocol_settings',
        'custom_outbounds', 'custom_routes', 'cert_config',
    ];

    public static function read(Server $node): array
    {
        $result = [];
        foreach (self::FIELDS as $field) $result[$field] = $node->getAttribute($field);
        foreach (['parent_id', 'machine_id'] as $field) {
            $result[$field] = $result[$field] === null ? null : (string) $result[$field];
        }
        foreach (['group_ids', 'route_ids'] as $field) {
            $result[$field] = array_map('strval', $result[$field] ?? []);
        }
        foreach (['tags', 'rate_time_ranges', 'custom_outbounds', 'custom_routes'] as $field) {
            $result[$field] ??= [];
        }
        $result['rate'] = (string) ($result['rate'] ?? '1');
        $result['port'] = (string) $result['port'];
        $result['server_port'] = (int) $result['server_port'];
        $result['transfer_enable'] = $result['transfer_enable'] === null ? null : (string) $result['transfer_enable'];
        $result['show'] = (bool) $result['show'];
        $result['rate_time_enable'] = (bool) $result['rate_time_enable'];
        foreach ($result['rate_time_ranges'] as &$range) $range['rate'] = (string) ($range['rate'] ?? '1');
        return $result;
    }

    public static function encode(array $configuration): string
    {
        return json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function revision(Server $node): string
    {
        return hash('sha256', self::encode(self::read($node)));
    }

    public static function decode(string $json): array
    {
        if (strlen($json) > 131072 || !str_starts_with(ltrim($json), '{')) self::invalid();
        try { $configuration = json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { self::invalid(); }
        if (!is_array($configuration) || array_is_list($configuration)
            || array_diff(array_keys($configuration), self::FIELDS) !== []) self::invalid();
        // This is a full replacement. Explicit fields avoid partial writes silently resetting configuration.
        if (array_diff(self::FIELDS, array_keys($configuration)) !== []) self::invalid();
        foreach (['group_ids', 'route_ids'] as $field) {
            if (!is_array($configuration[$field]) || !array_is_list($configuration[$field])) self::invalid();
            foreach ($configuration[$field] as $id) self::assertId($id);
        }
        foreach (['parent_id', 'machine_id'] as $field) {
            if ($configuration[$field] !== null) self::assertId($configuration[$field]);
        }
        $bytes = $configuration['transfer_enable'];
        if ($bytes !== null && (!is_string($bytes) || !preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', $bytes)
            || strlen($bytes) === 19 && strcmp($bytes, '9223372036854775807') > 0)) self::invalid();
        if (!is_string($configuration['rate']) || !preg_match('/\A(?:0|[1-9][0-9]{0,5})(?:\.[0-9]{1,2})?\z/D', $configuration['rate'])) self::invalid();
        foreach (['custom_outbounds', 'custom_routes', 'tags', 'rate_time_ranges'] as $field) {
            if (!is_array($configuration[$field]) || !array_is_list($configuration[$field])) self::invalid();
        }
        if (count($configuration['custom_outbounds']) > 200 || count($configuration['custom_routes']) > 200
            || count($configuration['rate_time_ranges']) > 100) self::invalid();
        return $configuration;
    }

    private static function assertId(mixed $id): void
    {
        if (!is_string($id) || !preg_match('/\A[1-9][0-9]{0,18}\z/D', $id)
            || strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0) self::invalid();
    }

    private static function invalid(): never
    {
        throw ValidationException::withMessages(['configuration_json' => 'Invalid or incomplete node configuration']);
    }
}
