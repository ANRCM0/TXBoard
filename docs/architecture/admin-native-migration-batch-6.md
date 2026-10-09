# React Admin TXAPI Batch 6：订单详情与结算控制

仅迁移 TXBoard 主仓原有管理员订单详情、手动标记支付和取消接口。真实支付商签名适配、TX-Node 和独立 Gateway 延期。

- GET /txapi/admin/{admin_path}/orders/{id}/detail：按显式 ID 加载，保留订单详情 UI 需要的用户/套餐/佣金/折抵字段；仅投影 id/email，避免旧 V2 直接返回所有用户字段与 token。
- POST /txapi/admin/{admin_path}/orders/{tradeNo}/paid：管理员手动确认订单；保留 OrderService 的行锁、状态机、开通队列与重复请求保护，负数金额不能结算。**此路径只代表管理员人工确认，不是支付商签名证明或线上对账。**
- POST /txapi/admin/{admin_path}/orders/{tradeNo}/cancel：通过 OrderService 取消待支付订单，重用余额退回、优惠券额度归还与幂等保障。
- 旧订单指派、佣金复核和复杂编辑仍走 Admin V2，必须独立完成事务/审计回归才能切换并裁撤。
- 原支付回调/订阅与外部协议继续保留；不得凭合成测试宣布已完成生产支付验收。
- 验收包含管理员权限、动态路径、跨用户凭据隔离、重复回调/取消、SQLite/MySQL 与队列，并保留发布前数据恢复演练。
