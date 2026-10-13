# 隔离管理套餐列表 v1

> 状态：Current，仅默认关闭的隔离 local/testing 切片；见[验收报告](../../../docs/architecture/v2/internal-admin-plan-list-verification.json)。

B5.20 增加 `admin.plan.list` version `1`，只由 `admin-gateway` 调用。独立 `CORE_INTERNAL_ADMIN_PLAN_LIST_ENABLED` 默认关闭，并要求全局 Internal 开关、local/testing、隔离副本。每次使用当前 Sanctum、封禁、AdminPath/Admin 授权；服务证书不授予管理员角色。

输入闭集为 `admin_path`（1..128 ASCII）、整数 `page`（1..9999）、`per_page`（1..100）。委托原生 `CommerceReadController::plans()`，保持 sort/id 升序、当前权限组、所有关联用户数和原生到期条件下的 active_users_count。隐藏、停售和不可续费的套餐均保留；不调用公开可售套餐过滤。分页为实时读取。

`data={items,pagination}`，pagination保留 page/per_page/total/last_page。item闭集为字符串id、name、可空content/group_id/group `{id,name}`、字符串 `traffic_limit_gib`、可空整数speed_limit_mbps/device_limit/capacity_limit/reset_traffic_method、`native_prices_major` 对象、tags数组、show/sell/renew布尔值、整数sort/users_count/active_users_count。

`traffic_limit_gib` 保留原套餐GiB整数，不称为字节。`native_prices_major` 只使用原生规范周期，值为可空非负十进制字符串，允许原生数字的指数表示；不乘100、不四舍五入、不构造Offer或报价，不从只读列表推断购买价格。数字值使用PHP原生JSON表示转为字符串；原生价格之前已经过PHP JSON解析，不能声称保存数据库原始词法精度。非数值、负数、未知周期或超过128字符拒绝整页；零和null保留。金额不转JavaScript Number。空价格返回对象，孤儿group返回null。

`meta={current_only:true,slice:internal-admin-plan-list/1}`，private/no-store。业务表不写，既有Sanctum使用时间、nonce、计数和技术审计可写；SQL/审计失败拒绝，不能隐藏失败或回退原生API。Gateway只消费固定Operation和闭集DTO，核对分页、group_id和关联ID，不缓存角色、不自动重试。原生描述和tags是内容数据，未来前端仍须遵循现有内容渲染规则。客户端64KiB响应上限可能拒绝包含很长内容的整页。

本切片不开放公网Admin入口、正式SC-v2、管理前端、套餐写入或生产PKI/私网。新用户前端仍延期。
