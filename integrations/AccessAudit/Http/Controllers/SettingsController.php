<?php

namespace Plugin\AccessAudit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Plugin\PluginConfigService;
use Illuminate\Http\Request;
use Plugin\AccessAudit\Services\AuditProcessor;
use Plugin\AccessAudit\Services\ConfigCache;

class SettingsController extends Controller
{
    private const KEYS = [
        'alert_chat_id',
        'default_threshold',
        'default_window_minutes',
        'auto_ban_enabled',
        'report_retention_days',
        'node_health_enabled',
        'node_offline_minutes',
        'node_offline_cooldown',
        'node_spike_enabled',
        'node_spike_window',
        'node_spike_growth_pct',
        'node_spike_min_hits',
        'node_spike_cooldown',
        'access_log_retention_days',
        'analytics_enabled',
        'analytics_retention_days',
    ];

    private const BOOL_KEYS = [
        'auto_ban_enabled',
        'node_health_enabled',
        'node_spike_enabled',
        'analytics_enabled',
    ];

    public function index()
    {
        return response()->json([
            'data' => $this->payload(ConfigCache::get()),
        ]);
    }

    public function update(Request $request, PluginConfigService $configService)
    {
        $data = $request->validate([
            'alert_chat_id' => 'nullable|string|max:64',
            'default_threshold' => 'required|integer|min:1|max:10000',
            'default_window_minutes' => 'required|integer|min:1|max:10080',
            'auto_ban_enabled' => 'required|boolean',
            'report_retention_days' => 'required|integer|min:1|max:3650',
            'node_health_enabled' => 'required|boolean',
            'node_offline_minutes' => 'required|integer|min:1|max:1440',
            'node_offline_cooldown' => 'required|integer|min:60|max:86400',
            'node_spike_enabled' => 'required|boolean',
            'node_spike_window' => 'required|integer|min:1|max:1440',
            'node_spike_growth_pct' => 'required|integer|min:1|max:10000',
            'node_spike_min_hits' => 'required|integer|min:1|max:1000000',
            'node_spike_cooldown' => 'required|integer|min:60|max:86400',
            'access_log_retention_days' => 'required|integer|min:1|max:30',
            'analytics_enabled' => 'required|boolean',
            'analytics_retention_days' => 'required|integer|min:7|max:3650',
        ]);

        foreach (self::BOOL_KEYS as $key) {
            $data[$key] = !empty($data[$key]) ? 1 : 0;
        }
        $data['alert_chat_id'] = trim((string) ($data['alert_chat_id'] ?? ''));

        // updateConfig() 会整体覆盖 Plugin.config，因此必须与已有配置合并，
        // 避免未来新增的插件配置项被这个页面保存时意外清掉。
        $current = $configService->getDbConfig('access_audit');
        $configService->updateConfig('access_audit', array_merge($current, $data));

        // 让上报热路径 / 节点监控尽快读取新配置。
        ConfigCache::flush();
        AuditProcessor::flushConfigCache();

        return response()->json([
            'data' => $this->payload(ConfigCache::get()),
        ]);
    }

    private function payload(array $config): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            if (!isset($config[$key])) {
                continue;
            }
            $item = $config[$key];
            $out[$key] = [
                'label' => (string) ($item['label'] ?? $key),
                'description' => (string) ($item['description'] ?? ''),
                'value' => $item['value'] ?? null,
                'type' => (string) ($item['type'] ?? 'string'),
            ];
        }
        return $out;
    }
}
