# P3 Billing 开发交付与验收边界

> 2026-10-09 · **P3 核心代码和自动化工作包已实现**，不等于真实支付提供方沙箱或正式资金对账已验收。

## 已提交的工作包

| 包 | PR | 内容 |
|---|---|---|
| P3-A1 | [#122](https://github.com/ANRCM0/TXBoard/pull/122) | 取消订单退余额与有限券名额一次；锁内 callback 号复核；金额折扣不负 |
| P3-A2 | [#123](https://github.com/ANRCM0/TXBoard/pull/123) | 原生订单创建/取消、钱包余额、返佣分页、优惠券查询、支付方式白名单及 Vue 适配 |
| P3-B | [#124](https://github.com/ANRCM0/TXBoard/pull/124) | 新旧支付 webhook 共享验签、金额与支付方式核验，原始 provider ACK；新切流默认关闭 |
| P3-A3 | [#125](https://github.com/ANRCM0/TXBoard/pull/125) | 新旧 checkout 统一使用行锁、手续费与支付插件执行，Vue 支付发起改为原生 |
| P3-A4 | [#126](https://github.com/ANRCM0/TXBoard/pull/126) | 只读聚合财务一致性扫描与 MySQL CI 报告工件 |

## 现行 API 与兼容

- 认证用户：POST `/txapi/orders`、`/txapi/orders/{tradeNo}/cancel`、`/txapi/orders/{tradeNo}/checkout`；GET `/txapi/billing/wallet`、`/txapi/billing/commissions`、`/txapi/billing/payment-methods`；POST `/txapi/billing/coupons/check`。
- 匿名支付通知：GET/POST `/txapi/payment/webhook/{method}/{uuid}`，返回支付商原始确认字符串（不是普通 JSON envelope），与 V1 共享同一服务。
- 老 V1 订单/checkout/webhook 路由原样保留。付款通知的出站 URL 默认还是 `/api/v1/guest/payment/notify/...`，仅通过显式 `TXBOARD_NATIVE_PAYMENT_WEBHOOK=true` 才改为新路径。

## 已有自动化证据

- SQLite API、MySQL 8.4 集成、P0 合成下单→支付→履约→流量回归、Vue/React 类型及构建、Docker/Octane/MCP 门禁。
- 金额与优惠：负金额、超限折扣、余额抵扣/取消一次返还、优惠券名额归还、同一回调重放不重复排队；错误签名、少付、错误支付方式拒收。
- 只读命令：`php scripts/p3-billing-audit.php --output=artifacts/p3-billing-audit.json`；输出异常计数，不含邮箱、Token、交易号、支付配置。

## 仍需独立真实环境证据

- P3-B 真实支付商沙箱与反向代理通知 URL 切换仍为 **deferred**，不能因为内部签名模拟通过就宣布支付渠道线上兼容。
- 只读异常扫描不是支付商对账单或银行流水对账；完整收入、退款、返佣与资金结算差额为零仍需外部结算数据和演练。
- 真正跨进程高并发和死锁压力测验尚未完成；MySQL 回归证明的是当前代码和合成请求，不是生产负载。
- 返佣提现/转余额、礼品卡、充值/退款与管理端部分特殊账务继续保留旧实现，直到后续跨消费者验收；不能提前删除旧接口。
- 没有做破坏性表迁移；代码可回滚，但已经真实付款的资金状态不能通过 Git 回退，必须以真实对账/补偿流程处理。

**结论**：P3 用户交易主路径及内部自动化代码工作包已交付，可继续 P4；真实第三方支付验收、全面结算对账及旧接口退役仍未通过。
