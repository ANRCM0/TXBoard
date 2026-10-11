# 订单、钱包与支付操作

> 本文只说明管理与验证顺序。金额字段、API 端点、订单状态和回调签名规则以当前 [TXAPI 用户协议](../../contracts/http/theme-integration-current.md) 和服务器端实现为准。

## 业务验证顺序

1. 配置测试套餐、测试支付方式和测试用户，并区分订单支付、钱包充值、余额与订阅开通等不同流程。
2. 确认客户端只能发起允许的订单操作；**客户端返回页面或 checkout 结果不代表付款成功**，支付状态必须由可信服务端业务流或签名回调更新。
3. 分别测试成功、失败、取消、重复提交、重复回调和网络重试；每条资金写入必须符合幂等和审计约束。
4. 对照商户沙盒流水核实支付金额、订单、余额及状态，不使用生产支付账户测试故障注入。
5. 真实上线前按 [发布前验收 Runbook](../operations/release-staging-acceptance.md) 备份并核对恢复数据。

## 开发者提醒

- API 金额以 `*_minor` 最小货币单位整数为准，流量以 `*_bytes` 为准，不要在客户端自行猜测缩放比例。
- API 日期字段并不一定采用同一时间格式；按各自字段契约解析。
- 订单写入、充值与支付回调涉及不同认证边界；不能从普通用户前端伪造支付成功。
- 外部 Gateway **不是** Laravel 资金与订单状态的权威存储，不能绕开原有服务端权限。

[当前用户 TXAPI 契约](../../contracts/http/theme-integration-current.md) · [接口身份边界](../../contracts/http/external-adapter-current.md) · [上线验收](../operations/release-staging-acceptance.md)。