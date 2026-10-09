# TXBoard 本体大版本收尾清单（CURRENT / NEXT）

> 2026-10-09。只覆盖 TXBoard 主仓 Laravel、Vue User、React Admin、内置运行时和数据库。不是新版本发布声明，不包含外部服务适配。

## 范围决策

- 唯一新增正式业务 API 根路径为 /txapi。TXBoard 是唯一权威控制面，资金、权限与流量业务不下沉到独立 Gateway。
- 本体先完成：功能、认证、交易、账户、前后端、管理、数据库、内置运行时和 CI。TX-Node、Gateway、真实支付商、外部插件/主题适配一律后置，不阻塞下一轮本体编码。
- 必须保留实际仍承担协议职责的订阅配置 URL、原支付回调、Telegram Bot webhook、旧节点/Agent/管理入口，除非已在本体独立证明对应新链路等价且明确切换策略。外部消费者须自行升级，不阻止已无本体依赖的普通旧用户路由退役。
- 代码已合并、CI 通过、部署预发验证、外部服务联调与生产对账是不同等级，不可混淆。

## 2026-10-09 已核实进度（非发布声明）

- TXAPI User Native、套餐/订单主流程、签名支付回调和流量账本均已有实现；[PR #159](https://github.com/ANRCM0/TXBoard/pull/159) 新增用户本人钱包充值页面、独立流水和签名金额验证，不是管理员代充。
- React Admin 已迁移：审计、套餐（含写端）、订单主要操作、用户主要操作、工单、公告知识库、流量重置（[PR #160](https://github.com/ANRCM0/TXBoard/pull/160)）。支付方式**删除**也已在 [PR #161](https://github.com/ANRCM0/TXBoard/pull/161) 使用新接口且同时保护旧 V2。
- 仍在使用 V2 的后台业务至少包括：系统配置及其敏感项、礼品卡、节点机器、插件和主题、Mail、Agent、队列及统计。订单指派/佣金复核和用户群发仍是 V2。勿用“核心页面已迁移”替代“全部后台已原生化”。
- 本轮新增：优惠券列表、单张创建/编辑、启停、批量生成 CSV 均迁入独立管理 TXAPI；对所有带订单历史的优惠券保留保护，旧 V2 不得绕过；CSV 明确容量限制与表格公式注入转义。代码须经 CI 验证后才能合并，不等于在线运营验收。\n- 本轮新增：队列监控快照、失败任务列表与脱敏详情接入管理员专属 TXAPI；复用 `QueueMonitorService` 与共享失败任务脱敏格式器，旧 V2 的脱敏契约保留，且不提供队列重试或任务执行入口。新增 PHP/React 合同测试仍以 CI 为验收依据。
- 本轮新增：支付渠道的后台列表、插件动态表单、创建、编辑、启停、排序已接入受动态管理员路径保护的 TXAPI；支付渠道删除沿用原生受交易历史保护的实现。保留原 V2 给未完成迁移的受支持客户端，支付通知地址继续依配置指向 legacy 或 native 回调，禁止提前删除旧支付回调。新增的 PHP/React 合同测试须经 CI 核验后才能合并，不代表真实商户对账完成。\n- 新增可重复的 CI 证据：`node scripts/native-admin-release-audit.mjs --guard-native --output artifacts/release/admin-native-gap.json` 会生成 React Admin **直接 apiClient 调用点**缺口，并拒绝 `content`/`ticket`/`traffic-reset`/`payment`/`queueMonitor`/`coupon` 已完成模块回退 V2；`--strict` 是人工发布前的检查项，**现在预计不能通过**。
- 这个静态扫描不等于 Laravel 全路由/插件/Worker/外部调用图，也不是漏洞扫描；必须与 `scripts/p0-api-audit.mjs`、功能合同测试和业务服务真实引用审计合并看待。
- 本轮已知未验收：真实 1Panel/OpenResty CIDR 与备份恢复、MySQL 多实例实际并发、Redis/Horizon/Octane 长时间运行、支付商沙盒及对账、独立 TX-Node/Gateway 协议联调。任何一项缺失都不能称为“生产稳定版已发布”。

## 已交付与剩余

| 领域 | 当前本体代码 | 仍需验收 |
| --- | --- | --- |
| P1/P2 用户端 | Native TXAPI，认证/套餐/内容/工单和钱包充值前台 | 发布部署及旧桥接移除前的完整回归 |
| P3 交易 | 共享订单/支付状态机、独立钱包充值账本、重复回调防护、渠道历史留存保护 | 真实商户签名对账、退款/冲正机制和线上 MySQL 并发 |
| P4 Node/Traffic | Native HTTP/WSS 服务端、共享 SQL 账本、后台重置并发保护 | 节点管理原生化、真实 MySQL 上报/重置竞争及外部 TX-Node 后置 |
| React Admin 核心 | 审计/套餐/订单主要操作/用户主要操作/工单/公告知识库/流量重置 | config、gift-card、plugin、theme、server、Agent、mail、统计等部分 V2 |
| Legacy Batch 1–6 | 大部分 Vue 用户端已迁至 TXAPI，逐批移除闲置旧用户路由 | 按调用图和自动缺口清单逐条判定 V2/V1 退役 |
| Runtime security | Caddy 可信代理、审计脱敏、管理员路径/角色隔离 | 真实反代 CIDR、备份恢复和持久化队列回归 |

## C1. TXBoard 内部引用收口

1. 对每条 /api/v1 路由记录 Vue、Laravel 测试、P0 脚本和运行时的内部消费者。
2. 合成购买、支付、流量流程以及性能读取基线全部采用原生 /txapi；保留真实账本断言。
3. 主题/config/email/knowledge 通过共享服务验证语义后逐项移除旧路由和 DTO；每删除一项，都须同时新增未注册断言。
4. 不把外部客户端的兼容要求当成无限期保留废弃 User V1 路由的理由；不能误删尚在履行订阅/支付/节点协议职责的入口。

## C2. React Admin 原生化

1. 列出所有 React Admin 的 V2 调用和后台权限映射，按认证、配置、内容、用户、套餐、订单、机器节点、插件主题、运营与 Agent 分批。
2. 原生 /txapi/admin/{secure_path} 必须保持动态安全路径、Sanctum、RBAC、审计、插件 Hook；禁止将普通用户 /txapi/me 的身份作为管理员授权。
3. 先实现共享业务 Domain/Service 与严格 DTO，再切换 React Admin client，完成 PHP/TypeScript 与跨角色回归；按内部实际依赖删除不再使用的管理 V2 注册。

## C3. 无损清理与优化

1. 静态调用图确认 Controller/Request/Resource/Adapter 不再被任何本体路径使用后才删除；保留被插件、Worker、回调和订阅复用的服务。
2. 对实际 N+1、无界列表和敏感字段做定量优化，保留事务/金额/流量一致性；没有性能数据不编造收益。
3. DB 优先索引和 expand-backfill-verify-contract，不为去掉 v2_ 前缀而大规模改表名。破坏性迁移必须有独立恢复演练。

## C4. 内核稳定验收

- 功能：Vue/React、身份和会话、套餐、订单支付、钱包/返佣/提现、礼品卡、工单、配置、机器/节点、流量、插件主题。
- 正确性：SQLite/MySQL、资金/优惠券/流量幂等、重复请求/并发、角色隔离、数据脱敏、系统升级回退。
- 运行时：镜像内 Caddy 配置验证、Octane、Horizon、Redis、备份/恢复与 1Panel/OpenResty 可信代理链。
- 性能：同等数据规模下的 SQL 次数、p50/p95/p99、DB 锁等待、内存、队列 lag；CI 合成耗时不能宣称为线上指标。
- 完成状态：每个条目都要关联 commit、CI 和实际实证。缺少真实环境时明确 deferred。

## 后置（内核冻结以后）

分别开展 TX-Node Native 协议联调、TXBoard-Gateway BFF 路由适配、真实支付提供方沙箱及生产对账、外部主题插件/SDK 迁移和正式灰度。它们不阻塞 C1–C3 的 TXBoard 主仓编码，但在未完成前不能宣布全生态/生产最终验收通过。
