# User Internal mTLS 当前本机接入

状态：Current / local-testing-only，B5.2/B5.3。迁移 profile、detail 和有界 orders list/page 的可选上游，不开放管理员 Gateway、不增加公网路由、不实现 SC-v2。完整 B5/B6 和生产门禁仍未完成。

`GATEWAY_INTERNAL_USER_ENABLED` 默认 false；undefined、空串和精确 false 关闭，其他非空误值启动失败；只有精确字符串 true 且 `APP_ENV=local/testing` 才可启用。默认现有用户路径继续 Native 请求。启用后 profile/detail 分别调用固定 Core `user.profile.get` / `user.order.get`，orders list/page 调用 `user.order.list`，request contract_version=2。status、订阅、支付、登录和写入保留当前 Native 行为。

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

请求路径只允许固定三个 user operation，POST JSON 包含服务器 UUID、UTC deadline≤5 秒和封闭 input。Authorization≤4KiB，body 为固定≤16KiB结构，response≤64KiB；总 walltime≤5 秒，连续 chunk 不延长期限。超时/错误/超大响应/异常 close/AbortSignal 都清 timer 并 destroy transport。每阶段的期限不代表取消 Core 阻塞 SQL；业务同步 handler 仍可能继续到返回。

不跟随 redirect，不自动重试 Internal，不发生 Native/明文 fallback。TLS、Core404、授权失败、格式错误、超大/错误 UTF8/重复键、未知 DTO 或数值溢出均返回拒绝；错误不公开 Core message、proof、私钥或 actor credential。默认关闭不产生 Internal 网络调用。

Internal envelope/meta/DTO 用精确 schema，拒绝额外/重复关键 JSON 字段。v2 profile 加 telegram_id；订单 detail 加 plan traffic、payment_id、balance/discount；均逐字段映射为现有 Gateway v1 DTO，没有字段编造。十进制 string 先 BigInt 校验，只有 JS 安全整数范围才转 Number，minor money/bytes/ID 禁止负数，telegram 可带符号。status0–4与现有周期/时间/GB 展示一致，核验响应 trade_no 与请求一致。结果不含 UUID、订阅链接、callback 或用户间泄漏。

B5.3 list input 固定 page 1–9999、per_page 1–100 与可选 status 0–4，均为整数。Core 复用 AccountController 当前 owner/filter/order/pagination；返回轻量 orders 与 pagination，不扩展支付/余额 detail。Adapter 核对请求 page/size/status、行数/total/lastPage、唯一 ID/trade_no、精确字段与安全整数。已删除套餐保留 null；现有 `/orders` array 和 `/orders/page` page shape 不变。各页是 live reads，没有跨页快照；并发 count/list 不一致或超过字节上限会拒绝。

## 复现

建立固定 Core `e1ed462232404596eb48b0a2b7ef5bebbf2fe146` 完整 snapshot，叠加当前 Internal changes，使用 Core prepare-snapshot.py 与 locked vendor；Gateway 用 `tests/integration/prepare-internal-user-snapshot.py <source> <new-target> <linux-dependency-source> 2a5d2ee2dd01a7568a0b121236b158fa5339fd8e` 建立 committed Gateway + Internal only 快照。两份 snapshot 保存真实 commit/hash，排除并行工作。

准备独占空 `txboard_internal_test`（重跑只能明确核验并清此 fixture 库，脚本不会 migrate:fresh）；Core fixture 签发用户会话，Gateway 联调另通过真实 /txapi/auth/login 获取用户 Bearer。密钥、token 只留临时目录/进程内存，结尾删秘密并停止服务。Redis固定实验室16379 DB14/cache15。

```bash
cd /path/to/gateway-snapshot
npm run build --workspace @txboard/gateway
DB_DATABASE=txboard_internal_test DB_HOST=127.0.0.1 DB_PORT=13306 \
DB_USERNAME=fixture-user DB_PASSWORD=fixture-password \
bash tests/integration/run-internal-user-linux.sh /path/to/core-snapshot/api
```

固定 loopback 端口 Core19081/TLS19443/Gateway19582–19587，以及错误 server SAN fixture19445；请确保没有其他进程占用。正向逐字段比较 Native/Internal profile、detail、list/page/filter/missing-plan；负向覆盖真实 CA/CRL/SAN、身份角色、owner404、expired/revoked/banned、BIGINT 字节数 overflow、default-off/production404及 TLS offline no fallback，并检查日志没有秘密。金额、ID、telegram 的溢出及非规范十进制由定向测试覆盖。结果只保留 source hash、runtime、公开 fingerprint、case 和 sanitized audit 统计。

MySQL8.4、生产网络/PKI自动轮换、细粒度 RBAC、cluster/Redis failover、Gateway 全部 operation、SC-v2 与生产发布仍需独立验证。

历史 B5.2 的 [脱敏验证记录](../tests/integration/evidence/internal-user-baseline.json) 保留 24 项真实 Core/Gateway 检查与原始 source hash。B5.3 的 [列表验证记录](../tests/integration/evidence/internal-order-list.json) 记录 27 项真实联调、767 项 API（另 21 项跳过）、492 项 Gateway 和 68 项 SDK，以及固定基线和精确源码。Launcher 在 setup 失败时也清理临时证书/会话材料，只保留脱敏 evidence/log。
