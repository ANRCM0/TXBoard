# AccessAudit 访问审计插件

节点上报用户访问目标，Xboard 侧匹配名单、达到阈值自动封禁用户、推送 Telegram 告警；支持分节点查看 + 节点级异常通报（上报中断 / 命中突增）。

## 架构

```
节点服务器                                Xboard 面板
┌───────────────────┐                  ┌──────────────────────────┐
│ sing-box: tx-node  │                  │ AccessAudit 插件           │
│   内嵌 audit 模块 ─┤                  │  ├─ 规则管理（名单）         │
│       或           │  HTTPS POST      │  ├─ /report 接收上报       │
│ xray: audit-agent  ├─────────────────►│  ├─ 阈值判定 + 自动封禁     │
│   旁路 tail 日志   │  ServerV2 认证   │  ├─ 分节点查看 / 节点状态   │
└───────────────────┘  (token+node_id) │  └─ TG 告警（封禁+节点异常）│
                                       └──────────────────────────┘
```

**认证（v2.0 起）**：上报接口复用 Xboard 原版节点认证（`ServerV2` 中间件）——`token`（后台「系统设置 → 节点」的 server_token）+ `node_id`，machine 模式用 machine token。不再有独立的上报密钥；node_id 从认证属性取，上报方无法伪造。

**关键设计**：节点侧先从插件拉取规则（`GET /rules`）在本地预过滤，只上报命中项。全量访问日志不出节点，量小、合规压力小。

## 安装

1. 上传 `AccessAudit/` 到 `plugins/` 下（zip 上传后台插件管理，或 Docker 部署放宿主机 `/opt/Xboard/plugins/`）
2. tinker 安装启用：
   ```bash
   docker exec xboard php artisan tinker --execute='
   $m = new App\Services\Plugin\PluginManager();
   $m->install("access_audit"); $m->enable("access_audit");'
   ```
3. `docker restart xboard`（Octane 重启后新路由才生效）
4. 管理页面：`/plugin/access-audit`（页面内置管理员登录）
5. 节点异常通报在插件配置里调参（开关/中断阈值/突增窗口/增长率）

## 节点侧接入（二选一，按内核选）

| 内核 | 方案 | 说明 |
|---|---|---|
| sing-box | **tx-node**（推荐） | xboard-node 二开版，内嵌 audit 模块。config.yml 加 `audit: enabled: true` 即可，复用面板 token 零额外配置。需要全量访问日志再加 `report_all: true`（管理页「访问日志」tab 可查看/筛选，默认保留 3 天）。见仓库 README |
| xray | **audit-agent.py** 旁路 | tail xray access log。配置 `server_token` + `node_id` + `log_path`。见 `node-agent/README.md` |

## 上报接口（节点侧实现参考）

```
POST {panel}/api/v1/plugin/access-audit/report
Content-Type: application/json

{
  "token": "<server_token>",
  "node_id": 1,
  "events": [
    {"user_id": 123, "target": "bad-site.com", "source_ip": "1.2.3.4", "matched": true},
    ...
  ]
}
```

- 单次最多 500 条 events，建议攒批 10~30 秒发一次
- `target` 支持域名或 IP
- `matched`（v2.1+）：true=命中规则（走封禁流程），false=仅记入全量访问日志；缺省视为 true（兼容旧节点）
- `node_id` 必须与认证身份一致；服务端从 `ServerV2` 认证属性取，请求体中的 `node_id` 仅作参考
- 响应：`{"data": {"node_id": 1, "received": N, "matched": M, "banned": B}}`

### 规则下发接口（节点本地预过滤用）

```
GET {panel}/api/v1/plugin/access-audit/rules?token=<server_token>&node_id=1
```

返回启用的规则列表，agent 按 `match_type`（domain / domain_suffix / keyword / ip_cidr）本地匹配，只上报命中项。

### 节点侧前提

xboard-node 使用 xray 或 sing-box 内核，需要在节点配置中开启 sniffing + access log 才能拿到目标域名：

- **sing-box**：inbound 加 `"sniff": true`，log 输出 access 级别
- **xray**：inbound `sniffing.enabled: true`，log access 输出到文件

agent 解析 access log 行格式提取 `(用户邮箱/uuid, 目标域名)`。注意 xboard-node 的 access log 中用户标识是 uuid，agent 需要先从面板用户接口建 uuid→user_id 映射，或上报时带 uuid、面板侧反查（当前版本要求 user_id，后续可加 uuid 支持）。

## 封禁行为

- 命中规则 → 写入 `audit_reports`
- 窗口内（默认 60 分钟）命中同规则达到阈值（默认 3 次）→ `v2_user.banned = 1`，UserObserver 自动同步到节点，用户立即掉线
- 已封禁用户不再重复触发
- `auto_ban_enabled = 0` 时只记录+TG 告警，不封禁
- 手动封禁/解封走管理页，记 `audit_ban_logs`，同样推 TG

## 数据库表

| 表 | 用途 |
|---|---|
| `audit_rules` | 审计规则（名单） |
| `audit_reports` | 命中记录（按 `report_retention_days` 自动清理，默认 30 天） |
| `audit_ban_logs` | 封禁/解封操作日志 |
| `audit_node_status` | 节点上报状态（最后上报时间、累计批/条/命中/封禁数） |
| `audit_access_logs` | 全量访问日志（仅在节点开启 `report_all` 时写入，按 `access_log_retention_days` 清理，默认 3 天） |

## 配置项

| 键 | 默认 | 说明 |
|---|---|---|
| `alert_chat_id` | 空 | TG 告警 chat_id，空=第一个绑定了 TG 的管理员 |
| `default_threshold` | 3 | 默认封禁阈值 |
| `default_window_minutes` | 60 | 默认统计窗口（分钟） |
| `auto_ban_enabled` | 1 | 是否自动封禁 |
| `report_retention_days` | 30 | 命中记录保留天数 |
| `node_health_enabled` | 1 | 节点异常监控开关（上报中断 / 命中突增） |
| `node_offline_minutes` | 10 | 上报中断阈值（分钟） |
| `node_offline_cooldown` | 3600 | 上报中断告警冷却（秒） |
| `node_spike_enabled` | 1 | 命中突增监控开关 |
| `node_spike_window` | 30 | 突增统计窗口（分钟） |
| `node_spike_growth_pct` | 200 | 突增增长阈值（%） |
| `node_spike_min_hits` | 10 | 突增最小命中数 |
| `node_spike_cooldown` | 1800 | 突增告警冷却（秒） |
| `access_log_retention_days` | 3 | 全量访问日志保留天数 |

> 上报无需配置密钥：认证复用节点原有 `server_token` + `node_id`（`ServerV2` 中间件），插件侧无 `api_secret` 配置项。

## 已知限制

- **UDP 连接的强制断开**：tx-node 侧 UDP/QUIC 连接不进入连接跟踪表（`connMap`），因此面板下发的 `CloseConnection` 对 UDP 连接无效。封禁用户后，UDP 会话需等其自然超时或客户端重连（重连时会被 `RemoveUsers` 拦截）。TCP 连接不受影响。
- **xray 侧 user_id 识别**：`audit-agent.py` 依赖 access log 中 `email: user@<数字ID>` 格式提取 user_id，UUID 形式的用户标识暂不支持（后续版本补充）。

## 合规提示

本插件只在**命中名单**时记录访问目标，不做全量访问审计。名单内容（赌博/诈骗/违法站点等）由管理员自行维护并对合规性负责。
