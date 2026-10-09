# TXBoard 原生网络管理 · 第二批：Node Admin

> 2026-10-09 — 开发阶段。新管理 API 仅支持 `/txapi`，不保留旧 `/api/v2/{admin_path}/server/manage/*` 适配；TX-Node 后续自行适配正式 Node Wire Contract。此文档不代表生产环境或 TX-Node 联调验收。

## 原生管理员协议

所有操作均在动态 `/txapi/admin/{admin_path}` 下，必须 Sanctum 管理员身份且遵循现有审计：

| 方法 | 子路径 | 说明 |
|---|---|---|
| GET | network-nodes | 查询，页大小 1–100，稳定按 sort/id 排序；返回 data + meta |
| GET | network-nodes/protocols | 使用唯一 `ProtocolRegistry` 的元数据；无第二套定义 |
| POST | network-nodes/secrets | `kind=x25519/hex/ech`，敏感 key material 不缓存、不入日志；限流 |
| POST | network-nodes | 使用扩展 `ServerSave` 规则创建 |
| PUT | network-nodes/{id} | 完整节点保存和父节点环检测 |
| PATCH | network-nodes/{id} | 仅 show、enabled、machine_id 白名单 |
| POST | network-nodes/{id}/copy | 复制协议设置，重置流量/可见性/code，返回新 ID |
| DELETE | network-nodes/{id} | 拒绝删除仍有子节点的记录 |
| PUT | network-nodes/sort | 1–100 条；所有 ID 存在才在单事务中写入 |
| PATCH | network-nodes/batch | 最多 100 条；无半成功的批量更新 |
| POST | network-nodes/batch-delete | 最多 100 条，子节点保护；逐 Model 删除以触发 observer |
| POST | network-nodes/{id}/traffic-reset | 重置上下行计数 |
| POST | network-nodes/batch-traffic-reset | 有限批量、原子重置 |

- Node 管理端读取对外只投影 UI 必需字段、归属组及父节点；单页集中查询组与父节点，避免按行重复查组；管理端自动读完最多 100 页（超出显式报错）。
- 机器 ID、父节点 ID、组和路由 ID 必须存在；父节点禁止构造环，批量操作拒绝重复 ID、未知 ID，更新明确使用 Eloquent Model 以保留 `ServerObserver` 的机器变更同步。
- 新增 `NodeSecretGenerator` 纯服务，复用现行密钥算法，不重复实现协议注册器，不依赖遗留控制器。
- 官方 React Admin 节点相关调用已迁到 `server-nodes.ts`；原管理路由注册和 `V2/Admin/Server/ManageController` 删除，不提供兼容 HTTP 响应；机器管理仍走 V2，留到第三批。
- 该改造尚未要求 TX-Node 实现与此 Admin REST 相同的地址：TX-Node Wire protocol 与后台 Admin REST 是独立协议。

## 未完成的发布门槛

生产 Node/机器运转验证、真实 TX-Node 适配、数据迁移/备份恢复、部署回滚、安全审计仍须在正式版本前单独验收。此批 CI 只能证明自动化条件，不是生产验收。
