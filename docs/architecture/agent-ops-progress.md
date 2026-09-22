# Agent Ops 进度与阶段复盘

> 最后更新：2026-09-23
>
> Phase 5 实现基线：TXBoard `7db01019f7ee9de47e2e445d4f0255fa113bfe59`
>
> 状态：Phase 0–5 已实现；自动修复保持关闭。

这份文档回答三个问题：

1. Agent Ops / MCP 当前到底做到哪一步；
2. 每个阶段交付了什么、如何验证、哪些设计决策值得保留；
3. 后续开发应该从哪里继续，而不是重新摸索已经解决过的问题。

详细架构规则见 [Agent Ops / MCP Architecture](./agent-ops.md)。新增功能的实施步骤见 [Agent Ops 开发指南](./agent-ops-development-guide.md)。

---

## 1. 当前能力快照

当前 TXBoard Agent Ops 已经形成完整闭环：

```text
Fleet inspection / Agent observation
  -> normalized diagnosis
  -> incident timeline
  -> deterministic remediation plan
  -> approval-gated action request
  -> Admin approval
  -> Redis / WebSocket dispatch
  -> TX-Node typed operation
  -> ops.result
  -> post-action verification
  -> audit / updated fleet health
```

核心能力：

- 独立 Agent Token；
- functional ability；
- node / machine target scope；
- Agent Audit；
- Read-only 诊断；
- approval-gated runtime action；
- TX-Node typed operation；
- request ID / replay protection；
- bounded network diagnostics；
- bounded/redacted log tail；
- MCP Gateway；
- Agent Token 创建后的 Self-Connect 提示词与版本匹配 Markdown guide；
- Admin Agent 运维页面；
- fleet health；
- 五分钟定时巡检；
- inspection history；
- incident timeline；
- remediation plan；
- post-action verification。

明确没有开放：

- 任意 Shell / SSH；
- 任意 SQL / Redis；
- 任意文件路径读取；
- 任意 Docker 命令；
- 任意 HTTP fetch；
- Agent 自行批准动作；
- 自动修复。

---

## 2. Phase 完成矩阵

| Phase | 状态 | 核心交付 | 验收重点 |
| --- | --- | --- | --- |
| Phase 0 | ✅ | 架构边界、风险模型、HTTP / Node Ops contract | 文档明确 Control Plane / Data Plane / MCP 边界 |
| Phase 1 | ✅ | system / machine / node / metrics / diagnosis / traffic / queue / audit | Agent 只读链路可独立工作 |
| Phase 2 | ✅ | typed Node Ops、result correlation、timeout、replay protection | 不存在 generic exec；重复 request ID 不重复执行 |
| Phase 3 | ✅ | Streamable HTTP MCP Gateway | MCP 只调用 Agent Ops API，不直连 DB / Redis / Node |
| Phase 4 | ✅ | approval、target scope、cooldown、queue limit、audit hardening | 写操作必须 pending -> Admin approval |
| Phase 5 | ✅ | fleet health、inspection、timeline、remediation、verify | ACK 与真实恢复分离；自动执行仍关闭 |

---

## 3. 已合并里程碑

### TXBoard

| PR | Merge commit | 作用 |
| --- | --- | --- |
| #27 — `feat: add Agent Ops and MCP gateway` | `b40de8fc6442e1270701bf8b08070bfeedd3e228` | Phase 1–3 主链路：Agent API、Token、Audit、Actions、MCP、Admin、WS result |
| #28 — `feat: harden Agent Ops policy and scopes` | `79ff2f1523b2628d59743f14ac0380df812bdda9` | Phase 4：target scope、cooldown、pending limit、bounded logs、审计增强 |
| #29 — `feat: complete Agent Ops Phase 5 AI-native operations` | `7db01019f7ee9de47e2e445d4f0255fa113bfe59` | Phase 5：fleet health、inspection、timeline、remediation、verification |

### TX-Node

| PR | Merge commit | 作用 |
| --- | --- | --- |
| #20 — `feat: execute typed TXBoard Agent Ops requests` | `72ccd651336e3f23f408716f35eae4d025383a18` | typed `ops.*`、machine-mode routing、`ops.result`、replay protection |
| #21 — `feat: harden Agent Ops log retrieval` | `bc7255b2ce4f5194588f32fe1eaa5a2252d298ad` | bounded/redacted application log tail |

这些 PR 是后续排查回归和理解设计演进时的第一组参考点。

---

## 4. 实现地图

### 4.1 TXBoard Control Plane

