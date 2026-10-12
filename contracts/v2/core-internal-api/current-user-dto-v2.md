# Internal User DTO v2 当前本机切片

状态：Current / local-testing-only，B5.2。基于已提交 B5.1 `8543668ccf7c53174f4ce1e9f4d0f25856fe2855`，不代表全部 B5/B6、生产门禁或 SC-v2 完成。

沿用 `POST /txapi/internal/v1/operations/{id}` 和 B5.1 服务证明/授权机制，请求仅新增 `contract_version:"2"`。完整原始正文（包含版本与 deadline）仍被 JWS body hash 绑定。JWS v=`internal-readonly/1` 是服务证明版本，与 DTO request version 分别验证。

| operation | v1 | v2 |
| --- | --- | --- |
| user.profile.get | 保持原字段 | 原字段加 telegram_id，nullable 带符号十进制字符串 |
| user.order.get | 保持原轻量订单与 trade_no 长度 1–64 | 委派现有 AccountController::orderDetail；trade_no 1–128；原订单字段加 plan.traffic_limit_bytes、payment_id、balance_amount_minor、discount_amount_minor |
| admin.order.get | 原 DTO/权限 | 拒绝 400 INVALID_REQUEST |

成功 meta 精确 `{current_only:true,slice:"internal-readonly/2"}`。profile 仍不包含 uuid/token/password；详情仍不包含 user_id/callback_no/邀请与佣金内容。所有新增 ID、minor money、bytes 为十进制字符串；Unix 秒和有限枚举保持整数。status 精确保留 0–4。字段由现有 Native read Controller 投影，不创建额外业务权限、价格规则或修改动作。

v2 与 v1 一样只允许 user-gateway 服务角色、有效 Sanctum 普通用户、未封禁账号；管理员 actor 即使会话有效也拒绝。订单归属、计划缺失 404 沿用 AccountController::orderDetail。撤销/过期/封禁/角色变化每请求由 Core 判定。admin-gateway 不能调用用户操作，没有管理员 Gateway。

默认关闭和 APP_ENV=production 的 404 gate 保留。边界 TLS、固定 key/epoch/cert trust、nonce/rate/audit、总 upstream walltime 及同步 SQL deadline 限制沿用 [v1 Current contract](current-readonly-slice-v1.md)。v2 不改变 v1 精确 DTO，客户端必须明确选择版本；未知版本/字段拒绝，不自动降级。

Gateway B5.2 仅可在服务器 local/testing 明确 opt-in 后把现有 `/gateway/v1/user/profile` 和 `/gateway/v1/orders/:tradeNo` 改用上述两个 v2 operation；缺配置、TLS、授权、DTO、数值溢出或 Core 禁用均拒绝，无 Native/明文 fallback。默认 Native、其他公网路径、SDK 数字展示契约不变。

模型整数进入 Internal 时保留十进制字符串。Gateway 用 BigInt 校验 JS 安全整数范围，溢出拒绝 502，不能截断、舍入或把未验证字符串当作 Number。支付/余额/折扣字段只展示现有 Core 的结果。

固定 Core/Gateway 提交、完整回归及真实 mTLS 联调的脱敏结果见 [B5.2 验证记录](../../../docs/architecture/v2/internal-user-v2-verification.json)。B5.1 的历史证据保持原提交基线，不与本次结果混用。
