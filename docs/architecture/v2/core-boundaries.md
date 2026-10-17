# Core 业务与信任边界

## 唯一业务权威

Core 基于 Laravel 构建模块化控制平面。API、Worker、Scheduler 共用领域规则与 SQL 权威；Gateway 负责访问适配，Provider 管理代理秘密，Node 执行有限授权及计量。

- Identity/RBAC 拥有账户、会话、权限、资源归属和审计。
- Subscription 拥有不可变 Revision/Offer、购买证据、订阅、Terms、生命周期及配额。
- Billing 拥有订单、支付尝试、收款事实、资金分录、钱包、退款和履约一致性。支付插件只验证/映射具体渠道协议；退款仅到钱包，不反转权益或履约 work。
- Network/Usage 拥有 Node/Machine/Group/Route、Subject、授权政策、持久接收、预留及原周期结算。
- 安装记录、Schema/epoch 和 readiness 属于 Core；主机安装/更新/恢复由 TXBoard-Deploy 编排。
- Module Registry 只读；生命周期变更委派 Plugin/Theme 专有运行时，避免第二套运行状态。

详细支付及扩展责任见[内核与插件责任矩阵](core-extension-boundaries.md)；运行角色及源码入口见[架构总览](README.md)。旧 User 套餐、流量、UUID 和余额字段不作为 native 权威。

## 信任与事务

1. User、Admin、Node/Machine、服务客户端和支付回调使用独立凭据及授权链。动态安全路径、服务 mTLS 或 capability 声明都不等于用户授权。Gateway 不直读写 Core MySQL 或发布 Core Redis。
2. Core 逐次校验 actor、scope、归属和当前状态。管理员使用受验证的动态路径、Admin Bearer、RBAC 和审计；服务端 Admin 客户端不表示浏览器管理入口已开放。
3. Subject 是非秘密归属与计量标识，不是代理密码。代理 UUID、私钥、可直接认证的配置仅由 Provider 向授权接收方交付，不进入 Core DTO/SQL/日志/管理清单。Core 自身会话、服务材料及链接摘要按各自契约保护。
4. Provider/支付网络调用在业务 SQL 事务外执行。Core 持久登记意图、最终重验、提交回执及补偿；未知结果查询原操作，不换键重新发行、收款或履约。
5. Node 验证签名目标、当前信任、高水位及有限期限；过期停止新准入和已有受控会话。原 Session/批次/Period 持久保存，接收或隔离不等于已结算。
6. 权益、订单和账务只按当前领域契约原子提交。SQL outbox/接收记录负责持久恢复，Redis 及 HTTP 成功响应不替代业务事实。
7. PHP 插件是可信宿主进程内代码，**不是安全沙箱**。不得将架构约定描述为已禁止插件访问宿主数据库或秘密。

## 开发边界

只维护当前 native 契约和实际消费者；不为旧版本/旧插件建立新兼容层。可丢弃开发库重建与不可丢弃数据的处理规则见[开发基线](../../development/native-development-baseline.md)。未实现能力明确拒绝，不以取消兼容放宽身份、资金、持久授权及恢复要求。

新增前端属于延期的独立 TX-F；Agent/MCP 已退役。当前能力和未验收限制以[实现基线](current-progress.md)和各[版本化契约](../../../contracts/v2/README.md)为准，不由历史阶段名称或目录存在推断。