```text
api/app/Services/AgentOps/
  AgentAbility.php
  AgentTargetScope.php
  AgentOpsService.php
  AgentActionService.php
  AgentInsightService.php
```

职责：

- `AgentAbility`：functional permissions；
- `AgentTargetScope`：node / machine resource boundary；
- `AgentOpsService`：规范化只读状态；
- `AgentActionService`：action lifecycle、policy、approval、dispatch；
- `AgentInsightService`：fleet health、inspection、timeline、remediation、verification。

重要原则：MCP、Controller、Admin UI 不应复制这些 domain rules。

### 4.2 Agent HTTP 层

主要入口：

```text
api/app/Http/Controllers/V2/Agent/AgentOpsController.php
api/app/Http/Routes/V2/AgentRoute.php
```

这里负责：

- 参数校验；
- ability check；
- target-scope check；
- 调用 AgentOps service；
- 返回稳定 envelope。

这里不负责 Node Ops 执行逻辑。

### 4.3 Admin 控制面

```text
api/app/Http/Controllers/V2/Admin/AgentOpsController.php
api/app/Http/Routes/V2/Admin/AgentRoute.php
web/admin/src/api/agent.ts
web/admin/src/pages/system/AgentOpsPage.tsx
```

当前支持：

- Agent Token 创建 / 撤销；
- ability / resource scope；
- pending action approve / reject；
- fleet health；
- inspection history；
- manual inspection。

### 4.4 MCP Gateway

```text
mcp/src/index.ts
```

定位：protocol adapter。

允许做：

- MCP schema；
- Bearer forwarding；
- tool -> Agent HTTP endpoint mapping；
- structured error。

禁止做：

- MySQL query；
- Redis publish；
- Node WebSocket；
- SSH；
- 业务权限判断；
- 自动 approve。

### 4.5 Node execution

TX-Node 负责：

- allow-listed `ops.*`；
- local input validation；
- runtime execution；
- request ID replay cache；
- `ops.result`。

TXBoard 与 TX-Node 的唯一兼容来源应是：

```text
contracts/node-protocol/agent-ops-v1.md
```

---

## 5. 当前 MCP Tool 分层

### READ / Insight

- `txboard_system_status`
- `txboard_list_machines`
- `txboard_list_nodes`
- `txboard_node_metrics`
- `txboard_diagnose_node`
- `txboard_traffic_summary`
- `txboard_queue_status`
- `txboard_audit_logs`
- `txboard_fleet_health`
- `txboard_inspection_history`
- `txboard_incident_timeline`
- `txboard_remediation_plan`
- `txboard_action_status`
- `txboard_verify_action`

### OPERATE / Approval-gated

- `txboard_full_sync_node`
- `txboard_reload_node_config`
- `txboard_restart_kernel`
- `txboard_network_test`
- `txboard_tail_logs`

原则：工具数量不是目标。只有当一个操作能被**明确建模、限制输入、审计、验证**时才应该增加 MCP Tool。

---

## 6. 关键设计决策复盘

### 6.1 不开放 Shell

这是整个 Agent Ops 最重要的约束。

如果一个需求看起来“需要 Shell”，优先把它改写成：

```text
typed operation
+ fixed input schema
+ timeout
+ error code
+ audit
+ verification
```

例如 kernel restart，而不是 `exec("systemctl restart ...")`。

### 6.2 MCP 不是第二控制面

MCP Gateway 不掌握 DB / Redis / WebSocket 控制权。

收益：

- 权限规则只有一份；
- HTTP API 和 MCP 行为一致；
- 可单独关闭 MCP；
- MCP SDK 升级不会污染核心 domain logic。

### 6.3 ACK 与恢复状态分离

`AgentAction.status=succeeded` 只说明操作执行结果返回成功，不等于业务状态恢复。

因此 Phase 5 增加：

```text
txboard_verify_action
```

例如 kernel restart ACK 成功，但 WebSocket 当前仍离线，verification 必须是 `failed`。

### 6.4 资源权限与功能权限分离

两个维度：

```text
functional ability
×
target scope
```

例：

```text
agent:nodes:diagnose
+
agent:target:node:12
```

表示“可以诊断，但只允许 Node #12”。

以后不要把资源 ID 编进新的 functional ability。

### 6.5 Remediation 是建议层，不是执行层

`AgentInsightService` 可以说：

> kernel_not_running -> 建议 restart kernel

但不能自己创建 approved action。

当前 policy 固定为：

```text
recommend
  -> request action
  -> approve
  -> execute
  -> verify
```

