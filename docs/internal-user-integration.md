# User Internal mTLS 当前本机接入

状态：Current / local-testing-only，B5.2–B5.8 已验收，订单创建另限隔离测试副本。现有 Gateway v1 受保护只读操作均可选择本地 Internal 上游，公共展示读取有额外 opt-in。完整 B5/B6、管理员 Gateway、SC-v2 和生产门禁仍未完成。

`GATEWAY_INTERNAL_USER_ENABLED` 默认 false；undefined、空串和精确 false 关闭，其他非空误值启动失败；只有精确字符串 true 且 `APP_ENV=local/testing` 才可启用。启用后 profile/detail、orders list/page/status、dashboard、payment-methods、subscription-summary、notices 调用固定 User operation，request contract_version=2。登录仍使用原路径；公共元数据和订单创建的额外开关见下文。

启用时必须设置服务器本地绝对 `GATEWAY_INTERNAL_USER_CONFIG_FILE`，内容精确为：

```json
{
  "origin": "https://127.0.0.1:19443",
  "servername": "internal-core.local",
  "ca_path": "/absolute/local-fixture/ca.pem",
  "crl_path": "/absolute/local-fixture/ca.crl",
  "cert_path": "/absolute/local-fixture/user.pem",
  "key_path": "/absolute/local-fixture/user.key"
}
```

它是服务器信任配置，不能从浏览器、body/header 或任意 URL 获取。配置拒绝重复/转义重复 JSON 键和无效 UTF8。origin 要求与 URL.origin 字节一致，只接受 clean HTTPS 的 127.0.0.1/internal-core.local，固定 servername=internal-core.local；不允许 userinfo/path/query/hash 或 HTTP。未配置、错误环境、坏材料立即启动失败，错误消息不含文件路径、PEM、Bearer 或底层 crypto 信息。路径是可信服务器本地 regular file，允许由管理员维护的 symlink；不接受请求传来的路径。测试配置最大 8KiB，CA/CRL 最大 32KiB，leaf/private key 最大 8KiB。

客户端 leaf 必须非 CA、唯一 `DNS:user-gateway.internal`、当前有效，RSA≥2048 且私钥与 leaf 公钥匹配。Admin、未知/多 SAN、CN fallback 拒绝。Node 内置 HTTPS 用真实 mTLS：TLS1.3、rejectUnauthorized=true，保留默认 CA/CRL/服务端主机名检查后，再要求唯一 server DNS SAN internal-core.local。每次请求重新握手（agent=false）并检查本地 leaf 有效期，避免长连接缓存服务器身份；配置材料在 startup 加载，轮换需要重启 Gateway，不声称热加载/生产 PKI 已完成。Core 与 TLS boundary 的 current proof/角色/fingerprint trust 仍是最终权威。

Gateway 只传用户的真实 Authorization Bearer 和新建版本2正文，不签服务 proof、不持有 proof key、不访问 Core 数据库。身份/证书/actor header 无授权意义，也不会转发。Core 自己验证 Sanctum 当前账号/会话、封禁、管理员拒绝、订单归属、计划缺失。客户端证书不是用户授权。

请求路径只允许固定 User operation，POST JSON 包含服务器 UUID、UTC deadline≤5 秒和封闭 input。Authorization≤4KiB，body 为固定≤16KiB结构，response≤64KiB；总 walltime≤5 秒，连续 chunk 不延长期限。超时/错误/超大响应/异常 close/AbortSignal 都清 timer 并 destroy transport。每阶段的期限不代表取消 Core 阻塞 SQL；业务同步 handler 仍可能继续到返回。

不跟随 redirect，不自动重试 Internal，不发生 Native/明文 fallback。TLS、Core404、授权失败、格式错误、超大/错误 UTF8/重复键、未知 DTO 或数值溢出均返回拒绝；错误不公开 Core message、proof、私钥或 actor credential。默认关闭不产生 Internal 网络调用。

