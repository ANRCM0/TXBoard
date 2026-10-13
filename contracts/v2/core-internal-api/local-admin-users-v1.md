# 隔离管理用户读取 v1

> 状态：Current，仅默认关闭的隔离 local/testing 切片；见[验收报告](../../../docs/architecture/v2/internal-admin-users-verification.json)。

B5.22 加入 `admin.user.list` / `admin.user.get` version `1`，只允许独立admin-gateway服务和当前真实Admin凭据。`CORE_INTERNAL_ADMIN_USERS_ENABLED` 独立默认关闭，同时要求全局Internal、local/testing与隔离副本。当前封禁、管理路径与Admin角色每次由Core验证；服务身份和输入owner不授予权限。

list输入闭集为admin_path、整数page1..9999/per_page1..100，可选布尔banned、规范正十进制字符串plan_id（原生INT上界2147483647）。复用原管理目录查询、当前Plan/Group关联、id降序、筛选和分页，不开放email通配或动态sort。get输入恰为admin_path和同范围字符串id，复用原生当前User/Plan/Group/Inviter详情查询，缺失返回404。既有管理HTTP仍使用原DTO，不改变编辑器主要币值格式或筛选/排序。

新闭集User DTO为字符串id/email，可空字符串plan_id/group_id及当前可空plan/group `{id,name}`；字符串balance_minor/commission_balance_minor；traffic `{upload_bytes,download_bytes,limit_bytes}` 整数字符串；可空整数expired_at/next_reset_at/last_reset_at/last_login_at、整数created_at/reset_count、可空有限数speed_limit_mbps/整数device_limit、布尔banned/is_admin/is_staff，以及可空remarks。get额外含当前可空invite_user `{id,email}`。list返回 `{items,pagination}`，get返回单个DTO；分页是实时读取，关联删除可为null。

金额从原始持久INT直接读取，不经过旧DTO除100，不扩容原生32位字段；流量从原持久字节整数读取，不经过JS Number、GiB换算或先求和。null原生金额/计数按现有0语义输出，不推断订阅Terms、授权或账本。异常整数字段拒绝503，不修复原记录。SQL故障固定503，User不存在为原生404；nonce/审计/响应大小故障拒绝。业务表不写，Sanctum last_used_at及技术nonce/计数/审计可写。

`meta={current_only:true,slice:internal-admin-users/1}`，private/no-store。不返回Token/UUID/密码/算法/盐/订阅链接/Telegram凭据或泛型Model。Gateway固定Operation与严格DTO核对请求id、分页、筛选及关联ID，不缓存角色、不自动重试或Native回退；64KiB响应上限可能拒绝长remarks页面。管理公网入口、SC-v2、细粒度RBAC、新管理前端、修改用户和生产门禁另行验收，新用户前端延期。
