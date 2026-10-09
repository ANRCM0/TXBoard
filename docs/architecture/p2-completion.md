# P2 原生化开发阶段验收记录

> 2026-10-09 · 状态：**P2 代码工作包已交付并合并 main**；不代表真实生产/第三方环境已经验收。
>
> 本记录区别「TXBoard 代码合约与自动回归通过」和「可删除旧接口/线上迁移已完成」。

## 合并的交付工作包

| 工作包 | 合并 PR | 可验证成果 |
|---|---|---|
| P2-A 原生套餐域 | [#116](https://github.com/ANRCM0/TXBoard/pull/116) | PlanCatalog；有限容量一次 group-by；用户续费访问隔离 |
| P2-B 套餐页面 | [#117](https://github.com/ANRCM0/TXBoard/pull/117) | Vue 套餐列表/详情使用 native DTO + 唯一页面适配器 |
| P2-C 账户/公告/知识库 | [#118](https://github.com/ANRCM0/TXBoard/pull/118) | 安全个人信息白名单；公告数据库分页；知识内容用户权限和正文插值 |
| P2-D 工单读写 | [#119](https://github.com/ANRCM0/TXBoard/pull/119) | 用户隔离、数据库分页、创建/回复/关闭、行锁及重复关闭 |
| P2-E 认证和会话 | [#120](https://github.com/ANRCM0/TXBoard/pull/120) | 统一登录/注册；令牌签发、注销、会话白名单/撤销、改密码 |

## 已实施的 TXAPI（Laravel）

- 匿名：GET /txapi/health、GET /txapi/public/config、GET /txapi/plans；POST /txapi/auth/login、POST /txapi/auth/register。
- 认证用户：GET /txapi/me、GET /txapi/plans/{planId}、GET /txapi/orders、GET /txapi/orders/{tradeNo}。
- 内容：GET /txapi/notices、GET /txapi/knowledge、GET /txapi/knowledge/categories、GET /txapi/knowledge/{articleId}。
- 工单：GET/POST /txapi/tickets、GET /txapi/tickets/{ticketId}、POST /txapi/tickets/{ticketId}/messages、POST /txapi/tickets/{ticketId}/close。
- 认证安全：POST /txapi/auth/logout、GET /txapi/auth/sessions、DELETE /txapi/auth/sessions/{sessionId}、POST /txapi/auth/password。

响应使用 data、可选 meta、服务端生成的 request_id / X-Request-Id；错误按标准 HTTP 状态与安全 code 返回，不暴露任意异常文字。客户端对原生 DTO 做明确的日期/金额/周期转换，不能通过旧接口静默填充新接口缺失字段。

## 通过的自动化门禁

- API SQLite/PHP 全量回归、MySQL 8.4 schema 与 Feature/Contract 回归。
- Vue / React Admin 类型检查、Vitest 与构建，Docker/Octane、MCP、发布门禁。
- 关键对抗项：无权限访问他人工单、未登录内容访问、隐藏知识与过期订阅屏蔽、套餐容量批量 SQL 统计、旧令牌撤销、密码更新取消其他会话、Token/管理员安全路径不进入原生登录响应。
- P0 合成订单→支付→生效→流量、财务与节点回归保持通过。该合成测试不代表真实交易或外部 Node 互通。

## 明确保留的旧接口与后续阶段

- 支付/结算、钱包/优惠券、订阅安全重置、实际节点流量协议与管理员动态 secure_path 仍使用原路径，由 P3/P4/P5/P7 按领域迁移。
- 特殊认证：邮件登录链接、验证码邮件发送、临时令牌链接、Telegram 登录、忘记密码、快速登录链接暂按原 V1 服务工作；该兼容不意味着可以移除这些能力，也不等于所有认证路径都已逐一迁移。
- Node、Gateway、外部插件/主题和真实支付沙箱等跨项目适配按维护者决策后置。旧路由在完成消费者迁移、真实验收与回退证据前**不得删除**。
- P2 无新增数据库表迁移。回滚 native 客户端和控制器代码不应改写已签发的 Sanctum Token 或已持久化的工单；这些状态必须照常留在原数据库。

## 进入 P3 的开发条件

P2 核心代码、客户端接入、自动化回归的交付条件已满足。允许开始 P3-A（订单/余额/优惠/返佣/并发与对账）。P3-B 的真实支付回调双路径、P7 的旧协议退役和线上验收仍需独立证据；本记录没有豁免。