Internal envelope/meta/DTO 用精确 schema，拒绝额外/重复关键 JSON 字段。v2 profile 加 telegram_id；订单 detail 加 plan traffic、payment_id、balance/discount；均逐字段映射为现有 Gateway v1 DTO，没有字段编造。十进制 string 先 BigInt 校验，只有 JS 安全整数范围才转 Number，minor money/bytes/ID 禁止负数，telegram 可带符号。status0–4与现有周期/时间/GB 展示一致，核验响应 trade_no 与请求一致。结果不含 UUID、订阅链接、callback 或用户间泄漏。

B5.3 list input 固定 page 1–9999、per_page 1–100 与可选 status 0–4，均为整数。Core 复用 AccountController 当前 owner/filter/order/pagination；返回轻量 orders 与 pagination，不扩展支付/余额 detail。Adapter 核对请求 page/size/status、行数/total/lastPage、唯一 ID/trade_no、精确字段与安全整数。已删除套餐保留 null；现有 `/orders` array 和 `/orders/page` page shape 不变。各页是 live reads，没有跨页快照；并发 count/list 不一致或超过字节上限会拒绝。

## 复现

本次验收建立固定 Core `422e85c44d064bfb763922316acd17d0174137d4` 完整 snapshot，叠加当前 Internal changes，使用 Core prepare-snapshot.py 与 locked vendor；Gateway 用 `tests/integration/prepare-internal-user-snapshot.py <source> <new-target> <linux-dependency-source> ee1cec6251dbc52368f4b7dfd9fcdb4d49d8954a` 建立 committed Gateway + Internal only 快照。两份 snapshot 保存真实 commit/hash，排除并行工作；源码清单见下方 B5.7 证据。

准备独占空 `txboard_internal_test`（重跑只能明确核验并清此 fixture 库，脚本不会 migrate:fresh）；Core fixture 签发用户会话，Gateway 联调另通过真实 /txapi/auth/login 获取用户 Bearer。密钥、token 只留临时目录/进程内存，结尾删秘密并停止服务。Redis固定实验室16379 DB14/cache15。

```bash
cd /path/to/gateway-snapshot
npm run build --workspace @txboard/gateway
DB_DATABASE=txboard_internal_test DB_HOST=127.0.0.1 DB_PORT=13306 \
DB_USERNAME=fixture-user DB_PASSWORD=fixture-password \
bash tests/integration/run-internal-user-linux.sh /path/to/core-snapshot/api
```

固定 loopback 端口 Core19081/TLS19443/Gateway19582–19588，以及错误 server SAN fixture19445；请确保没有其他进程占用。正向逐字段比较 Native/Internal profile、detail、list/page/filter/missing-plan；负向覆盖真实 CA/CRL/SAN、身份角色、owner404、expired/revoked/banned、BIGINT 字节数 overflow、default-off/production404及 TLS offline no fallback，并检查日志没有秘密。金额、ID、telegram 的溢出及非规范十进制由定向测试覆盖。结果只保留 source hash、runtime、公开 fingerprint、case 和 sanitized audit 统计。

MySQL8.4、生产网络/PKI自动轮换、细粒度 RBAC、cluster/Redis failover、Gateway 全部 operation、SC-v2 与生产发布仍需独立验证。

历史 B5.2 的 [脱敏验证记录](../tests/integration/evidence/internal-user-baseline.json) 保留 24 项真实 Core/Gateway 检查与原始 source hash。B5.3 的 [列表验证记录](../tests/integration/evidence/internal-order-list.json) 记录 27 项真实联调、767 项 API（另 21 项跳过）、492 项 Gateway 和 68 项 SDK，以及固定基线和精确源码。Launcher 在 setup 失败时也清理临时证书/会话材料，只保留脱敏 evidence/log。

B5.4 also selects authenticated `/dashboard/stats` and `/payments` using exact
empty-input `user.dashboard.get` / `user.payment-methods.list`. Existing public
camelCase display DTOs, enabled method ordering and native numeric fees are
preserved. Payment reads require the current non-admin User; neither method
config nor provider initialization crosses this boundary. Fixed final Core
`79a3854` / Gateway `6ea649b` checks passed 803 API (29 skipped), 512 Gateway,
68 SDK and 29 actual TLS1.3 integration checks. See
[verification evidence](../tests/integration/evidence/internal-user-display.json).

