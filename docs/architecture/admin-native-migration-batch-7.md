# React Admin 原生化 Batch 7：用户创建、编辑与资金快照保护

本批仅改 TXBoard 主仓 Laravel + React Admin，统一路径 `/txapi/admin/{admin_path}`。独立 TX-Node、Gateway、真实支付商、第三方插件适配仍后置。

## 本次落地
- `POST /users`：仅支持单用户创建，强制显式设置至少 8 位密码（不再默认邮箱即密码），验证邮箱格式/重复和套餐存在，复用 UserService 的套餐/试用逻辑；返回用户 ID 而非密码、token、uuid 或订阅 URL。
- `POST /users/{id}/update`：服务器限定编辑器可更改的邮箱/密码/套餐/过期时间/流量/限制/余额/佣金余额/邀请人/禁用状态等字段；不允许直接提交管理和员工角色、模型 ID。
- 编辑器的金额仍显示“元”，但请求必须提交用户原始余额的**整数分快照** `expected_balance_minor`、`expected_commission_balance_minor`。行锁事务中若账本发生变化则返回验证错误，防止浏览器旧值覆盖支付/返佣；金额最多 2 位小数，严格无负数/异常格式。
- 权限、路径、用户数据白名单、套餐查询、密码哈希与会话撤销复用已有 Laravel/AuthService/HookManager。插件 after Hook 接收实际提交的哈希而不是明文。
- React Admin 用户创建编辑切至 TXAPI；创建表单必须填密码；邀请人字段未知时不清除旧值。
- SQLite/MySQL、TS 类型/请求测试验证未授权、错误管理路径、资金乐观并发冲突、计划分配、密码敏感值及后台 UI 兼容。

## 未改变
- 保留 V2 创建/编辑接口供暂存的外部或未迁移消费者，后续须将旧 V2 写路径纳入同等资金快照与权限策略后才进行路由退役。
- 本批不开放管理员角色提升；须单独设计拥有者/双人复核机制。
- 线上财务对账、数据恢复/回滚、支付商签名、真实 Node/Gateway 联调不能以 CI 替代。
