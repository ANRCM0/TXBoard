> Archived reverse-engineering notes. The maintained React/Vue source is authoritative.

# Xboard 前端逆向分析报告

> 版本：2026-09-20  
> 逆向对象：Xboard 已编译用户前端与管理面板  
> 说明：本报告基于无 SourceMap 的编译产物、格式化后的 bundle、接口测试结果与已创建 scaffold 项目整理。由于原始产物存在变量压缩、组件复用与命名混淆，文中对不确定项会明确标注。

---

## 目录

1. [逆向来源说明](#0-逆向来源说明)
2. [用户前端 umi.js 详解](#1-用户前端-umijs-详解)
3. [管理面板 admin.js 详解](#2-管理面板-adminjs-详解)
4. [管理面板组件分析](#3-管理面板组件分析)
5. [组件汇总与模块分类](#4-组件汇总与模块分类)
6. [跨组件实现模式](#5-跨组件实现模式)
7. [API 测试结果](#6-api-测试结果)
8. [Scaffold 项目结构](#7-scaffold-项目结构)
9. [待解决问题与下一步](#8-待解决问题与下一步)
10. [关键技术结论](#9-关键技术结论)

---

# 0. 逆向来源说明

此次逆向针对两个已编译前端 bundle，并非源码。

| 文件 | 位置 | 原始大小 | 格式化后 | 说明 |
|---|---|---:|---:|---|
| `umi.js` | `/www/public/theme/Xboard/assets/umi.js` | 1.4 MB | 2.3 MB / 59139 行 | 用户前端 |
| `index-CEIYH7i8.js` | `/www/public/assets/admin/assets/index-CEIYH7i8.js` | 6.5 MB | 9 MB / 232291 行 | 管理面板 |

两个 bundle 均未提供 SourceMap，因此主要通过以下方式进行逆向：

- `js-beautify` 格式化代码；
- 搜索 API 路径、路由常量、组件变量、表单 schema；
- 识别 React/Vue 常见运行时模式；
- 追踪 Axios 实例与拦截器；
- 对照浏览器行为和真实 API 测试；
- 通过已创建的 scaffold 项目验证理解是否一致。

> 注意：逆向记录中存在组件变量名复用、旧称与真实路由含义不一致的情况。后文保留变量名，但以实际路由和行为为准。

---

# 1. 用户前端 `umi.js` 详解

## 1.1 技术栈确认

| 项目 | 结论 |
|---|---|
| 框架 | Vue 3 |
| 构建 / 路由体系 | UmiJS 产物特征 + Vue Router 4 |
| 状态管理 | 自研基于 reactivity 的 store，行为类似 Pinia，但非 Pinia |
| 请求库 | Axios，自定义实例，代码中出现 `BN.create` / `TL` |
| 样式 | UnoCSS 原子化 CSS |
| Token 存储 | `sessionStorage` |
| API 版本 | `/api/v1` |

## 1.2 环境配置 `env.js`

文件位置：

```text
/www/public/theme/Xboard/env.js
```

提取到的核心逻辑：

```js
window.routerBase = "http://127.0.0.1:8000/"
window.appVersion = "0.1.1-dev"
window.apiBase = window.routerBase + "api/v1"
```

最终 API 基座：

```text
http://127.0.0.1:8000/api/v1
```

潜在问题：

- `env.js` 可作为静态文件公开访问；
- 暴露 API 基座地址；
- 暴露前端版本号；
- 若生产环境同样公开内部地址，可能造成额外信息泄露。

## 1.3 API 通信细节

| 属性 | 值 |
|---|---|
| Base URL | `http://127.0.0.1:8000/api/v1` |
| Content-Type | `application/x-www-form-urlencoded` |
| Timeout | 12 秒 |
| Token key | `sessionStorage["VANES_ACCESS_TOKEN"]` |
| Token 前缀 | `vanes_` |
| Token 有效期 | 21600 秒，即 6 小时 |
| 请求拦截器 | `TN` |
| 响应拦截器 | `RN` |

鉴权请求头：

```http
Authorization: Bearer <token>
```

免鉴权白名单共识别 7 个接口：

```text
/passport/auth/login
/passport/auth/token2Login
/passport/auth/register
/guest/comm/config
/passport/comm/sendEmailVerify
/passport/auth/forget
/passport/auth/telegramLogin
```

错误处理行为：

- `401`：提示“登录已过期”，清理 Token；
- `403`：提示“没有权限”；
- `404`：根据后端 message 显示具体提示。

## 1.4 用户前端路由

```text
/                     Dashboard
/login                Login
/register             Register
/forgetpassword       ForgetPassword
/profile              Profile
/plan                 Plan
/order                Order
/ticket               Ticket
/traffic              Traffic
/knowledge            Knowledge
/node                 Node
/invite               Invite
```

## 1.5 暴露的全局函数

| 函数 | 作用 |
|---|---|
| `window.jump(path)` | 前端路由跳转 |
| `window.handleTelegramAuth()` | 发起 Telegram 登录 |
| `window.onTelegramAuth()` | Telegram 回调处理 |
| `window.copy(text)` | 复制文本 |
| `window.recaptchaReady()` | Captcha ready 回调 |

## 1.6 用户前端 API 目录

### 用户模块

```text
GET/POST /api/v1/user/info
GET      /api/v1/user/getSubscribe
GET      /api/v1/user/getStat
GET      /api/v1/user/getActiveSession
POST     /api/v1/user/removeActiveSession
POST     /api/v1/user/update
POST     /api/v1/user/changePassword
POST     /api/v1/user/resetSecurity

GET      /api/v1/user/server/fetch

GET      /api/v1/user/order/fetch
POST     /api/v1/user/order/save
POST     /api/v1/user/order/cancel
GET      /api/v1/user/order/detail
POST     /api/v1/user/order/checkout
GET      /api/v1/user/order/check

GET      /api/v1/user/plan/fetch
POST     /api/v1/user/coupon/check

POST     /api/v1/user/gift-card/check
GET      /api/v1/user/gift-card/detail
GET      /api/v1/user/gift-card/history
POST     /api/v1/user/gift-card/redeem
GET      /api/v1/user/gift-card/types

GET      /api/v1/user/invite/fetch
POST     /api/v1/user/invite/save

GET      /api/v1/user/knowledge/fetch
GET      /api/v1/user/knowledge/getCategory
GET      /api/v1/user/notice/fetch

GET      /api/v1/user/stat/getTrafficLog
POST     /api/v1/user/transfer

GET/POST /api/v1/user/getQuickLoginUrl
```

### 认证模块

```text
POST /api/v1/passport/auth/login
POST /api/v1/passport/auth/register
POST /api/v1/passport/auth/forget
POST /api/v1/passport/auth/token2Login
GET  /api/v1/passport/auth/token2Login
POST /api/v1/passport/auth/loginWithMailLink
POST /api/v1/passport/auth/getQuickLoginUrl
POST /api/v1/passport/comm/sendEmailVerify
POST /api/v1/passport/comm/pv
```

### 公共模块

```text
GET /api/v1/guest/comm/config
GET /api/v1/client/app/getConfig
GET /api/v1/client/app/getVersion
```

### 支付

```text
POST /api/v1/guest/payment/notify/{method}/{uuid}
```

### Telegram

```text
POST /api/v1/guest/telegram/webhook
GET  /api/v1/user/telegram/getBotInfo
```

### 节点 / 代理协议

```text
POST     /api/v1/server/ShadowsocksTidalab/submit
GET      /api/v1/server/ShadowsocksTidalab/user
GET      /api/v1/server/ShadowsocksTidalab/config
GET/POST /api/v1/server/UniProxy/**
GET/POST /api/v1/server/TrojanTidalab/**
```

总体调用模式：

```text
Promise
  ├─ 成功 → response.data.data
  └─ 失败 → response.data.message
```

---

# 2. 管理面板 `admin.js` 详解

## 2.1 技术栈

| 能力 | 实现 |
|---|---|
| UI 框架 | React 18 |
| Router | React Router / Remix Router v6 |
| 代码分割 | `lazy` + 动态 import |
| 数据请求 | 自定义 React Query 风格 hooks |
| 表格 | TanStack Table |
| 表单 | react-hook-form |
| 校验 | zod |
| UI | Shadcn / Radix 风格组件 |
| 图表 | Recharts |
| Markdown | markdown-it |
| 代码编辑 | Monaco Editor |
| Toast | Sonner 风格 |
| API | Axios |
| API 版本 | `/api/v2` |

识别出的关键变量：

```text
pC(...) = query hook，类似 useQuery
mC(...) = mutation hook，类似 useMutation

Nv = react-hook-form
Mv / py = zod / resolver
NGt = TanStack Table 相关

Lf  = Button
u8e = Input
oZt = Switch
Czt = Select
hQt = Dialog / AlertDialog
q$t = Skeleton / Spinner

gE.success(...)
gE.error(...)
```

## 2.2 管理端 API 与 Token

API Base：

```js
(window.settings?.base_url || "/") + "/api/v2"
```

运行环境中实际测试到的前缀：

```text
/api/v2/de47dcba/
```

其中 `de47dcba` 为安装实例生成的唯一标识。

请求配置：

| 属性 | 值 |
|---|---|
| Content-Type | `application/json` |
| Timeout | 30 秒 |
| Token key | `localStorage["access_token"]` |
| Token getter | `Pf()` |
| Token remover | `jf()` |
| Token 前缀记录 | `Of` |
| 鉴权头 | `Authorization: Bearer <token>` |

管理端免鉴权接口：

```text
/passport/auth/login
/passport/auth/token2Login
/passport/auth/register
/guest/comm/config
/passport/comm/sendEmailVerify
/passport/auth/forget
```

未登录时，`Bf` 会执行：

```text
/sign-in?redirect=<原路径>
```

## 2.3 管理端路由树

路由常量来源：`HQe`，约位于 beautified bundle 第 136953 行起。

```text
/sign-in

/
├── config
│   ├── system
│   ├── system/safe
│   ├── system/subscribe
│   ├── system/invite
│   ├── frontend
│   ├── server
│   ├── email
│   ├── telegram
│   ├── APP
│   ├── payment
│   ├── theme
│   ├── notice
│   ├── knowledge
│   ├── plugin
│   └── subscribe-template
│
├── server
│   ├── manage
│   ├── machine
│   ├── group
│   └── route
│
├── finance
│   ├── plan
│   └── order
│
└── plugins/:pluginCode/*
```

## 2.4 管理端 API 封装

### 系统配置 `kT.*`

```text
GET  /config/fetch?key=xxx
POST /config/save
GET  /config/getEmailTemplate
POST /config/testSendMail
POST /config/setTelegramWebhook

GET  /system/getSystemStatus
GET  /system/getQueueStats
GET  /system/getQueueWorkload
GET  /system/getQueueMasters
GET  /system/getHorizonFailedJobs
```

### 邮件模板 `ST.*`

```text
GET  /mail/template/list
GET  /mail/template/get?id=xxx
POST /mail/template/save
POST /mail/template/reset
POST /mail/template/test
```

### 通知

```text
GET  /notice/fetch
POST /notice/save
POST /notice/drop
POST /notice/show
POST /notice/sort
```

### 主题

```text
GET  /theme/getThemes
GET  /theme/getThemeConfig?theme=xxx
POST /theme/saveThemeConfig
POST /theme/upload
POST /theme/delete
```

### 支付

```text
GET  /payment/fetch
POST /payment/save
POST /payment/drop
POST /payment/show
POST /payment/sort
GET  /payment/getPaymentMethods
POST /payment/getPaymentForm
```

### 礼品卡

```text
GET  /gift-card/types
GET  /gift-card/templates
GET  /gift-card/codes
GET  /gift-card/usages
GET  /gift-card/statistics
POST /gift-card/create-template
POST /gift-card/update-template
POST /gift-card/delete-template
POST /gift-card/generate-codes
GET  /gift-card/export-codes
POST /gift-card/toggle-code
POST /gift-card/update-code
POST /gift-card/delete-code
```

### 优惠券

```text
GET  /coupon/fetch
POST /coupon/generate
POST /coupon/update
POST /coupon/drop
POST /coupon/show
```

### 用户

```text
GET  /user/fetch
POST /user/update
POST /user/resetSecret
POST /user/generate
POST /user/destroy
POST /user/sendMail
POST /user/dumpCSV
POST /user/ban
POST /user/setInviteUser
GET  /user/getUserInfoById
```

### 流量重置

```text
GET  /traffic-reset/logs
POST /traffic-reset/reset-user
GET  /traffic-reset/user/{userId}/history
```

### 工单

```text
GET  /ticket/fetch
POST /ticket/close
POST /ticket/reply
POST /ticket/save
```

### 知识库

```text
GET  /knowledge/fetch
GET  /knowledge/getCategory
POST /knowledge/save
POST /knowledge/drop
POST /knowledge/show
POST /knowledge/sort
```

### 套餐

```text
GET  /plan/fetch
POST /plan/save
POST /plan/update
POST /plan/drop
POST /plan/sort
```

### 订单

```text
GET  /order/fetch
GET  /order/detail?trade_no=xxx
POST /order/paid
POST /order/cancel
POST /order/update
POST /order/assign
```

### 节点机器

```text
GET  /server/machine/fetch
POST /server/machine/save
GET  /server/machine/getToken?id=xxx
GET  /server/machine/installCommand?id=xxx
POST /server/machine/resetToken
POST /server/machine/drop
GET  /server/machine/nodes?machine_id=xxx
GET  /server/machine/history?machine_id=xxx&limit=&range_hours=
```

### 节点管理

```text
GET  /server/manage/getNodes
POST /server/manage/save
POST /server/manage/update
POST /server/manage/drop
POST /server/manage/batchDelete
POST /server/manage/batchUpdate
POST /server/manage/copy
POST /server/manage/resetTraffic
POST /server/manage/batchResetTraffic
POST /server/manage/sort
GET  /server/manage/generateEchKey?public_name=xxx
```

### 分组

```text
GET  /server/group/fetch
POST /server/group/save
POST /server/group/drop
```

### 路由规则

```text
GET  /server/route/fetch
POST /server/route/save
POST /server/route/drop
```

### 插件 `ET.*`

```text
GET  /plugin/types
GET  /plugin/getPlugins
POST /plugin/install
POST /plugin/uninstall
POST /plugin/enable
POST /plugin/disable
POST /plugin/upload
POST /plugin/delete
POST /plugin/upgrade
GET  /plugin/config?plugin_name=xxx
POST /plugin/config
```

还包含通用 CRUD：

```text
ET.crudList(api, params)
ET.crudSave(api, data)
ET.crudDelete(api, id)
```

### 统计

```text
GET /stat/getOrder
GET /stat/getStats
ANY /stat/getStatUser
GET /stat/getRanking
GET /stat/getTrafficRank
GET /stat/getServerLastRank
GET /stat/getServerYesterdayRank
GET /stat/getStatRecord
GET /stat/getOverride
```

### 审计日志

```text
GET /system/getAuditLog
```

---

# 3. 管理面板组件分析

> 原始记录写作“20 个组件”，但实际汇总表包含 22 个组件。本文按 **22 个已识别组件** 统计。

## 3.1 配置类页面

### `GGt` — `/config/subscribe-template`

- 外壳较简单；
- 主要逻辑在 `cZt`；
- `try_out_plan_id` 来源于套餐接口；
- 结构与系统配置页面高度复用。

### `dZt` — `/config/system`

主要字段约 13 个：

```text
app_name
description
app_url
force_https
logo
subscribe_url
tos_url
stop_register
ticket_must_wait_reply
try_out_plan_id
currency
currency_symbol
...
```

特征：

- `react-hook-form + zod`
- `mode: "onBlur"`
- 自动保存
- `debounce(1000ms)`
- `FT.isEqual` 判断是否真正变化
- 成功提示 `common.autoSaved`

### `pZt` — `/config/system/safe`

约 21 个字段，是配置页中条件逻辑最复杂的一类。

条件渲染包括：

- `captcha_type == recaptcha-v3` 才显示 `score_threshold`
- 开启邮箱白名单才显示 suffix
- 开启注册 IP 限制才显示 count / expire
- 开启密码限制才显示 count / expire
- 支持 reCAPTCHA v2 / v3 / Turnstile

### `vZt` — `/config/system/subscribe`

约 10 个字段。

特征：

- 订阅设置；
- 流量重置策略 5 选 1；
- `subscribe_path` 等路径设置；
- 自动保存模式与 system 页一致。

### `wZt` — `/config/system/invite`

- 外壳简单；
- 逻辑主要在 `xZt`；
- 邀请相关字段独立维护。

### `NZt` — `/config/frontend`

约 4 个字段。

与其他配置页不同：

```text
mode: "onChange"
```

即每次修改就触发保存。

另一个显著特征：

```text
成功提示为硬编码中文“更新成功”
```

而不是统一 i18n 的 `common.autoSaved`。

### `IZt` — `/config/server`

主要包含：

```text
server_token
pull interval
push interval
ws enable
ws url
```

特征：

- 两个配置查询并行；
- 包含测试邮件 / 测试逻辑；
- 使用对话框展示发送详情；
- 复杂度高于普通配置页。

### `xYt` — `/config/email`

页面使用 Tab：

```text
邮件配置
邮件模板
```

SMTP 字段：

```text
email_host
email_port
email_encryption
email_username
email_password
email_from
```

特征：

- `MZt` + `bYt`
- `remind_mail_enable` 更改时立即保存
- 支持测试发送邮件

### `kYt` — `/config/telegram`

非常简单：

- 标题；
- 描述；
- 分隔；
- `SYt` 子组件；
- 外壳本身几乎无业务逻辑。

### `DYt` — `/config/APP`

子组件：

```text
LYt
TYt
IYt
```

支持 Win / Mac / Android 下载配置：

```text
windows.version
windows.download_url
mac.version
mac.download_url
android.version
android.download_url
```

`IYt` 为动态配置渲染器，可处理：

```text
input
textarea
select
yaml
json
```

### `_Qt` — `/config/payment`

原 bundle 中该页面本身是 **静态 Skeleton 占位页**。

也就是说：

- `_Qt` 默认导出主要渲染多个 `q$t` Skeleton；
- 没有直接实现完整支付配置管理；
- bundle 中虽然存在支付 API 和通用配置弹窗，但 `_Qt` 本身未挂载完整 CRUD。

可复用的 `bQt` 与 `IYt`：

- `bQt`：插件 / 动态配置弹窗；
- `IYt`：动态字段渲染；
- `ET.getPluginConfig()`
- `ET.updatePluginConfig()`

### `g1t` — `/config/plugin`

插件管理中心。

核心能力：

- Tab：`all / feature / tool`
- 搜索；
- 安装状态筛选；
- 类型筛选；
- 插件卡片；
- 上传插件；
- Readme 弹窗；
- 配置弹窗；
- 安装 / 卸载 / 启用 / 禁用 / 删除 / 升级等操作。

插件缓存 key：

```js
Wlt = ["pluginList"]
```

所有变更后统一：

```js
invalidateQueries({ queryKey: Wlt })
```

### `E1t` — `/config/theme`

主题管理页面。

功能：

- 主题卡片网格；
- 主题预览；
- 多图左右切换；
- 删除确认；
- 切换主题；
- 主题动态配置；
- 上传主题。

当前激活主题不能删除。

配置弹窗 `k1t`：

- 打开时通过 `VL(theme)` 获取配置；
- `react-hook-form` 构造动态表单；
- `S1t` 根据字段类型渲染 input / textarea / select；
- 提交调用 `WL(theme, data)`。

### `G2t` — `/config/notice`

这里存在逆向记录上的命名混淆。

从实际逻辑看，`G2t` 内部复用了文章 / 内容管理表格，具备：

- 搜索；
- 分类筛选；
- 拖拽排序；
- Markdown 编辑；
- show 开关；
- CRUD 对话框；
- category / language / body 等文章字段。

因此它与 knowledge 实现高度重叠，实际用途需结合运行页面再次确认。

### `n4t` — `/config/knowledge`

极简外壳：

- 标题；
- 描述；
- skeleton；
- 直接复用 `t4t` 表格。

大部分业务逻辑都在与 `G2t` 共用的表格实现中。

### `r4t` — `/plugins/:pluginCode/*`

这是管理端最有代表性的 **条件渲染工厂**。

根据插件元数据动态决定页面类型：

1. 概览页；
2. settings 页面；
3. 通用 CRUD 表格；
4. admin_menu 页面；
5. 404；
6. 禁用提示。

判定依据包括：

```text
config
admin_crud
admin_menu
readme
is_enabled
```

插件 CRUD query key 大致为：

```js
["pluginCrud", pluginCode, api.list, page, sort, search]
```

变更后：

```text
refetch current CRUD
+
invalidate pluginList
```

---

## 3.2 Server 页面

### `J5t` — `/server/manage`

节点管理。

核心能力：

- TanStack Table；
- 节点列表；
- 分组过滤；
- 机器过滤；
- URL 参数 `machine_id`；
- 表格模式；
- 拖拽排序模式；
- 自动刷新。

查询策略：

```text
节点列表：30 秒自动刷新
机器列表：staleTime 30 秒，refetch 60 秒
默认 pageSize：500
```

核心 API：

```text
$L()
iD()
DT()
QL(saveOrder)
```

### `B3t` — `/server/machine`

机器管理，是当前已识别页面中复杂度最高之一。

包含：

- 机器 CRUD；
- Token 创建；
- Token 重置；
- Token 18 秒自动隐藏；
- 安装命令；
- 复制按钮；
- 绑定节点；
- 已绑定节点表格；
- 机器历史；
- CPU / MEM / DISK；
- 网络信息；
- Recharts 折线图；
- 时间范围切换；
- 图表系列开关。

相关子组件包括：

```text
I3t Token
R3t Install Command
O3t Bind Nodes
M3t Related Nodes
E3t Load Chart
L3t Summary
D3t Metric Card
```

机器 query 策略：
```text
staleTime: 30s
refetchInterval: 60s
refetchOnMount: always
refetchOnWindowFocus: always
```

### `/server/group` 与 `/server/route`

原始 bundle 的压缩变量名与“旧称”存在冲突，建议以后按路由语义重新命名源码组件。

路由规则表单字段：

```text
id
remarks
match
action
action_value
```

`match`：

- textarea 输入；
- 按换行切分；
- 过滤空串；
- 存为数组。

`action`：

```text
block
dns
direct
proxy
```

仅当：

```text
dns
proxy
```

时显示 `action_value`。

---

## 3.3 Finance 页面

### Plan Manage

套餐管理复杂度较高。

功能包括：

- 套餐 CRUD；
- 多周期价格；
- 折扣；
- 自动价格计算；
- Markdown 内容；
- 预览；
- 标签多选；
- 分组；
- 拖拽排序。

周期：

```text
monthly
quarterly
half-yearly
yearly
two-yearly
three-yearly
onetime
reset-traffic
```

价格逻辑大致为：

```text
基础月价 × 月数 × 折扣系数
```

支持多个折扣档位，例如：

```text
1.00
0.95
0.90
0.85
0.75
```

### `d6t` — Order Manage

订单管理。

创建订单字段：

```text
email
plan
period
amount
```

金额后端以“分”为单位，前端展示时除以 100。

列表包含约 11 列：

- trade_no；
- 类型；
- 套餐；
- 周期；
- 金额；
- 状态；
- 状态操作；
- 佣金余额；
- 佣金状态；
- 佣金操作；
- 创建时间。

订单状态：

```text
PENDING
PROCESSING
COMPLETED
CANCELLED
DISCOUNTED
```

订单类型：

```text
NEW
RENEWAL
UPGRADE
RESET_FLOW
```

佣金状态：

```text
PENDING
PROCESSING
VALID
INVALID
```

### `L6t`

`/finance/order` 的简单外壳之一。

页面本身只处理：

- 标题；
- 描述；
- skeleton；
- 将业务逻辑委托给 `E6t`。

---

# 4. 组件汇总与模块分类

## 4.1 已识别组件汇总

| # | 组件 | 路由 | 模块 | 复杂度 |
|---:|---|---|---|---|
| 1 | `GGt` | `/config/subscribe-template` | config | 简单 |
| 2 | `dZt` | `/config/system` | config | 简单 |
| 3 | `pZt` | `/config/system/safe` | config | 中 |
| 4 | `vZt` | `/config/system/subscribe` | config | 中 |
| 5 | `wZt` | `/config/system/invite` | config | 简单 |
| 6 | `NZt` | `/config/frontend` | config | 简单 |
| 7 | `IZt` | `/config/server` | config | 稍复杂 |
| 8 | `xYt` | `/config/email` | config | 中 |
| 9 | `kYt` | `/config/telegram` | config | 简单 |
| 10 | `DYt` | `/config/APP` | config | 中 |
| 11 | `_Qt` | `/config/payment` | config | 占位 |
| 12 | `g1t` | `/config/plugin` | config | 复杂 |
| 13 | `E1t` | `/config/theme` | config | 复杂 |
| 14 | `G2t` | `/config/notice` | config | 复杂 |
| 15 | `n4t` | `/config/knowledge` | config | 简单外壳 |
| 16 | `J5t` | `/server/manage` | server | 复杂 |
| 17 | `B3t` | `/server/machine` | server | 超复杂 |
| 18 | 压缩变量冲突 | `/server/group` | server | 中 |
| 19 | 压缩变量冲突 | `/server/route` | server | 中 |
| 20 | Plan Manage | `/finance/plan` | finance | 复杂 |
| 21 | `d6t / L6t` | `/finance/order` | finance | 复杂 |
| 22 | `r4t` | `/plugins/:pluginCode/*` | plugins | 复杂工厂 |

## 4.2 模块分类

### Config

```text
subscribe-template
system
system/safe
system/subscribe
system/invite
frontend
server
email
telegram
APP
payment
theme
notice
knowledge
plugin
```

### Server

```text
manage
machine
group
route
```

### Finance

```text
plan
order
```

### Plugins

```text
/plugins/:pluginCode/*
```

---

# 5. 跨组件实现模式

## 5.1 模式 A：配置表单页

典型结构：

```tsx
export default function ConfigPage() {
  return (
    <Layout>
      <TopBar />
      <Content>
        <header>
          <h2>{title}</h2>
          <p>{description}</p>
        </header>

        <FormSubComponent />
      </Content>
    </Layout>
  )
}
```

表单子组件典型模式：

```tsx
const { data } = useQuery({
  queryKey: ["settings", "module"],
  queryFn: () => getSettings("module"),
})

const form = useForm({
  resolver: zodResolver(schema),
  defaultValues: {},
  mode: "onBlur",
})

const mutation = useMutation({
  mutationFn: saveSettings,
  onSuccess: () => toast.success("autoSaved"),
})
```

保存策略：

```text
表单变化
→ debounce 1000ms
→ 与上次值 deepEqual
→ 有变化才 POST
```

## 5.2 模式 B：管理表格页

```tsx
<Toolbar>
  <Search />
  <Filter />
  <CreateButton />
  <PageSize />
</Toolbar>

<DataTable
  columns={columns}
  data={data}
/>

<Pagination />
```

高级页面进一步支持：

- server-side pagination；
- server-side sort；
- search；
- row selection；
- batch action；
- drag sort；
- auto refresh；
- URL query filter。

## 5.3 模式 C：纯外壳页

如：

```text
kYt
L6t
n4t
```

特点：

```text
Page
└── Header
└── Skeleton
└── RealSubComponent
```

业务全部下沉到共享子组件。

## 5.4 模式 D：Tab 页面

适用于：

```text
email
plugin
plugin route
```

结构：

```tsx
<Tabs>
  <TabsList />
  <TabsContent />
</Tabs>
```

## 5.5 模式 E：插件条件渲染工厂

```tsx
if (!plugin) return <NotFound />
if (!plugin.is_enabled) return <Disabled />

if (plugin.admin_crud) return <CrudPage />
if (plugin.admin_menu) return <MenuPage />

if (plugin.config) {
  return <OverviewAndSettings />
}

return <Overview />
```

这意味着 Xboard 插件系统并不要求每个插件都编写独立 React 页面，而是通过插件元数据描述后台 UI。

## 5.6 模式 F：动态配置渲染器

`bQt + IYt` 的本质是：

```text
后端返回 config schema
→ 前端遍历字段
→ 根据 type 选择组件
→ react-hook-form 管理状态
→ zod 校验
→ 提交完整 config
```

典型字段类型：

```text
input
textarea
select
boolean
json
yaml
nested object
array
```

这是插件配置、APP 动态配置、主题配置等功能可以复用的核心抽象。

---

# 6. API 测试结果

## 6.1 环境

```text
宿主机端口：7801
容器端口：7001
容器：xboard
镜像：ghcr.io/paimoncai/txboard-api:latest
框架：Laravel 11
来源：基于 V2board 二次开发
数据库：MySQL
数据库地址：host.docker.internal:3306
数据库名：xboard
Redis：/data/redis.sock
```

## 6.2 鉴权

实际确认：

```text
Laravel Sanctum Bearer Token
```

Token 形式：

```text
67|<token>
```

用户前端与管理端可共用同一套 Sanctum Token 体系。

## 6.3 API 前缀

用户前端：

```text
/api/v1/
```

管理端：

```text
/api/v2/de47dcba/
```

其中：

```text
de47dcba
```

为当前安装实例的唯一标识。

## 6.4 已测试接口

### 用户前端

```text
client/app/getConfig            403
client/app/getVersion           403
guest/comm/config               200
user/comm/config                403
guest/plan/fetch                200
passport/auth/login             400
passport/auth/register          422
passport/auth/token2Login       422
user/info                       200
user/getSubscribe               200
user/server/fetch               200
user/order/fetch                200
user/plan/fetch                 200
user/stat/getStat               200
```

### 管理端

```text
config/fetch                    403 Unauthorized
theme/getThemes                 200
plan/fetch                      200
server/group/fetch              200
server/manage/getNodes          200
```

结论：

- 路由识别基本正确；
- API 返回字段与前端 bundle 中的使用方式一致；
- `guest/comm/config`、`guest/plan/fetch` 可匿名访问；
- 管理端 path 需要实例唯一标识。

---

# 7. Scaffold 项目结构

## 7.1 用户前端

路径：

```text
/tmp/xboard-user-frontend
```

结构：

```text
xboard-user-frontend/
├── index.html
├── package.json
├── tsconfig.json
├── vite.config.ts
├── public/
│   └── favicon.svg
└── src/
    ├── main.ts
    ├── App.vue
    ├── router.ts
    ├── styles/
    │   └── main.css
    ├── api/
    │   ├── guest.ts
    │   └── user.ts
    ├── stores/
    │   └── auth.ts
    └── views/
        ├── Dashboard.vue
        └── Login.vue
```

当前已实现：

- Vue 3 入口；
- Router；
- Pinia scaffold；
- API 层；
- Session Token；
- 6 小时过期；
- Dashboard；
- Login。

待补页面：

```text
Register.vue
Plan.vue
Order.vue
Ticket.vue
Traffic.vue
Profile.vue
Knowledge.vue
Server.vue
Invite.vue
```

## 7.2 管理面板

路径：

```text
/tmp/xboard-admin-panel
```

结构摘要：

```text
xboard-admin-panel/
├── index.html
├── package.json
├── tsconfig.json
├── tsconfig.node.json
├── vite.config.ts
├── public/
│   └── favicon.svg
└── src/
    ├── main.tsx
    ├── App.tsx
    ├── router.tsx
    ├── styles/
    │   └── main.css
    ├── api/
    │   ├── client.ts
    │   ├── config.ts
    │   ├── theme.ts
    │   ├── plugin.ts
    │   ├── server.ts
    │   └── finance.ts
    ├── hooks/
    │   └── useQuery.ts
    ├── components/
    │   └── ui/
    │       ├── Sonner.tsx
    │       └── Toaster.tsx
    └── pages/
        ├── Layout.tsx
        ├── SignIn.tsx
        ├── config/
        ├── server/
        ├── finance/
        └── plugins/
```

已搭建的主要页面：

```text
SystemSettings
SafeSettings
SubscribeSettings
InviteSettings
FrontendSettings
ServerSettings
EmailSettings
TelegramSettings
AppSettings
ThemeSettings
NoticeSettings
KnowledgeSettings
PluginSettings
PaymentSettings

ManageNodes
MachineManage
GroupManage
RouteManage

PlanManage
OrderManage

PluginRoute
```

## 7.3 Vite API 代理

用户前端：

```ts
server: {
  port: 3000,
  proxy: {
    "/api": {
      target: "http://127.0.0.1:7801",
      changeOrigin: true,
    },
  },
}
```

管理端：

```ts
server: {
  port: 3001,
  proxy: {
    "/api": {
      target: "http://127.0.0.1:7801",
      changeOrigin: true,
    },
  },
}
```

---

# 8. 待解决问题与下一步

## 8.1 依赖

缺少：

```text
@hookform/resolvers
```

## 8.2 缺失组件

管理端：

```text
Sidebar.tsx
TopBar.tsx
```

Vue 用户端：

```text
Register.vue
Plan.vue
Order.vue
Ticket.vue
Traffic.vue
Profile.vue
Knowledge.vue
Server.vue
Invite.vue
```

## 8.3 TypeScript 问题

当前已知：

```text
AppSettings.tsx
  renderField 函数签名不匹配

useQuery.ts
  onSettled 参数不匹配

若干 unused variable
```

## 8.4 Monaco Editor

部分 JSON / YAML 配置依赖 Monaco Editor，但 scaffold 尚未完成对应封装。

## 8.5 开发服务器

当前 Vite 未处于运行状态。

建议顺序：

1. 补依赖；
2. 补 `Sidebar.tsx` / `TopBar.tsx`；
3. 修复 TypeScript；
4. 暂时创建 Vue 占位页面或移除无效路由；
5. `npm run typecheck`；
6. `npm run build`；
7. 启动 Vite；
8. 联调真实 Xboard API。

## 8.6 尚未重建的后台模块

可继续补：

```text
User Management
Statistics
Ticket
Gift Card
Coupon
Traffic Reset
Audit Log
Mail Template
Payment Methods
```

## 8.7 需要再次确认的行为

### 用户登录 Token 字段

当前登录接口测试返回：

```text
400 邮箱或密码错误
```

因此还没有通过成功登录响应确认 Token 字段名。

### Payment 页面

原始 admin bundle 中 `/config/payment` 是 Skeleton 占位，但同时存在完整 Payment API 封装。

因此有三种可能：

1. 页面功能由插件提供；
2. 当前构建版本暂未启用；
3. 支付配置被迁移到其他入口。

不能仅凭 `_Qt` 页面断言“管理端完全没有支付配置能力”。

### Notice / Knowledge 复用

`G2t` / `n4t` 之间存在明显的表格复用与变量压缩混淆，需要结合真实页面 UI 再做最终映射。

---

# 9. 关键技术结论

## 9.1 前后端架构

Xboard 当前至少有两个独立前端：

```text
用户端：Vue 3
管理端：React 18
```

二者共同访问 Laravel 11 后端。

## 9.2 API 分层

```text
/api/v1
  面向用户前端

/api/v2/<instance-id>
  面向管理端
```

管理端实例路径包含安装时生成的唯一标识。

## 9.3 鉴权

后端使用 Laravel Sanctum。

真实 Token 格式：

```text
67|<token>
```

前后端均通过：

```http
Authorization: Bearer <token>
```

进行鉴权。

## 9.4 管理端最重要的两个页面模式

### 配置页

```text
query
→ react-hook-form
→ zod
→ debounce
→ auto save
```

### 管理页

```text
query
→ TanStack Table
→ filter / sort / pagination
→ dialog CRUD
→ mutation
→ invalidate / refetch
```

## 9.5 插件系统是动态后台框架

插件并不只是后端扩展，它还可以通过元数据描述后台页面：

```text
admin_crud
admin_menu
config
readme
```

管理端 `r4t` 根据这些元数据动态生成页面。

这意味着在重构时，可以将插件后台抽象成统一的 Schema-driven Admin UI。

## 9.6 动态配置是可复用核心

`IYt` / `bQt` / `k1t` 体现出一个很重要的设计：

```text
配置 schema
→ 动态表单
→ 自动校验
→ 自动保存
```

未来重构时，可以统一：

```text
Theme Config
Plugin Config
APP Config
Node Config
Payment Config
```

为同一套 Schema Form Engine。

## 9.7 节点机器页是最值得优先复刻的复杂页面

`/server/machine` 集中体现：

- CRUD；
- Token；
- 实时状态；
- 节点关联；
- 安装命令；
- 指标卡；
- 历史数据；
- 图表。

如果该页可以完整复刻，管理端其他大部分 CRUD 页面都能顺势完成。

---

# 10. 建议的重构路线

建议不要继续按“逐页面硬抄 bundle”的方式推进，而是先抽象底层共性。

推荐顺序：

```text
1. API Client
2. Auth
3. App Layout
4. Form Engine
5. DataTable
6. Dialog CRUD
7. Settings Auto Save
8. Plugin Schema Renderer
9. Server / Machine
10. Finance
11. User / Ticket / GiftCard / Coupon
12. Statistics
```

理想目录结构：

```text
src/
├── api/
├── auth/
├── components/
│   ├── form-engine/
│   ├── data-table/
│   ├── dialogs/
│   └── layout/
├── features/
│   ├── config/
│   ├── server/
│   ├── finance/
│   ├── users/
│   ├── tickets/
│   ├── plugins/
│   └── statistics/
├── hooks/
├── router/
├── schemas/
└── types/
```

如果最终目标是将 Xboard 管理端改造成长期可维护源码，最关键的不是逐行恢复压缩变量名，而是恢复：

```text
数据模型
API 语义
页面状态
组件边界
业务流程
```

只要这五层被准确重建，就不需要追求与原 bundle 一一对应。

---

# 11. 最终判断

目前已经完成的逆向信息，足以支撑：

- 重写用户前端；
- 重写管理端核心配置；
- 重写节点 / 机器管理；
- 重写套餐 / 订单；
- 重写主题系统；
- 重写插件管理；
- 建立插件动态 CRUD；
- 建立统一 API SDK。

下一阶段工作的重点，应从“继续阅读 bundle”转向：

```text
真实接口验证
+
schema 类型化
+
公共组件抽象
+
页面联调
```

也就是说，当前项目已经从“逆向分析阶段”进入了“源码重建阶段”。