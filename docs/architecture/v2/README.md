# Core 当前架构

Core 是账户、订阅、订单、资金、权限与用量的唯一持久业务权威。它对兼容/访问适配层提供明确契约，插件通过统一框架调用，不能另建业务真相。项目处于开发阶段，只维护当前 native 生产者和消费者，不承诺旧版本或旧插件兼容。

## 运行角色

| 组件 | 所有权与职责 | 不具备的权威 |
| --- | --- | --- |
| Core API / Worker / Scheduler | 同一领域代码、SQL 事务、安装 admission/epoch、履约、回执和 outbox 恢复 | 环境变量、Redis 通知或进程缓存不能替代持久业务事实 |
| Node Control | Core 内 HTTP；独立 Workerman WSS；Node/Machine 专用身份、完整授权和刷新提示 | WSS 提示不签发用户权威，HTTP 路由存在不证明 WSS 在线 |
| Credential Provider | 独立进程、私有存储与 mTLS 身份，管理代理秘密，直接交付授权接收方 | 不决定订阅、配额、计费归属；Core 不接收代理秘密 |
| TX-Node | 代理内核、有限授权、计量、原批次持久重报 | 不直连 SQL、不自行换用量周期、不把接收当作结算 |
| Gateway | 可选用户 BFF、SDK、受限服务客户端及 CLI | 不持有业务数据库；服务认证不替代 User/Admin 授权，无管理员浏览器入口 |
| Module / Plugin / Theme | Registry 只读库存；Lifecycle 委派专有运行时；支付插件处理渠道协议 | 插件不写订单/钱包/权益权威；PHP 插件是可信进程内代码，非沙箱 |
| TXBoard-Deploy / TX-F | 独立安装更新运维；独立后续前端 | 代码合仓不合并凭据、构建、发布权限；新前端仍延期 |

SQL 持久保存业务事实、用量接收/预留/结算和 outbox。Redis 只承担队列、缓存和通知。Provider 失联不延长 Node 授权；重启、轮换及新周期不能清除原周期未决批次或额度。

## 领域入口

| 所有者 | 源码范围与责任 |
| --- | --- |
| Identity | 账户、认证/会话、管理员保护与账户状态 |
| Subscription | 不可变目录、购买协调、订阅/Terms/生命周期、共同权益、非秘密凭据及分发协调；[领域说明](../../../api/app/Domains/Subscription/README.md) |
| Billing | native 订单状态机、支付尝试/收款事实、结算、账本/钱包、退款及升级现金来源预算 |
| CredentialProvider | Core 的 mTLS 协议适配与非秘密回执；实际秘密留在独立 Provider |
| Network / UsageV2 | Node/Machine/目标身份、授权签名；原 Session/Period 接收、预留与结算 |
| Installation / Support | 持久安装、Schema/epoch/readiness；工单及其事务与授权 |

## 必要资料

- [实现基线与限制](current-progress.md)：已合并能力、精确提交的验证范围及未验收边界。
- [Core 边界](core-boundaries.md)、[内核与插件责任矩阵](core-extension-boundaries.md)：身份、秘密、资金与恢复所有权。
- [当前契约](../../../contracts/v2/README.md)、[HTTP 身份边界](../../../contracts/http/external-adapter-current.md)、[Node 协议](../../../contracts/node-protocol/README.md)。
- [Native 配置与运行](native-c2-operations.md)、[开发及数据安全规则](../../development/native-development-baseline.md)、[发布验收](../../operations/release-staging-acceptance.md)。

## 架构设计草案

[小内核与业务模块详细设计](core-kernel-modular-design.md)及[进程内能力契约草案](../../../contracts/v2/core-module-ports-v1.md)描述如何在当前 native 实现上收口依赖与事务边界，状态为 Target / Draft。业务模块仍属于 Core，不变成可热卸载插件；适配层不恢复旧版本兼容。设计文件不表示接口、目录调整或多租户已经实现，不改变本页当前运行能力。

当前角色不等于已验证 Compact/Standard/Cluster 部署产品；HA、完整平台/协议与容量承诺没有完成验收。旧计划、阶段台账和重复设计从当前文档移除；原文可从 Git 历史追溯，不再以历史方案驱动新开发。