B5.5 also selects `/user/subscription/summary` and bounded `/notices` via fixed
User operations. The summary omits credentials and the private subscription
URL; current plan failures remain Core 409. Notices retain native visibility,
sort, content and pagination. Fixed Core `60e6eca` / Gateway `31bde17` passed
806 API (29 skipped), 526 Gateway, 68 SDK and 35 actual TLS1.3 integration
checks. See [verification evidence](../tests/integration/evidence/internal-user-content.json).

B5.6 also selects `/orders/{trade_no}/status` via `user.order-status.get` with
exact trade input. Native ownership, statuses and reads after Plan deletion
remain unchanged. Gateway binds the closed status DTO to the requested trade
number and retains `{tradeNo,status}`. Fixed Core `dd3b4fd` / Gateway `257ff7e`
passed 808 API (29 skipped), 527 Gateway, 68 SDK, typecheck/build and 35 actual
TLS1.3 integration checks. See
[verification evidence](../tests/integration/evidence/internal-order-status.json).

B5.7 仅在现有 `GATEWAY_ORDER_WRITE_ENABLED=true` 和本地 Internal transport 同时启用时，选择 `user.order.create` version1。Core 还要求严格启用 `CORE_INTERNAL_ORDER_WRITE_ENABLED=true`、隔离副本标记和原 local/testing gate。默认关闭仍返回 405；Internal 写入口关闭返回 Core404，不回退 Native。

请求正文绑定原客户端 Idempotency-Key；适配器只映射 Plan ID、周期、可选优惠码，不计算金额或扣款。原 BillingController/OrderService 的 SQL 幂等与 User 锁继续权威，成功只接受 HTTP201 和封闭 `internal-order-write/1` DTO。Core/Gateway 不自动重试；提交后超时、断线或审计失败可能返回错误但已建单，客户端用新 transport request 和原业务键/参数恢复。原 TTL、当前 actor、Plan 和 owned Order 可访问性仍约束恢复；删除 Plan 后原重放继续404。公共 JSON parser 的既有重复字段处理未改变，Core 对实际 Internal 字节单独拒绝重复字段。

B5.7 固定 Core `422e85c` / Gateway `ee1cec6` 加十四文件：845项API通过（36项按引擎跳过、7085断言），529项Gateway、68项SDK、typecheck/build及40项真实TLS1.3/MariaDB检查通过。同键并发只预扣一次、过期键替换后立即重放、审计503后独立确认已提交再原键恢复均已验证。[验收证据](../tests/integration/evidence/internal-order-write.json)记录精确源码、原始日志hash和秘密清理结果。

B5.8 的额外 `GATEWAY_INTERNAL_PUBLIC_READ_ENABLED=true` 只选择 `/bootstrap`、`/theme/config`、`/plans` 的两个固定匿名展示 operation；要求上述 local/testing Internal transport，误值、缺配置或生产启用均启动失败。Core 另要求 `CORE_INTERNAL_PUBLIC_READ_ENABLED=true`。浏览器 Bearer 不转发，实际 Internal Authorization 必须不存在；user-gateway 证书和 proof 仍必需，支付等受保护接口仍要求当前 User。

公开 DTO、原主题扩展和套餐价格/可见性不变；响应先经闭合 schema 与安全整数校验。现有公共缓存遵守原 TTL，缓存元数据不代表当前授权；被选中的 Internal cache miss 失败无 Native 回退。联调固定端口另增加19589，边界用例先清共享缓存，单独验证 MISS/HIT 只生成一条 SQL 审计。

B5.8 固定 Core `a7e73b1` / Gateway `8c03a78` 加十六文件：872项API通过（40项引擎专测跳过、7697断言），596项Gateway、68项SDK、typecheck/build及48项真实TLS1.3/MariaDB检查通过。[验收证据](../tests/integration/evidence/internal-public-read.json)保存源码和日志hash、匿名actor marker、缓存检查与秘密清理结果；登录/注册/邮件和生产PKI仍待实现。
