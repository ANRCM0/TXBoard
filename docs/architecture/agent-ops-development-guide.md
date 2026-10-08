# Agent Ops 开发指南

> 用途：指导后续新增 Agent / MCP / TX-Node 运维能力。
>
> 前置阅读：[Agent Ops Architecture](./agent-ops.md) 与相应的 HTTP / TX-Node 协议契约。

---

## 1. 开发前先分类

任何新需求先归入以下一种类型。

### A. READ

只读取现有状态，不改变 runtime / persistent state。

例：

- 新的 metrics summary；
- 新的 incident query；
- 新的 capability query。

通常路径：

```text
domain/service
-> Agent HTTP API
-> MCP tool
```

### B. INSIGHT

组合已有事实，产生结构化判断或建议，但不执行 mutation。

例：

- anomaly classification；
- remediation guidance；
- verification。

通常路径：

```text
AgentOpsService facts
-> AgentInsightService
-> Agent API
-> MCP
```

### C. OPERATE

改变 Node runtime，但不直接改变业务核心数据。

例：

- kernel restart；
- config reload。

必须：

```text
request
-> pending
-> Admin approval
-> dispatch
-> result
-> verify
```

### D. DANGEROUS

改变持久配置、用户权限、凭据、计费、删除资源。

默认不进入 MCP。

如果确实要做，必须单独设计 threat model、ability、approval 与 recovery，不允许照搬 OPERATE。

---

## 2. 不变量

任何后续开发不得破坏以下规则：

1. MCP 不直连 MySQL；
2. MCP 不 publish Redis；
3. MCP 不直连 TX-Node；
4. Agent 不能调用 generic shell；
5. Agent 不能指定任意 filesystem path；
6. Agent 不能指定任意 outbound URL 形成 SSRF；
7. Node Ops 必须 allow-list；
8. mutation 默认 approval-gated；
9. functional ability 与 target scope 同时生效；
10. secret 不进入 Agent output / audit；
11. ACK 不等于 verification；
12. 自动修复当前默认关闭。

如果一个设计必须破坏其中某条，先改架构文档并进行单独安全评审，而不是直接写代码。

---

## 3. 新增 READ Tool 的标准流程

假设要新增：

```text
txboard_example_read
```

### Step 1 — 明确 output contract

先定义结构化输出：

```json
{
  "target": {},
  "facts": {},
  "warnings": []
}
```

不要把 DB Model 整体直接返回。

### Step 2 — Ability

检查是否已有适合 ability。

优先复用：

```text
agent:system:read
agent:nodes:read
agent:metrics:read
agent:traffic:read
agent:audit:read
agent:insights:read
```

只有语义明显不同才增加新的 ability。

文件：

```text
api/app/Services/AgentOps/AgentAbility.php
```

### Step 3 — Domain service

优先放入：

```text
AgentOpsService
```

如果是多事实组合/推导，放：

```text
AgentInsightService
```

禁止把业务逻辑写在 `mcp/src/index.ts`。

### Step 4 — Agent Controller / Route

更新：

```text
api/app/Http/Controllers/V2/Agent/AgentOpsController.php
api/app/Http/Routes/V2/AgentRoute.php
```

要求：

- validate input；
- ability check；
- node-scoped 请求执行 target check；
- stable success/failure envelope。

### Step 5 — MCP

更新：

```text
mcp/src/index.ts
```

MCP 只做：

```text
schema
-> HTTP request
-> structured result
```

### Step 6 — Test

至少覆盖：

- success；
- missing ability -> 403；
- target scope -> 403 / filtered result；
- malformed input -> 422；
- secret 不泄露。

### Step 7 — Contract

更新：

```text
contracts/http/agent-ops-v1.md
mcp/README.md
```

---

## 4. 新增 Node OPERATE Action 的标准流程

这是风险最高、最容易漏层的一类。

假设未来要新增一个**假想示例**：

```text
ops.service.restart
```

它当前并未实现，本节只用来说明流程。

### Step 1 — 先定义 Node protocol

先写 contract，再写 handler：

```text
operation
input
output
timeout
error_code
mutation
idempotency
verification signal
minimum node capability
```

更新：

```text
contracts/node-protocol/agent-ops-v1.md
```

如果协议语义不向后兼容，应升级协议版本，而不是偷偷改变 v1。

### Step 2 — TXBoard action definition

更新：

```text
AgentActionService::DEFINITIONS
```

必须指定：

- ability；
- risk；
- event。

### Step 3 — TXBoard input validation

在发送到 Node 之前做第一层限制：

- enum；
- number range；
- max length；
- allow-list；
- destination policy。

不要依赖 TX-Node 单独兜底。

### Step 4 — approval policy

默认：

```text
pending
```

Agent API 不允许创建：

```text
approved
running
succeeded
```

### Step 5 — dispatch

继续复用：

```text
NodeSyncService
-> Redis node:push
-> NodeWorker
-> TX-Node
```

不要新增旁路 SSH。

### Step 6 — TX-Node receive

通常会涉及：

```text
internal/panel/ws.go
internal/controlplane/types.go
internal/controlplane/panel.go
internal/controlplane/mailbox.go
internal/machine/machine.go
internal/service/service.go
```

注意：

- 单 Node 模式；
- machine-mode multiplex；
- request ID；
- local validation；
- bounded execution；
- unsupported operation。

### Step 7 — result

统一回：

```text
ops.result
```

必须包含：

- request_id；
- operation；
- ok；
- result 或 error_code/message。

### Step 8 — replay

同一个 request ID：

```text
MUST NOT repeat a non-idempotent action
```

必须返回 cached result 或明确 previous-state 语义。

### Step 9 — verification

在 TXBoard 定义 observable verification。

例：

