# Release Safety Gate — 支付方式不能删除历史账务所引用的渠道

范围：TXBoard 主仓内置管理端。保留已有订单和钱包充值的支付插件签名处理，**不做外部支付商接入/联调**。

## 发现的问题
原 `/api/v2/{secure_path}/payment/drop` 可以直接物理删除 `v2_payment` 记录，即使它被 `v2_order.payment_id` 或 `v2_wallet_recharge.payment_id` 引用。失去原渠道 UUID/配置后，迟到的支付通知可能无法验签，也会失去对账依据。

## 处置
- 共享 `AdminPaymentSafety::deleteUnused`：行锁事务内检查 **所有状态** 的订单与充值流水，任何引用均拒绝物理删除并返回 409。历史 completed/cancelled 仍保留渠道身份作为审计依据。
- 同时修复旧 V2 删除入口和新增 `POST /txapi/admin/{admin_path}/payment-methods/{id}/delete`，避免旧入口绕过保护。React Admin 的删除动作转为调用 native API。
- 管理员如需停用有历史账务的支付方式，可以使用现有 enable 开关；停用并不删除原配置。不要在存在迟到回调期间直接轮换旧密钥，配置变更另需独立审计。
- PHP/TS 测试涵盖角色校验、安全路径、订单历史、钱包充值历史、无引用的安全删除和重复删除。
- 不宣称数据库层 FK 已全覆盖，真实并发订购与删除仍需 MySQL 行锁/部署级验证；如需进一步消除竞态，可在后续扩展迁移中逐步增加受控 FK 和金融流水冻结策略。

此项属于 C3/C4 财务数据无损清理，不能直接用旧 V2 路由退役代替安全门禁。