这是未来引入自动修复时必须显式修改的安全边界。

---

## 7. 开发过程中发现并固化的工程经验

### 7.1 Node action 必须考虑重放

WebSocket、HTTP、Agent 都可能重试。

因此：

- TXBoard action 有 request ID；
- TX-Node 对近期 request ID 缓存结果；
- 非幂等动作不得因网络重试重复执行。

新增 Node Ops 时必须回答：

> 同一个 request ID 再来一次，会发生什么？

### 7.2 日志读取必须把“来源”与“路径”分开

正确：

```text
source = application
```

错误：

```text
path = /var/log/anything
```

当前 TX-Node 只读 operator 已配置的 application log，并限制：

- <= 200 lines；
- <= 65536 bytes；
- bounded tail window；
- secret redaction。

### 7.3 Go / PHP / TS 三端契约要同时验证

Agent Ops 涉及：

- Laravel；
- React / TypeScript；
- MCP TypeScript；
- TX-Node Go。

仅某一端编译通过不能算完成。

### 7.4 CI cancellation 不等于 failure

文档提交可能因为 GitHub Actions concurrency 取消旧 run。

复盘 CI 时区分：

- `failure`：实际失败；
- `cancelled`：可能被更新 commit 替代；
- 最新可执行代码 head 的成功 run 才是有效验收证据。

---

## 8. 当前已知限制

这些不是遗漏，而是当前阶段明确保留的边界。

### 8.1 自动修复关闭

当前：

```text
automatic_remediation_enabled = false
```

不要在 MCP Prompt 层绕过它。

### 8.2 日志没有可靠时间范围过滤

TX-Node 当前 file log 不保证包含可解析日历日期。

所以 v1 只提供：

- tail lines；
- byte bound。

在日志格式先标准化之前，不实现伪精确的 `from/to` 时间过滤。

### 8.3 MCP Gateway 随 TXBoard 主镜像分发

MCP Gateway 仍位于 `mcp/`，但生产 artifact 已与 TXBoard 主镜像统一：

```text
Caddy /mcp
  -> loopback MCP Gateway
  -> Agent Ops HTTP API
```

默认 `ENABLE_MCP=false`，因此不使用 AI Agent 的部署不会启动额外 Node 进程。启用后 Gateway 只监听 loopback，Caddy 是唯一公共入口；Agent Ops 权限、target scope、approval、audit 与 TX-Node typed operation 边界保持不变。

源码 Compose 的历史 `mcp` profile 继续保留为兼容方式，但也复用同一个 TXBoard image，不再需要第二个 MCP production image。

长期如果 MCP 的 release cadence、auth integration 或 client compatibility 明显独立于 TXBoard，仍可迁移到独立 `TXBoard-MCP` 仓库，但必须继续只消费 Agent Ops HTTP API。

### 8.4 Agent Self-Connect v1 / v2 Pairing

Self-Connect v1 建立了版本匹配的公开 guide：

```text
/.well-known/txboard-agent-connect.md
```

Self-Connect v2 在不改变长期 Agent Token 事实来源的前提下增加一次性 pairing：

```text
Admin creates Agent Token
        ↓
encrypted pairing payload -> Redis TTL (default 600s)
        ↓
one-sentence prompt = guide URL + txbp_...
        ↓
Agent POST /api/v2/agent/pairings/redeem
        ↓
one-time long-lived Agent Token delivery
        ↓
Agent local secret/config
        ↓
existing /mcp
```

运行时规则：

- pairing code 具有至少 128-bit entropy，默认 TTL 600 秒，硬限制 60–900 秒；
- raw code 不进入 Redis key，key 使用 SHA-256 派生；
- Redis payload 用 APP_KEY 加密；
- 同一 code 通过 cache-backed lock + pull 保证一次性兑换；
- Redis 只保存短期 credential-delivery state，不拥有 abilities / target scope / expiry / revocation；
- token 被撤销后，即使 pairing 还在 TTL 内也无法兑换；
- Redis 不可用时 token 创建仍成功，Admin 回退到 v1 手动 secret 方式；
- 不新增数据库 migration，不把 pairing 状态放进 Octane worker 全局内存。

安全边界继续保持：

- 长期 plain-text Agent Token 不进入一句话提示词；
- pairing code 是短期、单次临时 bearer capability；
- enrollment endpoint 不允许 Agent 直连 Redis；
- 不安装第二套 TXBoard MCP Server；
- 不允许 MySQL / Redis / TX-Node / SSH / Docker / generic shell 成为替代控制路径；
- onboarding 只做 read-only MCP 验证；
- abilities、target scope、approval、audit 仍由 Agent Ops runtime 执行。

