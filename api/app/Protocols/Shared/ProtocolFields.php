<?php

namespace App\Protocols\Shared;

class ProtocolFields
{
    public static function tlsDefaults(): array
    {
        return [
            'server_name' => '',
            'allow_insecure' => false,
            'ech' => [
                'enabled' => false,
                'config' => '',
                'query_server_name' => '',
                'key' => '',
                'key_path' => '',
                'config_path' => '',
            ],
        ];
    }

    public static function tlsSchema(string $root, array $visibleWhen = []): array
    {
        $echWhen = [
            ...$visibleWhen,
            ['field' => "{$root}.ech.enabled", 'equals' => true],
        ];

        return [
            ['key' => "{$root}.server_name", 'label' => 'SNI / Server Name', 'type' => 'text', 'visible_when' => $visibleWhen],
            ['key' => "{$root}.allow_insecure", 'label' => '跳过证书验证', 'type' => 'checkbox', 'visible_when' => $visibleWhen],
            ['key' => "{$root}.ech.enabled", 'label' => '启用 ECH', 'type' => 'checkbox', 'full' => true, 'visible_when' => $visibleWhen],
            ['key' => "{$root}.ech.config", 'label' => 'ECH Config', 'type' => 'textarea', 'full' => true, 'visible_when' => $echWhen],
            ['key' => "{$root}.ech.query_server_name", 'label' => 'ECH Query Server Name', 'type' => 'text', 'visible_when' => $echWhen],
            ['key' => "{$root}.ech.key_path", 'label' => 'ECH Key Path', 'type' => 'text', 'visible_when' => $echWhen],
            ['key' => "{$root}.ech.key", 'label' => 'ECH Key', 'type' => 'textarea', 'full' => true, 'visible_when' => $echWhen],
            ['key' => "{$root}.ech.config_path", 'label' => 'ECH Config Path', 'type' => 'text', 'full' => true, 'visible_when' => $echWhen],
        ];
    }

    public static function tlsRules(string $root): array
    {
        return [
            $root => 'nullable|array',
            "{$root}.server_name" => 'nullable|string',
            "{$root}.allow_insecure" => 'nullable|boolean',
            "{$root}.ech" => 'nullable|array',
            "{$root}.ech.enabled" => 'nullable|boolean',
            "{$root}.ech.config" => 'nullable|string',
            "{$root}.ech.query_server_name" => 'nullable|string',
            "{$root}.ech.key" => 'nullable|string',
            "{$root}.ech.key_path" => 'nullable|string',
            "{$root}.ech.config_path" => 'nullable|string',
        ];
    }

    public static function realityDefaults(): array
    {
        return [
            'server_name' => '',
            'server_port' => 443,
            'public_key' => '',
            'private_key' => '',
            'short_id' => '',
            'allow_insecure' => false,
        ];
    }

    public static function realitySchema(array $visibleWhen = []): array
    {
        return [
            ['key' => 'reality_settings.server_name', 'label' => 'Reality SNI', 'type' => 'text', 'visible_when' => $visibleWhen],
            ['key' => 'reality_settings.server_port', 'label' => 'Reality 目标端口', 'type' => 'number', 'min' => 1, 'max' => 65535, 'visible_when' => $visibleWhen],
            ['key' => 'reality_settings.public_key', 'label' => 'Reality Public Key', 'type' => 'text', 'full' => true, 'visible_when' => $visibleWhen],
            ['key' => 'reality_settings.private_key', 'label' => 'Reality Private Key', 'type' => 'text', 'full' => true, 'visible_when' => $visibleWhen],
            ['key' => 'reality_settings.short_id', 'label' => 'Reality Short ID', 'type' => 'text', 'visible_when' => $visibleWhen],
            ['key' => 'reality_settings.allow_insecure', 'label' => 'Reality 跳过证书验证', 'type' => 'checkbox', 'visible_when' => $visibleWhen],
        ];
    }

    public static function realityRules(): array
    {
        return [
            'reality_settings' => 'nullable|array',
            'reality_settings.allow_insecure' => 'nullable|boolean',
            'reality_settings.server_name' => 'nullable|string',
            'reality_settings.server_port' => 'nullable|integer|min:1|max:65535',
            'reality_settings.public_key' => 'nullable|string',
            'reality_settings.private_key' => 'nullable|string',
            'reality_settings.short_id' => 'nullable|string',
        ];
    }

    public static function multiplexDefaults(): array
    {
        return [
            'enabled' => false,
            'protocol' => 'yamux',
            'max_connections' => null,
            'min_streams' => null,
            'max_streams' => null,
            'padding' => false,
            'brutal' => [
                'enabled' => false,
                'up_mbps' => null,
                'down_mbps' => null,
            ],
        ];
    }

    public static function multiplexSchema(): array
    {
        $brutalOnly = [['field' => 'multiplex.brutal.enabled', 'equals' => true]];

        return [
            ['key' => 'multiplex.enabled', 'label' => '启用 Multiplex', 'type' => 'checkbox'],
            ['key' => 'multiplex.protocol', 'label' => '复用协议', 'type' => 'text'],
            ['key' => 'multiplex.max_connections', 'label' => '最大连接数', 'type' => 'number'],
            ['key' => 'multiplex.min_streams', 'label' => '最小流数', 'type' => 'number'],
            ['key' => 'multiplex.max_streams', 'label' => '最大流数', 'type' => 'number'],
            ['key' => 'multiplex.padding', 'label' => 'Multiplex Padding', 'type' => 'checkbox'],
            ['key' => 'multiplex.brutal.enabled', 'label' => '启用 Brutal', 'type' => 'checkbox', 'full' => true],
            ['key' => 'multiplex.brutal.up_mbps', 'label' => 'Brutal 上行 Mbps', 'type' => 'number', 'visible_when' => $brutalOnly],
            ['key' => 'multiplex.brutal.down_mbps', 'label' => 'Brutal 下行 Mbps', 'type' => 'number', 'visible_when' => $brutalOnly],
        ];
    }

    public static function multiplexRules(): array
    {
        return [
            'multiplex' => 'nullable|array',
            'multiplex.enabled' => 'nullable|boolean',
            'multiplex.protocol' => 'nullable|string',
            'multiplex.max_connections' => 'nullable|integer',
            'multiplex.min_streams' => 'nullable|integer',
            'multiplex.max_streams' => 'nullable|integer',
            'multiplex.padding' => 'nullable|boolean',
            'multiplex.brutal' => 'nullable|array',
            'multiplex.brutal.enabled' => 'nullable|boolean',
            'multiplex.brutal.up_mbps' => 'nullable|integer',
            'multiplex.brutal.down_mbps' => 'nullable|integer',
        ];
    }

    public static function utlsDefaults(): array
    {
        return [
            'enabled' => false,
            'fingerprint' => 'chrome',
        ];
    }

    public static function utlsSchema(): array
    {
        return [
            ['key' => 'utls.enabled', 'label' => '启用 uTLS', 'type' => 'checkbox'],
            ['key' => 'utls.fingerprint', 'label' => 'uTLS 指纹', 'type' => 'text', 'placeholder' => 'chrome'],
        ];
    }

    public static function utlsRules(): array
    {
        return [
            'utls' => 'nullable|array',
            'utls.enabled' => 'nullable|boolean',
            'utls.fingerprint' => 'nullable|string',
        ];
    }
}