```text
restart kernel
-> websocket connected
-> kernel_running=true
```

如果没有可靠 verification signal：

```text
verification_status=inconclusive
```

不要伪造 `passed`。

### Step 10 — MCP

MCP tool 调用的仍然是：

```text
POST /agent/nodes/{id}/actions
```

返回 pending action，不直接等待 Node mutation 完成。

---

## 5. 新增 Insight 的标准流程

Insight 不应该读取更多权限，只应该组合调用者本来可见的事实。

### 推荐结构

```text
facts
-> deterministic classification
-> reason_code
-> suggested next tool
```

示例：

```json
{
  "reason_code": "kernel_not_running",
  "priority": "critical",
  "suggested_tool": "txboard_restart_kernel",
  "approval_required": true,
  "automatic_execution": false
}
```

要求：

- reason code 稳定；
- 不虚构 root cause；
- 建议和事实分离；
- Agent 能知道建议是否需要 approval；
- Insight 不能自行调用 mutation。

---

## 6. Target Scope 规则

每个 node-scoped endpoint 都要问：

> 这个 Token 是否允许看到这个 node？

当前两层：

```text
agent:target:node:<id>
agent:target:machine:<id>
```

Machine scope 动态包含该 machine 当前所属 Node。

列表类 endpoint：

```text
filter
```

单对象 endpoint：

```text
assert
```

不要返回资源后再让 MCP 自己过滤。

---

## 7. 错误语义

保持 Agent 可理解。

### 403

权限问题：

- missing ability；
- target scope denied。

### 404

目标不存在：

- node/action not found。

### 422

请求/策略不满足：

- unsupported action；
- invalid input；
- cooldown；
- pending queue full；
- disallowed target。

### Node result error_code

Node 执行失败应使用稳定 code：

```text
config_invalid
kernel_restart_failed
invalid_target
invalid_port
dns_lookup_failed
port_check_failed
log_tail_failed
unsupported_operation
```

Agent 应优先按 error_code 分支，而不是匹配自由文本。

---

## 8. Audit 要求

新增 Agent endpoint 时检查：

- request ID；
- client；
- protocol；
- tool；
- target；
- risk；
- redacted input；
- result status；
- error code；
- started / finished。

任何可能出现：

```text
token
password
secret
uuid
private_key
api_key
credential
authorization
```

的字段都不能原样进入 audit。

---

## 9. 安全输入设计

### Host / network

允许：

- node 自身 host；
- deployment allow-list；
- fixed port range。

禁止：

- 任意 URL；
- URL scheme；
- 任意 internal metadata endpoint；
- generic HTTP client。

### Logs

允许：

```text
source=application
lines=N
```

禁止：

```text
path=/...
glob=...
command=journalctl ...
```

### Process / service

未来如果新增 service 操作：

使用固定 enum：

```text
service=proxy-kernel
```

不要接受：

```text
process_name
binary_path
shell_command
```

---

## 10. Test Matrix

### Agent API

至少：

- auth；
- ability；
- target scope；
- success；
- invalid input；
- policy rejection；
- audit。

### Agent Action

至少：

- created as pending；
- no implicit approval；
- duplicate pending；
- cooldown；
- pending limit；
- action result；
- timeout；
- verification。

### TX-Node

至少：

- event parse；
- missing request ID；
- machine mailbox order；
- local input validation；
- operation success/failure；
- replay；
- redaction / bounds（若涉及日志）。

### MCP

至少：

- TypeScript typecheck；
- build；
- schema 与 HTTP contract 对齐。

### Admin

涉及页面时：

- TS compile；
- existing web tests；
- build。

---

## 11. Definition of Done

一个新的 Agent Ops capability 只有满足以下条件才算完成。

### Contract

- [ ] HTTP contract 更新；
- [ ] Node protocol 更新（若需要）；
- [ ] MCP tool 文档更新。

### Security

- [ ] risk class 明确；
- [ ] ability 明确；
- [ ] target scope 明确；
- [ ] input bounded；
- [ ] secrets redacted；
- [ ] 无 generic shell / path / URL。

### Runtime

- [ ] timeout 明确；
- [ ] retry/replay 明确；
- [ ] error codes 明确；
- [ ] partial failure 明确；
- [ ] verification 明确。

### UX

- [ ] Agent output 结构化；
- [ ] Admin approval 路径存在（若 mutation）；
- [ ] pending / result 状态可查看。

### Test / CI

- [ ] API CI；
- [ ] Web CI（如涉及）；
- [ ] MCP CI（如涉及）；
- [ ] TXBoard image smoke（运行时改动）；
- [ ] TX-Node CI（Node action）。

### Docs

- [ ] architecture/status 若边界变化则同步；
- [ ] 架构/契约文档按实际行为同步更新；
- [ ] known limitation 有变化则记录。

---

## 12. 推荐 PR 拆分

不要一次把一个大 Agent feature 横跨所有层但没有中间验证点。

推荐：

### PR A — Contract / read path

- contract；
- ability；
- read service；
- tests。

### PR B — Node execution

- typed protocol；
- TX-Node；
- result；
- replay；
- tests。

### PR C — approval / MCP / Admin

- action lifecycle；
- MCP；
- UI；
- integration tests。

### PR D — hardening

- scopes；
- cooldown；
- limits；
- redaction；
- edge cases。

复杂度较小时可以合并，但审查时仍按这些逻辑块检查。

---

## 13. 开发复盘模板

完成一个较大的 Agent Ops milestone 后，在 PR 或 progress doc 记录：

```markdown
### Milestone

目标：

实现：

未实现 / 有意延后：

安全边界：

兼容性：

关键测试：

CI：

发现的问题：

最终设计决策：

下一步：
```

重点记录**为什么**，而不只是“改了哪些文件”。

---
