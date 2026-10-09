# React Admin TXAPI Batch 5：套餐写端

范围：TXBoard 主仓 React Admin 管理套餐。外部 TX-Node/Gateway/支付商/插件适配仍全部后置。

- POST /txapi/admin/{admin_path}/plans：新建/保存套餐，正数金额周期白名单、权限和必需流量的参数验证。已存在套餐可选择 force_update 将流量、分组、速率、设备限制同步给订阅用户，复用 TXBoard 原有用户/套餐数据库语义。
- POST /txapi/admin/{admin_path}/plans/{id}/flags：只允许 show/sell/renew 布尔字段；禁止修改资金、权限和私密账户字段。
- POST /txapi/admin/{admin_path}/plans/sort：唯一 ID、最大 1000 项、数据库锁和事务，防止部分成功。
- POST /txapi/admin/{admin_path}/plans/{id}/delete：套餐仍被用户/订单引用时返回 409，禁止破坏历史订单权益。
- 所有管理员变更暂用 POST，是为了继续经过当前只记录 POST 的 RequestLog 审计中间件。后续 RequestLog 全 HTTP unsafe-method 覆盖后可再统一为 RESTful PATCH/DELETE。
- React Admin 原套餐表单、排序、状态切换和删除动作已调用新 TXAPI；读端则在上一批完成。V2 旧 Plan Controller 及路由暂保留，直到所有内部回归与外部兼容边界核查完成。
- Release 门禁：管理员与 secure_path 作用域、SQLite/MySQL、复杂钱包和订阅用户计费状态、前端构建/测试、镜像内 Caddy 检查。CI 不是完整线上生产认证。
