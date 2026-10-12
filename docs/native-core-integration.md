# 当前 Gateway 与 Core Native 适配

2026-10-12：本次交付属于 B5/B6 准备，保留当前 `/gateway/v1` 外部合同。目标 `/txapi/bff/v1`、统一 User/Admin Gateway、版本协商、正式部署与 SDK 发布均没有因此完成。

`apps/gateway/src/services/upstream.ts` 固定列出 15 个历史操作；14 个映射到当前 `/txapi`，匿名 `guestPaymentMethods` 无对应接口。`adapters/txapi-native.ts` 严格校验 Native 信封和每类 DTO，再投影为已有主题 SDK 展示字段。旧 `status:success`、旧 paginator、含糊信封、无效数据拒绝。

`/catalog/payment-methods` 始终 404、零上游调用；历史 `GATEWAY_PAYMENT_CATALOG_ENABLED` 不能绕过 Core 保护。bootstrap 不声明该能力，policy operation 集合为空。用户 `/payments` 仍需调用者的 Bearer；没有共享管理员凭据。默认订单创建仍为 405、零上游调用；只有显式启用现有写入开关才转发单个创建操作。认证、注册、邮箱码与公共读请求不转发 Authorization；幂等头只用于创建订单。

金额保持分单位，ISO 日期转换为现有秒时间戳。套餐流量展示由字节转换为 GiB，用户流量仍为字节。订阅概览删除私密地址和 UUID，用户资料也不透传 UUID、凭据或未知扩展字段。支付方法只输出展示字段；订单详情与状态必须匹配请求标识。

订单支持状态 0..4，已折抵状态 4 原样透传。原 `/orders` 保持数组形状，支持可选 `current/page_size`，默认第 1 页最多 100 条；新增 `/orders/page` 返回 `{items,current,pageSize,total,lastPage}`，bootstrap 声明 `orders.page`。SDK 提供 `orders.page()`，Vue/React 示例在发现能力后逐页读取，未提供分页时显示历史范围提示。Native 分页必须与请求及条数一致。通知 `current/pageSize` 转为 `page/per_page`，输出仍为 `{data,total}`。

## 复现隔离 HTTP 联调

需要 Linux、Git、PHP 8.4、已安装 Core Composer vendor、Node 22、MariaDB/MySQL CLI、OpenSSL、`flock`、独立数据库服务及 Redis。该脚本只授权专用 `txboard_gateway_test` 数据库，既不连接生产数据库，也不运行 Gateway 数据库代码。固定测试端口和 fixture 设置由独占文件锁保护，禁止同时运行两个副本。

```sh
export TXBOARD_CORE_REPO=/path/to/TXBoard
export TXBOARD_CORE_VENDOR=/path/to/TXBoard/api/vendor
export MYSQL_TEST_SOCKET=/path/to/test/mysql.sock
export MYSQL_TEST_PORT=13306
export REDIS_TEST_PORT=16379
export GATEWAY_INTEGRATION_SCRATCH=/path/to/test/evidence
npm ci
npm run test:core
```

脚本从 Core **committed HEAD** 导出独立快照并复制 vendor，保存 commit provenance，生成仅测试使用的 APP_KEY，执行正常 migrations。它不包含主仓未提交改动。数据库授权仅限固定测试库；fixture 启动后再次检查 testing 环境、数据库名和真实 `mail.driver=log`，才允许创建测试数据。邮件环境同时设置 `MAIL_DRIVER=log` 与 `MAIL_MAILER=log`，且 SMTP fallback 指向 loopback，不外发邮件。测试会保留专用数据库与日志以供复核；不会清空其他数据库或 Redis。

真实 PHP HTTP 服务位于 loopback 18081，Gateway 默认禁写/显式启用写入实例位于 18082/18083。两个 Gateway 都使用真实网络 fetch；HPKE 使用临时 P-256 测试密钥，nonce 和账号限流使用 Redis 专用 DB 12。测试通过 Core 注册/登录实际签发 Sanctum 会话，没有静态假 token 或管理员 bearer。

覆盖内容：

- 当前允许操作全部正例：站点配置、主题、套餐、登录、加密注册、加密邮箱码、个人资料、订阅概览、统计、订单列表/详情/状态、支付方法、显式开关下创建订单。
- 缺失 Bearer 零上游调用；非法会话由 Core 拒绝；两个真实用户的资料及订单隔离；他人订单详情/状态 404；注销后的会话再次访问 401。
- 真实 Core fixture 创建 101 条历史已折抵订单，验证两页覆盖全部且无重复；原数组第 2 页与分页 DTO 一致，另一用户不可见。
- 默认禁写 405、零上游调用；启用写入仍必须认证和提供幂等键；同键重放只看到同一订单，不同 body 同键 409 冲突。
- CAPTCHA 拒绝、Core 429 限流、通知可见性、未知字段过滤和匿名支付目录 404。Core 原有限流不被关闭。
- 邀请与知识库直接走 Core：邀请代码只属于发起用户；知识库私密片段对无有效订阅用户隐藏，对有有效订阅用户可见；匿名请求 401。Gateway 对邀请/知识库仍返回 404，本次没有扩大路由集。

证据目录保存 `result.json`、`migrations.log`、`build.log`、`core-http.log`、`gateway-requests.log` 与独立 Core snapshot。请求日志使用现有脱敏 logger，不写入 Bearer、密码或邮箱。Core 的日志邮件驱动可能记录专用测试验证码，证据目录仅用于本地测试，不应发布邮件日志。

## 验证范围与局限

实际基线为 Alpine 3.22、PHP 8.4、Node 22、**MariaDB 11.4.12** 和 Redis，不能写成已验证 MySQL 8.4。Core 版本和数据库版本由联调报告实际记录。当前准备工作的全量 `npm run check`、严格 adapter 测试及 HTTP 集成结果见 [开发状态](./development-status.md)。

Core 的默认 throttle 签名在不同接口限额之间共享请求计数；先大量读再调用 `/auth/login` 或创建邀请可能触发更低限额。联调先完成敏感正例，再明确验证末尾 429；没有绕过或禁用限流。调整生产限流维度属于 Core 后续工作。

现有浏览器 SDK 测试的模拟 Core fixtures 也改为 Native DTO，结果只证明浏览器与 Gateway/SDK 的合同。Linux musl 可指定系统 Chromium：

```sh
GATEWAY_TEST_REDIS_URL=redis://127.0.0.1:16379/12 \
GATEWAY_TEST_CHROMIUM_PATH=/usr/bin/chromium \
npm run test:browser
```

完整生产数据、真实外部邮件投递、真实第三方 CAPTCHA 成功验证、支付 Provider/回调、MySQL 8.4、目标 BFF/版本协商、生产部署和 SDK 发版均未验收。原有 POSIX Docker secret 路径测试必须在 Linux 验证，Windows 单测不能代替该项。
