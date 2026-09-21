<?php

namespace App\Protocols\Definitions;

use App\Models\Server;
use App\Protocols\AbstractProtocolDefinition;
use App\Utils\Helper;

class ShadowsocksProtocol extends AbstractProtocolDefinition
{
    public function type(): string
    {
        return Server::TYPE_SHADOWSOCKS;
    }

    public function label(): string
    {
        return 'Shadowsocks';
    }

    public function defaults(): array
    {
        return [
            'cipher' => 'aes-128-gcm',
            'obfs' => '',
            'obfs_settings' => [
                'host' => '',
                'path' => '',
            ],
            'plugin' => '',
            'plugin_opts' => '',
        ];
    }

    public function formSchema(): array
    {
        return [
            [
                'key' => 'cipher',
                'label' => '加密方式',
                'type' => 'select',
                'options' => [
                    ['value' => 'aes-128-gcm', 'label' => 'aes-128-gcm'],
                    ['value' => 'aes-256-gcm', 'label' => 'aes-256-gcm'],
                    ['value' => 'chacha20-ietf-poly1305', 'label' => 'chacha20-ietf-poly1305'],
                    ['value' => '2022-blake3-aes-128-gcm', 'label' => '2022-blake3-aes-128-gcm'],
                    ['value' => '2022-blake3-aes-256-gcm', 'label' => '2022-blake3-aes-256-gcm'],
                    ['value' => '2022-blake3-chacha20-poly1305', 'label' => '2022-blake3-chacha20-poly1305'],
                ],
            ],
            ['key' => 'obfs', 'label' => 'Obfs', 'type' => 'text', 'placeholder' => 'http / tls'],
            ['key' => 'obfs_settings.host', 'label' => 'Obfs Host', 'type' => 'text'],
            ['key' => 'obfs_settings.path', 'label' => 'Obfs Path', 'type' => 'text'],
            ['key' => 'plugin', 'label' => 'Plugin', 'type' => 'text', 'placeholder' => 'v2ray-plugin / shadow-tls / restls'],
            ['key' => 'plugin_opts', 'label' => 'Plugin Options', 'type' => 'text'],
        ];
    }

    public function rules(): array
    {
        return [
            'cipher' => 'required|string',
            'obfs' => 'nullable|string',
            'obfs_settings' => 'nullable|array',
            'obfs_settings.path' => 'nullable|string',
            'obfs_settings.host' => 'nullable|string',
            'plugin' => 'nullable|string',
            'plugin_opts' => 'nullable|string',
        ];
    }

    public function buildNodeConfig(Server $node): array
    {
        $settings = $this->normalize($node->protocol_settings ?? []);
        $cipher = (string) ($settings['cipher'] ?? '');

        return [
            ...$this->baseConfig($node),
            'cipher' => $cipher,
            'plugin' => $settings['plugin'] ?? null,
            'plugin_opts' => $settings['plugin_opts'] ?? null,
            'server_key' => match ($cipher) {
                '2022-blake3-aes-128-gcm' => Helper::getServerKey($node->created_at, 16),
                '2022-blake3-aes-256-gcm' => Helper::getServerKey($node->created_at, 32),
                default => null,
            },
        ];
    }
}
