# 本地管理订单列表 v1

> 状态：Current，仅默认关闭的隔离 local/testing 切片。

B5.19：`admin.order.list` version `1` 复用原生 `CommerceReadController::orders()` 的查询、状态过滤、当前User/Plan关联和稳定排序，Core保持唯一订单权威。独立 `CORE_INTERNAL_ADMIN_ORDER_LIST_ENABLED` 默认关闭，并要求global Internal、local/testing和隔离副本门禁。服务只能是admin-gateway；当前Sanctum、封禁、AdminPath/Admin继续复用现有授权。

`input` 恰好包含ASCII `admin_path`、整数 `page`（1..9999）、`per_page`（1..100），可选整数 `status`（0..4）。不开放email、callback_no、通配SQL或caller身份筛选；未知字段、重复成员、错误类型和版本拒绝。

成功 `data` 为 `{items,pagination}`。pagination沿用原page/per_page/total/last_page。每条item只含字符串id/trade_no/user_id/plan_id、当前可空plan `{id,name}` 与user `{id,email}`、规范period、整数type/status、字符串 `amount_minor`、`handling_amount_minor`、`balance_amount_minor`、`discount_amount_minor`、整数created_at/updated_at以及可空paid_at。金额保持原整数最小币值，不重算应付或变为财务事实；不传callback_no、返佣策略、surplus历史引用、Token或订阅秘密。孤儿User/Plan保留null，状态4保留；分页不是冻结快照。

`meta={current_only:true,slice:internal-admin-order-list/1}`，private/no-store。读取不改业务表，原Sanctum使用时间、nonce、服务计数和技术审计可写。审计/SQL失败拒绝；同步deadline不保证取消SQL。Gateway独立管理客户端新增固定orders调用和闭集DTO，金额/ID不转Number、不缓存授权、不重试或回退。客户端核对请求页码、分页范围、状态过滤及当前关联ID。原MySQL金额字段仍为32位，本切片不扩大数据库范围。真实Core/MariaDB/mTLS联调12项通过，见[验收报告](../../../docs/architecture/v2/internal-admin-order-list-verification.json)；正式管理入口、SC-v2和独立管理前端仍按各自门禁推进。