### 8.5 当前 anomaly explanation 是 deterministic

Phase 5 不在 Control Plane 内部调用 LLM。

这使结果：

- 可测试；
- 可复现；
- 可审计。

外部 Agent 可以基于结构化事实进一步解释，但不能把推测写回为“事实”。

---

## 9. 当前生产验收基线

一次 Agent Ops 相关改动在合并前至少应确认：

- [ ] API CI 通过；
- [ ] Web CI 通过（涉及 Admin UI 时）；
- [ ] MCP typecheck/build 通过（涉及 MCP 时）；
- [ ] TXBoard image build / runtime smoke 通过（涉及运行时依赖时）；
- [ ] TX-Node Go CI 通过（涉及 Node Ops 时）；
- [ ] HTTP contract 已同步；
- [ ] Node protocol contract 已同步（涉及 TX-Node 时）；
- [ ] ability / target scope 已定义；
- [ ] action 是否 approval-gated 已明确；
- [ ] audit 字段足够定位请求；
- [ ] replay / timeout / partial failure 行为已定义；
- [ ] mutation 有独立 verification signal；
- [ ] 不新增 generic exec / path / URL / SQL / Redis 能力。

---

## 10. Module Platform Phase H integration

Agent Ops 现已在 Module Platform v1 中完成只读 Registry enrichment。

这一步没有新增 Agent action、Token、approval 或 MCP 能力，而是把现有 Agent Ops runtime 的可观测事实投影到 `agent_ops` Module：

```text
AgentOpsService::systemStatus()
        ↓
AgentOpsModuleAdapter
        ↓
ModuleDescriptor.health / health_details
        ↓
Module Registry
        ↓
Module Center
```

当前 Module health 只使用三个已有、低成本、本地只读检查：

- `schedule`；
- `horizon`；
- `websocket_server`。

全部为 true 时 Module 为 `healthy`；任何 false/unknown 为 `degraded`。health collection 异常不会隐藏 Module，也不会把原始异常、Redis/SQL 信息或 secret 暴露到 Registry。

Descriptor 同时投影 system-owned `description` / `author` metadata，并继续声明：

```text
agent.api
agent.admin
```

没有改变的安全边界：

```text
MCP
  -> Agent Ops API
  -> permission
  -> target scope
  -> approval / audit
  -> TXBoard domain service
  -> TX-Node typed operation
```

Module Registry 不读取具体 Agent token、target scope、pending action、approval reason、audit payload、fleet finding 或 node address/metrics，也不执行任何 Agent operation。

---

## 11. 下一阶段建议

Phase 0–5 已经完成，因此后续不再用“补完 Agent Ops 基础架构”的方式推进。

优先方向：

### P1 — Observability quality

- 标准化 TX-Node 日志时间戳；
- 更完整的 node capability / protocol version reporting；
- 更稳定的 metrics freshness / telemetry confidence；
- incident timeline 增加更多可靠事件源。

### P1 — Verification coverage

为每一个 mutation 建立明确 verification contract：

```text
action
-> expected observable state
-> timeout
-> passed / failed / inconclusive
```

### P2 — Notification

将 fleet inspection 的**状态变化**接入通知层，而不是每五分钟重复通知：

- critical transition；
- recovery transition；
- repeated-failure escalation。

通知必须消费结构化 inspection state，不应从原始日志猜测。

### P2 — Capability negotiation

让 TXBoard 明确知道 TX-Node 支持哪些 `ops.*` / protocol version，避免向旧 Node 下发未知 operation。

### P3 — Narrow auto-remediation

只有在以上能力成熟后才评估。

任何自动修复规则必须至少具备：

- fixed trigger；
- fixed action；
- per-node cooldown；
- attempt limit；
- verification；
- rollback / stop condition；
- audit；
- kill switch。

Level 3 destructive action 不进入自动修复。

---

## 12. 复盘入口

排查 Agent Ops 问题时建议按这个顺序：

```text
1. docs/architecture/agent-ops.md
2. docs/architecture/agent-ops-progress.md
3. contracts/http/agent-ops-v1.md
4. contracts/node-protocol/agent-ops-v1.md
5. Agent Ops services
6. Agent / Admin controller
7. MCP mapping
8. TX-Node typed handler
9. tests
10. relevant merged PR
```

这样可以先确认 contract 和边界，再进入实现细节，避免直接从某个 Controller 或 MCP Tool 反推架构。
