# TXBoard 本体大版本收尾清单（CURRENT / NEXT）

> 2026-10-09。只覆盖 TXBoard 主仓 Laravel、Vue User、React Admin、内置运行时和数据库。不是新版本发布声明，不包含外部服务适配。

## 范围决策

- 唯一新增正式业务 API 根路径为 /txapi。TXBoard 是唯一权威控制面，资金、权限与流量业务不下沉到独立 Gateway。
- 本体先完成：功能、认证、交易、账户、前后端、管理、数据库、内置运行时和 CI。TX-Node、Gateway、真实支付商、外部插件/主题适配一律后置，不阻塞下一轮本体编码。
- 必须保留实际仍承担协议职责的订阅配置 URL、原支付回调、Telegram Bot webhook、旧节点/Agent/管理入口，除非已在本体独立证明对应新链路等价且明确切换策略。外部消费者须自行升级，不阻止已无本体依赖的普通旧用户路由退役。
- 代码已合并、CI 通过、部署预发验证、外部服务联调与生产对账是不同等级，不可混淆。

## 已交付与剩余

| 领域 | 当前本体代码 | 仍需验收 |
| --- | --- | --- |
| P1/P2 用户端 | Native TXAPI、认证/套餐/内容/工单 | 旧桥接与 React Admin |
| P3 交易 | 共享支付状态机/订单服务/财务审计 | 并发、对账及后置真实商户 |
| P4 Node/Traffic | Native HTTP/WSS 服务端和共享 SQL 账本 | 外部 TX-Node 双端后置 |
| Legacy Batch 1–5 | 大部分 Vue 迁入 TXAPI | 旧路由/Controller/Test 进一步清理 |
| Runtime security | 收紧 Caddy 可信代理；令牌分域 | 真实反代 CIDR/部署回归 |

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
