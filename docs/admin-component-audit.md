# 管理端参考组件与页面全面审查

> 历史审查基线说明：2026-10-21 已更新参考至 `6cb42a9`，新旧差异及后续修复顺序见 [新版执行计划](admin-reference-update-plan.md)。本文旧状态不构成当前验收。

审查日期：2026-10-20。参考根目录：`../references/webb/xboard-admin-rebuild`。本报告整合 `artifacts/admin-reference-details/audit-{root,tables,business,shell}.json` 四组逐文件记录。每条参考路径均相对参考根目录，TXBoard 路径均相对本仓库。

“已审查”表示读过参考证据并与当前实现及契约对照，不表示已完成参考等价、浏览器验收或发布。文中“本轮补齐”是当前工作区实现状态，不表示已经提交、合并或上线。源码清单与具体参考行号见上述 JSON；报告围绕页面归并同一功能的原件提取文档与 scaffold 重建源码，保留两类证据的差异。

## 逐文件覆盖核对

参考项目自有文件共 141 个，排除依赖、构建输出和 Git 内部目录。四组分工分别为网络/元数据 35、表格/用户/财务 37、业务/配置 44、外壳/语言 25；合并后缺失 0、重复 0，141 个文件的 SHA-256 均与审查起始清单一致。核对结果与每个文件的 TXBoard 对应路径、证据、缺口见 `artifacts/admin-reference-details/audit-coverage.json`；起始清单 `audit-inventory.json` 的 review 状态已同步。

全部逐文件记录也保存到 [逐文件审查清单](admin-reference-file-audit.md)，包含各文件依据、TXBoard 对应实现及审查时点结论，可随文档保留，不依赖本地 artifacts 目录。

元数据另核对 266 个组件和 33 棵递归树；锁文件按结构读取全部 342 条 package 记录。这里的逐文件覆盖范围是参考项目 141 个自有文件，不能扩张为 TXBoard 全仓库、后端或所有运行场景的逐文件验收。

## 当前判断

- 管理端已经具有真实 Core 数据与写入流程，但仍有显著的页面、字段、交互及统计能力缺口，不能声称“全面复刻完成”。
- 节点完整创建/配置编辑及服务器创建、凭据/安装/负载历史缺失。后端已有部分领域实现，缺的是当前统一 Core 操作契约与接线；应复用后端实现后接真实表单，详见 `admin-network-component-audit.md`。
- 仪表盘的四项资源总数与最近订单样本，不等价于参考八指标、全站收入/佣金统计与用户/节点流量榜。当前 Core 没有对应权威全站统计操作。
- 用户页面已有单用户创建。缺口是高级条件、批量创建及参考的大量身份/订阅/财务编辑能力，不能把“缺批量创建”写成“不能创建用户”。
- 订单关闭/重试不是直接标记付款、分配订单或修改佣金状态；当前 Core 未开放这些参考写操作。
- 礼品卡采用资金批次、预留、兑换与退回的资金模型；参考采用奖励模板与生成兑换码模型。两者不能用四个外观相似的 tab 强行等同。
- 订阅模板整页、站点前端外观配置、主题/插件安装升级生命周期与插件运行时 schema CRUD，当前存在完整页面或 Core 契约缺口。

## 外壳、登录与仪表盘

### 仪表盘 `/`

参考：`pages/dashboard.md`、`scaffold/src/pages/dashboard/index.tsx`。TXBoard：`web/admin/src/native/OverviewPage.tsx`、`overviewCharts.ts`、`QueuePage.tsx`。

- 已覆盖：账户/订单/节点/服务器四项资源总数；按币种展示的最近订单样本收入趋势、订单状态环图；节点历史流量；最近订单和节点概况；加载、错误、手动刷新及统计口径说明。
- 本轮补齐：浮层交互修复应用于仪表盘统计口径说明；现有样本图表仍明确标注范围。
- 前端缺口：参考的八项统计卡、收入金额/数量切换、收入与佣金双序列/汇总、7/30/90/180/365 天及自定义日期、节点/用户排名、嵌入式队列监控及失败详情弹窗均未形成对应页面结构。独立队列页已有部分功能，不能算嵌入布局一致。
- Core 缺口：无 `getStats/getOrder/getTrafficRank` 等价操作；缺当日/月收入及增长、月新增用户及增长、活跃/在线用户设备、全站上传下载量、日期维度收入佣金聚合与前期排名。最近最多 100 条订单不能推断全站收入；前 100 节点中的累计前五也不能称全站日期流量榜。
- 当前历史流量契约仅支持 7/14/30 天、1–20 个节点 ID，口径为 UTC 已结算原始用量。不可包装成任意日期、实时采样或全站排名。
- 原件与 scaffold：原件 `uqt/eKt` 提取只有截断声明，部分正文混入图表库源码；八卡和完整排名细节由 scaffold 补充。scaffold 数值缺失时补零、空排名持续“加载”、金额固定人民币等处理不能作为 TXBoard 的要求。

### 登录 `/sign-in`

参考：`pages/sign-in.md`、`scaffold/src/pages/auth/sign-in.tsx`。TXBoard：`web/admin/src/pages/SignInPage.tsx`、`components/CaptchaWidget.tsx`、`native/session.ts`。

- 已覆盖：居中登录卡、邮箱/密码图标、主题切换、密码显示、表单校验、真实管理员会话、验证码、错误/等待状态和受限站内回跳。验证码配置加载失败时暂停提交并支持重试。
- 前端缺口：语言选择与管理员重置帮助/复制说明未实现；站点可配置标题/描述、平面卡片与当前品牌/渐变玻璃卡片存在视觉差异。
- Core/平台边界：参考 Sanctum token 存储与 PHP artisan 命令属于旧后端，不能直接替换 Core 会话或把旧命令标为 TXBoard 可用恢复命令。当前管理员操作清单无自助重置操作。
- 原件与 scaffold：原件证据包含重置说明；scaffold 自己注明 `/passport/auth/forget` 自助流程是补充实现，并有手贴验证码 token 输入。不能把这些扩展当作原版必需行为；也不能复制其登录请求遗漏验证码、配置失败静默放行等问题。

### 导航、工具栏与账户菜单

参考：`scaffold/src/components/layout/{app-shell,header-widgets,layout,nav-config}.tsx`，其中 `nav-config` 实际扩展名为 `.ts`。TXBoard：`web/admin/src/components/layout/{AdminLayout,Sidebar,TopBar}.tsx`、`navigation/core.ts`、`router.tsx`。

- 已覆盖：分组折叠、活动路由、移动导航、侧栏宽度/分组偏好持久化；Ctrl/Cmd+K 搜索、方向键/Enter、空结果；路由切换后的焦点和滚动恢复。
- 本轮补齐：账户按钮打开真实身份菜单，读取 `identity.admin.get`，提供系统设置、登录会话、明确退出、失败重读；主题选择后关闭。浮层按视口翻转/限制位置与高度，关闭焦点恢复，嵌套 Escape 优先关闭子层，Modal 描述绑定完善。
- 前端缺口：全局语言切换及翻译管线、站点品牌/版本页脚未覆盖；参考顶部工具条与当前侧栏底部工具区、默认紧凑栏存在明确布局差异。通用 Radix menu/roving-focus API 未实现，当前账户层是可聚焦 Popover，不能称完整 DropdownMenu 等价。
- 原件与 scaffold：导航注释声称 22 项，但数组实际 18 项；UserNav 用 token 前缀 `atob` 猜邮箱不是可信身份来源。当前保留真实 Core 身份。scaffold 的快捷键文字也不证明设置/退出组合键实际有效。

### 路由与错误页面

参考：`scaffold/src/router.tsx`。TXBoard：`web/admin/src/router.tsx`、`native/routes.ts`、`lib/basePath.ts`。

- 已覆盖：核心资源页面、受保护布局、basename、详情路由，以及额外审计/会话/邮件模板/队列入口。
- 前端缺口：参考 `/user/traffic-reset-logs`、若干 `/config/system/*` 深链接、独立 `/404`、`/500`、`/503` 未全部建立原路径别名或专页；部分功能在不同原生路径。参考 hash router 与当前 browser router 的部署地址语义不同。
- Core 缺口：插件通配路径现重定向模块页，不能据此认为插件运行时页面已实现。

## 配置与内容页面

### 系统站点设置 `/config/system`

参考：`pages/config-system.md`、`scaffold/src/pages/config/system/index.tsx`。TXBoard：`native/SettingsPage.tsx`、`ConfigurationPage.tsx`、`configurationPresentation.ts`、`contracts/core-api/admin-settings.v1.json`。

- 已覆盖：`app_name/app_description/app_url/logo/tos_url/stop_register`，按组读取、帮助文字、显式编辑/取消/保存、修改计数、凭据不回显及版本冲突处理。
- 前端缺口：URL、Logo/下载地址示例与说明可继续完善；当前只读值网格加编辑表单，与参考横向字段行有差异；手机分类导航仍为链接集合。
- Core 缺口：`force_https/subscribe_url/ticket_must_wait_reply/try_out_plan_id/try_out_hour/currency/currency_symbol` 不在当前写 schema，不能声明站点设置全字段覆盖。
- 原件与 scaffold：共享 `settings.tsx` 把 `$f` 注释成 SaveButton，但原件 `pages/config-system.md:280` 的 `$f` 实为 Separator。原件多组设置自动保存，当前显式提交保留 Core 版本/授权时点，不应为了外观改成自动写。

### 安全设置 `/config/system/safe`

参考：`pages/config-system-safe.md`、`scaffold/src/pages/config/system/safe.tsx`。TXBoard：`native/ConfigurationPage.tsx` 的 `safe` 分组。

- 已覆盖：注册/频率限制、域名校验、人机验证及对应密钥，凭据留空保留原值和 CAS 冲突重编。
- 前端缺口：当前同时平铺多个验证码提供方字段；可按当前选择条件呈现，保留未显示提供方的已存凭据。
- Core 缺口：`safe_mode_enable/secure_path/recaptcha_v3_score_threshold` 未开放。旧 `password_limit*` 与当前 `login_limit*` 有语义覆盖，不应重复添加旧字段。

### 订阅设置 `/config/system/subscribe`

参考：`pages/config-system-subscribe.md`、`scaffold/src/pages/config/system/subscribe.tsx`。TXBoard：`native/ConfigurationPage.tsx` 的 `subscribe` 分组。

- 已覆盖：套餐变更、流量重置、新购/续费/变更三种订单事件配置。
- 前端缺口：业务友好选项文案仍可完善，提交值保持原契约。
- Core 缺口：`surplus_enable/subscribe_path/show_info_to_server_enable/show_protocol_to_server_enable` 未开放，尤其余额折抵涉及财务规则，不能仅添加开关。

### 订阅模板 `/config/system/subscribe-template`

参考：`pages/config-system-subscribe-template.md`、`scaffold/src/pages/config/system/subscribe-template.tsx`。TXBoard：无对应模板页，仅有普通订阅设置。

- 已覆盖：无六模板页面等价实现。
- 前端缺口：六客户端模板 tab、Monaco 语法编辑区、模板状态/保存流程整页未实现。
- Core 缺口：无 `subscribe_template_*` 字段及模板读取/写入、编译校验契约；需先确定模板所有者、错误/版本语义，再建立编辑器。语法通过与真实订阅输出验收须分别证明。
- 原件与 scaffold：scaffold 的 500px 编辑区与 1500ms 自动保存是其具体实现证据，不应绕过当前 Core 显式写入策略。

### 邀请与佣金 `/config/system/invite`

参考：`pages/config-system-invite.md`、`scaffold/src/pages/config/system/invite.tsx`。TXBoard：`native/CommissionsPage.tsx`。

- 已覆盖：单级 `rate_bps/hold_seconds` 佣金策略、版本、退款规则及转入钱包审核。
- Core/模型缺口：参考邀请强制模式、邀请码数量/有效期、首单返佣、三级分销、外部提现/银行渠道不等价于当前单级奖励与钱包模型；需明确新增业务模型，不能直接套字段。

### 节点服务配置

参考：`pages/config-system-server.md`、`scaffold/src/pages/config/system/server.tsx`。TXBoard：`/config/server`，`native/ConfigurationPage.tsx` 的 `server` 分组。

- 已覆盖：`server_pull_interval/server_push_interval/device_limit_mode`。
- Core 缺口：`server_token/server_ws_enable/server_ws_url` 未开放；节点部署和 Agent 通道须由各自权威入口提供，前端生成随机 token 不代表有效注册。
- 路径差异：参考 `/config/system/server` 与当前 `/config/server` 不同。

### 邮件与模板

参考：`pages/config-system-email.md`、`scaffold/src/pages/config/system/email.tsx`。TXBoard：`/config/email`、`/config/email/templates`，`native/ConfigurationPage.tsx`、`MailTemplatesPage.tsx`、`MailPreview.tsx`。

- 已覆盖：SMTP 配置、场景模板、正文/主题、保存/恢复默认/测试、变量复制；预览保留 sandbox 空权限、严格 CSP 与外部资源隔离。
- 本轮补齐：脏草稿切换/取消确认、源码选区插入变量、测试后保留草稿；对应针对测试通过。
- 前端缺口：变量含义/样例、样例替换预览、主题中插入变量仍缺；明确标注演示数据后可完善，不能冒充服务端实际值。
- Core 缺口：`remind_mail_enable` 与独立 SMTP 诊断操作未开放；发送模板测试不等于独立 SMTP 诊断。

### Telegram

参考：`pages/config-system-telegram.md`、`scaffold/src/pages/config/system/telegram.tsx`。TXBoard：`/config/telegram`，`native/ConfigurationPage.tsx` 的 `telegram` 分组。

- 已覆盖：机器人启用、token、自定义 Webhook、讨论群四字段；token 不回显。
- 前端缺口：填写说明与目标地址来源解释可完善。
- Core 缺口：无设置/诊断 Webhook 操作；保存地址不能显示成已向 Telegram 注册成功。

### 客户端 APP

参考：`pages/config-system-app.md`、`scaffold/src/pages/config/system/app.tsx`。TXBoard：`/config/app`，`native/ConfigurationPage.tsx` 的 `app` 分组。

- 已覆盖：Windows/macOS/Android 各版本和下载地址共六字段，平台分区、修改计数、保存错误。
- 差异：参考自动保存与当前显式按组版本提交不同；该写入策略差异不应当作 CSS 修复。

### 站点前端外观

参考：`pages/config-system-frontend.md`、`scaffold/src/pages/config/system/frontend.tsx`。TXBoard：无对应配置组，旧前端入口重定向主题管理。

- 前端/Core 缺口：侧栏样式、顶部样式、配色和登录背景地址四项持久化配置均未建立；当前 ThemeToggle 只是本机管理端偏好。
- 原件与 scaffold：该 scaffold 部分字面文案编码异常，不能直接复制。

### 公告 `/config/notice`

参考：`pages/config-notice.md`、`scaffold/src/pages/config/notice.tsx`、共享 EditorDialog。TXBoard：`native/ContentPage.tsx`、`components/ui/MarkdownEditor.tsx`、`TagInput.tsx`。

- 已覆盖：新增/编辑前读取完整正文、Markdown/预览、标签、封面、可见性、全量排序、审计，以及 Core 的弹窗/受众/发布时间。
- 本轮补齐：共享 Markdown/标签与内容编辑细节已落入当前实现；以逐文件记录和实际源码为准。
- 前端缺口：当前页内编辑，与参考固定头脚、70vh 独立正文滚动的模态编辑不同；后续改层需同时保护草稿。
- 模型差异：参考删除与当前归档语义不应只因按钮文案接近就视作等价，恢复和可见性依 Core 规则。

### 知识库 `/config/knowledge`

参考：`pages/config-knowledge.md`、`scaffold/src/pages/config/knowledge.tsx`。TXBoard：`native/ContentPage.tsx`。

- 已覆盖：完整正文详情、分类/语言/Markdown、状态、排序、发布信息与审计。
- 前端缺口：语言仍为自由文本，可补常用语言名称选择并保留自定义；分类/显示状态筛选、排序工具直观性及模态编辑布局有差异。
- Core 边界：现有 list 查询能力以契约为准；若只能在当前页分类筛选须明确范围，不能当作全量服务端过滤。

### 支付配置 `/config/payment`

参考：`pages/config-payment.md`、`scaffold/src/pages/config/payment.tsx`。TXBoard：`native/PaymentMethodsPage.tsx`。

- 已覆盖：渠道列表、提供方动态表单、币种摘要、商户密钥不回显、编辑焦点、版本/幂等及未知结果恢复。
- Core 缺口：参考非零费率、`notify_domain`、删除及专用排序接口未完整开放；保留当前零手续费约束，不可随意扩张 save payload。
- 原件与 scaffold：原件文档仅 106 行，`mQt/HGt` 签名截断；完整动态字段来自 scaffold 补充，证据等级不同。

### 主题管理 `/config/theme`

参考：`pages/config-theme.md`、`scaffold/src/pages/config/theme.tsx`。TXBoard：`native/ModulesPage.tsx` 的 `kind=theme`。

- 已覆盖：基本信息、版本/状态、详情和操作历史。
- 前端/Core 缺口：截图轮播、ZIP 拖放上传、schema 配置、激活与删除均未建立；Core 也不返回对应截图/config schema 或写操作。
- 概念边界：本机 light/dark/system 不等于主题市场当前主题或用户站点主题。

### 插件管理 `/config/plugin`

参考：`pages/config-plugin.md`、`scaffold/src/pages/config/plugin.tsx`。TXBoard：`native/ModulesPage.tsx` 的 `kind=plugin`。

- 已覆盖：名称/类型/版本/来源/安装/启用/健康状态、详情、操作记录、失权清理。
- Core 缺口：当前仅 `modules.list/get/operations.list`；上传、安装、启停、升级、卸载与 schema 配置写操作缺失。README/作者/链接未返回时也不能使用示例数据冒充。

### 插件运行时与演示页面

参考：`pages/plugins-plugincode.md`、`pages/config-plugin-{menu,crud}-demo.md`、`scaffold/src/pages/plugins/index.tsx` 及两演示页。TXBoard：`ModulesPage.tsx`、`router.tsx`，无运行时 schema CRUD 页。

- 前端缺口：插件菜单贡献声明/预览、受控运行时路由、schema CRUD 渲染器均未实现；模块元数据详情不是运行时执行。
- Core 缺口：缺菜单贡献协议、README/config schema、受权限与输入校验控制的插件 CRUD 契约；不得将插件提供的任意 URL 直接接成管理操作。
- 原件与 scaffold：原件 `i4t` 有真实 schema list/save/delete 请求，scaffold 明说未实现，只打印 JSON；menu/crud demo 的本地 seed/state/延时保存是演示，不是持久化业务证据。

## 用户、工单与财务页面

### 用户管理 `/user/manage`

参考：`pages/user-manage.md`、`scaffold/src/pages/user/manage.tsx`。TXBoard：`web/admin/src/pages/users/{UsersPage,UserDetailPage}.tsx` 及原生账户/订阅操作。

- 已覆盖：真实账户列表/详情、单用户创建、冻结/解冻、邮箱/状态查询、当前订阅与生命周期操作；列表/详情显示权限允许的数据。
- 本轮补齐：移动主卡片、筛选条/逐项删除筛选、页大小切换回第一页、加载和失权选择清理、展示字段整理已在当前工作区实现。
- 前端缺口：高级条件构建器、字段选择/分面与丰富列表列未等价。当前邮箱/状态过滤不能声称覆盖任意 `gt/lt/contains/date/boolean` 规则。
- Core 缺口：账户列表缺订阅流量、设备、套餐/权限组/速率、余额与佣金等完整列表字段；不能逐行拼详情请求伪造高效列表。批量生成、CSV、群发、用户删除/身份编辑/重置密钥、姓名手机号/邀请人/员工角色/备注、钱包佣金及订阅额度直接改写、高级过滤未建立对应管理员操作。
- 语义边界：详情中的授权订阅生命周期，不等价于参考 UserDialog 直接写 `email/transfer_enable/is_admin/is_staff`。

### 订单管理 `/finance/order`

参考：`pages/finance-order.md`、`scaffold/src/pages/finance/order.tsx`。TXBoard：`web/admin/src/pages/finance/OrdersPage.tsx`。

- 已覆盖：用户 ID、订单号、状态查询；完整套餐/周期、精确金额、创建/支付时间；详情账务分录、时间线、事件/审计；受控关闭和重试。
- 本轮补齐：移动主卡片、可删除筛选、页大小切换回第一页，以及列表上下文/详情展示整理已在当前工作区实现。
- Core 缺口：无管理员分配订单、直接标记付款、修改佣金状态、完整 type/period/commission 排序过滤、复合条件查询。不可把 close/retry 改文案伪装成 mark-paid。
- 原件与 scaffold：原件 E6t 从 URL 读取的条件比重建 FinanceOrder 更完整；不能只按 scaffold 缺失断定原件没有。当前 URL page/per_page 与筛选语义须独立检查，不能让过期页码隐藏新筛选结果。

### 套餐管理 `/finance/plan`

参考：`pages/finance-plan.md`、`scaffold/src/pages/finance/plan.tsx`。TXBoard：`native/CatalogPage.tsx`、`commerce-format.ts`。

- 已覆盖：authoritative get 完整条款、Plan/Revision/Offer 分离、标签、访问额度、精确金额、八周期价格、历史与可用性修改。
- 本轮补齐：按当前流量/限制生成套餐说明模板，有正文时确认替换；相关针对测试通过。
- 前端缺口：权限组仍用 ID 输入，可利用 `network.groups.list` 补名称选择；组内新增须另核写权限。周期价一键清空与明确公式的批量草稿填充缺失。
- Core/模型差异：旧套餐 `force_update`、旧 ID 原地改价与拖动 sort 不等价于不可变修订；不得覆盖旧订单冻结条款。批量草稿计算也须保持分单位精度，不照搬浮点折扣公式。

### 优惠券 `/finance/coupon`

参考：`pages/finance-coupon.md`、`scaffold/src/pages/finance/coupon.tsx`。TXBoard：`native/CouponsPage.tsx`。

- 已覆盖：真实列表、创建、停用、页大小，折扣/门槛、全局/每用户用量、起止时间、限定商品、状态和复制；权限与未知结果恢复存在。
- 前端缺口：移动主卡片/横向停用入口、固定操作列、多选商品/周期、日期快捷范围及状态提示。桌面列表可操作不代表移动卡片体验已覆盖。
- Core 缺口：仅 page/per_page、最大 50；无参考 filter/sort、编辑/删除、批量创建/启用、CSV 导出与 plan/period 全套限制。当前创建后规则固定、只允许停用。
- 字段差异：百分比用 basis points，门槛用分，限定用 offer IDs；参考折/元/plan IDs 不能原样提交。

### 礼品卡 `/finance/gift-card`

参考：`pages/finance-gift-card.md`、`scaffold/src/pages/finance/gift-card.tsx`。TXBoard：`native/GiftCardsPage.tsx`。

- 已覆盖：单卡/资金批次分页、精确金额、兑换人/批次号/期限/状态、批次内资金口径及版本化撤销。
- 前端缺口：可将单卡/批次整理为有明确含义的 section/tab，并展示已有 `created_at/closed_at`；不应直接套参考四 tab 的名称。
- Core/模型缺口：无奖励模板、面额编辑、管理员生成兑换码/CSV、启用/批量开关、全站使用记录和全站模板统计。服务端保管的兑换码不能由后台读取。当前钱包充值/预留/兑换/退回模型与参考模板发码不同。
- 原件与 scaffold：参考部分所谓总数实为当前页数组计数，不能把这种展示迁移后称全站统计；当前四状态金额也只限各批次口径。

### 工单 `/user/ticket`

参考：`pages/user-ticket.md`、`scaffold/src/pages/user/ticket.tsx`。TXBoard：`native/TicketsPage.tsx`、`AttachmentsPanel.tsx`。

- 已覆盖：状态/用户/归属过滤、详情、消息游标、字节限制、认领/释放、回复/关闭、附件和审计，保留版本与幂等边界。
- 前端缺口：当前仍是页内长详情，固定回复区、独立会话滚动、侧栏切换与草稿保护未完成；本轮未实现工单会话弹窗。中文 IME 期间不应按 Enter 直接发送。
- 原件与 scaffold：原件提取中的 K8t 实为用户编辑片段，会话上下文也有截断；完整会话布局与自动滚动细节主要来自 scaffold。

### 流量重置日志 `/user/traffic-reset-logs`

参考：`pages/user-traffic-reset-logs.md`、`scaffold/src/pages/user/traffic-reset-logs.tsx`。TXBoard：`native/TrafficResetPage.tsx`，原生路径 `/user/traffic-reset`。

- 已覆盖：读取用户订阅并用 expected_version 执行流量重置的独立操作工具。
- 前端/Core 缺口：无历史日志页、来源/类型/时间范围查询或日志导出契约；操作工具不等价于日志页面，不能从单次 reset 响应重建历史。
- 原件与 scaffold：重建版部分筛选/小计只作用已取当前页，CSV 固定取 10000 条也不证明全量。旧 old_traffic 模型不能改动当前订阅生命周期边界。

### 队列监控 `/system/queue`

参考来源为仪表盘 `UGt/QueueMonitor`；TXBoard：`native/QueuePage.tsx`。

- 已覆盖：运行状态、可为空的观测指标、最近 30 条失败、失败详情、手动/自选 30 秒刷新、失权隐藏。
- Core 缺口：无参考 Horizon 全量分页、原始异常/载荷、暂停主进程数、吞吐/运行时间峰值、保留时段；不得用零补缺失值或显示不可提供的数据。

## 通用组件、语言与运行基础

三语言根目录/副本共六文件均做全命名空间和叶键结构核对：23 命名空间；zh-CN 2292、en-US 2287、ru-RU 2225 叶键。英文缺中文 34 键，俄文缺 97 键；每语言根目录与 scaffold 副本逐字节相同。完整缺失/额外键在 `audit-shell-locales.json`。TXBoard 当前无三语言资源、检测顺序或切换管线；不是加一个语言按钮便完成。

| 参考组件/基础 | TXBoard 对应 | 覆盖及缺口 |
| --- | --- | --- |
| data-table、table、checkbox | `components/ui/DataTable.tsx`、原生表格/checkbox | 语义表格、列宽、当前页选择/半选、加载失权清理、首尾固定列及单份移动卡片/分页已有；多固定列累计偏移、通用 draggable、caption API 未等价。 |
| filter-builder、column-header | `components/ui/FilterBar.tsx`、列说明 Popover | 搜索/芯片/清除/逐项删除已有；高级规则、服务端排序及移动卡片字段说明仍缺。不能把当前页客户端过滤称为全量查询。 |
| pagination | 列表分页组件 | 有页码/大小/跳转/首尾；每页值受契约约束，不能照搬 500；删除后页码超末页的纠偏不是所有列表都已证明。 |
| dialog、popover、dropdown-menu | `Modal.tsx`、`Popover.tsx`、`useDialog.ts` | 本轮补浮层位置、子层 Escape、焦点恢复和描述；menuitem/roving focus 仍与 Radix dropdown 不同。 |
| calendar、date-range-picker | 原生 `datetime-local` 等 | 可输入日期/时间，未有通用双月范围日历、摘要浮层或快捷区间等价组件。 |
| input、textarea、label、switch、select | `components/ui/Field.tsx` 等 | 原生属性、label/hint/error、禁用与键盘操作已有；无同等 forwardRef/portal/item indicator API，自定义选项弹层外观不同。 |
| tag-input | `components/ui/TagInput.tsx` | 分隔/失焦提交、去重/删除及 IME 保护已有；优惠券多选商品等不是标签输入能自动替代。 |
| tabs | 当前页面 section | 尚无通用 Tabs 及礼品卡四业务 tab 等价；渲染 tab 前要先确认每个 tab 有真实数据契约。 |
| markdown-editor | `components/ui/MarkdownEditor.tsx` | 格式工具、编辑/分屏/预览、B/I 快捷键、maxLength/required 已有；保留 html:false 和安全链接策略，不能照搬 scaffold 的 html:true。未显式配置的插件不能仅凭编辑器库名推断已启用。 |
| config/form-dialog | `Modal.tsx` 与当前页内编辑区 | 现有 portal/focus/inert 可复用；ContentPage 尚无参考固定头脚和独立长正文滚动。表单改层必须保留权限、版本、草稿和未知结果恢复。 |
| alert、badge、button、card、separator | 原生语义和业务 CSS/组件 | 主要视觉职责已有，API 与变体不是一比一；状态颜色/密度需按页面截图检验，文件名存在不能证明全部调用等价。 |
| tooltip、scroll-area | 可点击 Popover 与各 overflow 容器 | 触摸可操作说明已有；hover/focus 延时 Tooltip 与统一 overscroll API 未等价。参考 ScrollArea 本身也只是 overflow div。 |
| sonner | `main.tsx` | 原件根 Toaster 为 top-right richColors；scaffold 独立包装器 top-center/closeButton 与根挂载不一致。当前 top-right 与原件根一致，不应只依包装器判定位置缺口。 |
| `_b/shared.tsx` | Core SDK/session/workflow/格式化 | 精确字符串金额、版本/幂等恢复已有；无通用高级 facet、Blob 导出/通用拖拽。确认框关闭时点与参考 await action 不同，不能宣称异步错误展示一致。 |
| globals/theme provider | `design-tokens.css`、`admin-theme.css`、`lib/useTheme.ts` | 三态主题与媒体/跨 tab 同步已有；参考黑白主色/8px 圆角/64px 顶栏与当前蓝色/18px 卡片/紧凑栏存在视觉差异。 |
| api/http/global types | `native/api.ts`、`native/session.ts`、Core SDK | 真实权限、输入校验、幂等与会话清理保留；旧 secure_path REST、本地三天 token TTL 不可当作统一替换方案。 |
| main/query | `main.tsx`、`native/queryClient.ts` | StrictMode、路由、统一查询和提示已有；scaffold main 新建另一个 query client，未挂其导出配置和 ThemeProvider，是重建集成缺陷。 |

## 网络页面与元数据审查

完整字段和协议矩阵见 [节点与服务器专题审查](admin-network-component-audit.md)。已读原件四页及 scaffold 节点 4500 行、服务器 2121 行、分组及分组表单 428 行、路由 576 行。

- 节点创建/编辑：共用名称、地址、两类端口、监听、父节点、服务器、倍率与时间段、权限组/路由、流量上限、标签和高级 JSON 等字段；11 种协议面板分别具有 TLS/Reality、传输、带宽、混淆、多路复用等条件。当前 `network.nodes.patch` 仅支持显示、启用与服务器绑定，不能承担完整表单保存。
- 服务器创建/详情：参考有 Token 主动读取及 180 秒隐藏、确认轮换、安装命令、1/6/12/24 小时 CPU/内存/磁盘/网络曲线和关联节点操作。TXBoard 尚无对应 Core 操作和界面；管理启用不代表在线状态。
- 已有可用领域实现：`NetworkNodeAdminController` 的 save/replace/protocols 和 `NetworkMachineAdminController` 的 create/credentials/rotateToken/history。接入时需补统一投影、授权、幂等与凭据生命周期，不直接调用旧 REST。
- 本轮补齐：服务器绑定选择独立分页；500/403 后清空旧候选并禁止写入；补节点批量显示。操作完成回调核对挂载、视图代次和会话，避免迟到结果跳走新详情。服务器删除说明已按真实行为改为解绑后删除、保留节点。
- 分组与路由：已有真实 CRUD，路由具完整集合排序/模拟；仍缺名称选择、表单联动、多选 facets 和部分移动布局。节点排序 Core 已有，但需建立完整集合/并发变化语义，不能排序分页子集。

工具与 fixture 仅做源码/结构审查，未运行 OCI 拉取或参考写服务。重建稿写请求固定返回成功且不持久化，部分筛选排序被忽略；参考历史截图差异只比较原版与重建稿，不构成 TXBoard 运行或视觉验收。

## 验证与后续边界

2026-10-20 本轮最终本地验证：

- `npm run verify:web` 通过：130 个可达模块的前端边界检查、全部工作区类型检查、295 项测试（管理端 238 项/39 个文件）、管理端与用户端生产构建及资源预算检查。管理端初始 JS 137.4 KB / 150 KB，最大路由 JS 48.6 KB / 110 KB，最大 CSS 26.1 KB / 50 KB。
- `npm run test:browser` 五组浏览器回归共 57 项通过，包含权限撤回、原请求恢复、用户/订单详情、网络及财务操作和移动布局。
- `npm run test:dev-browser --workspace @txboard/admin` 的实际 Vite 启动、登录校验检查通过。
- `component-browser-checks.mjs` 在现有测试数据预览的 1440/390px 视口共 16 项通过：真实账户/主题层、移动列表单份 DOM、嵌套 Modal/Popover 边界、子层优先 Escape、焦点恢复、inert/滚动释放。临时组件夹具导入实际 Vite 组件；没有业务写入或真实 Core 转发。结果记录 6 次预览登录、16 次读取、0 页面异常、0 被阻止业务写入。
- `merge-audit.mjs` 核对 141 项审查状态、路径唯一性与参考哈希全部通过；`git diff --check` 通过。

完整日志位于 `artifacts/admin-reference-details/component-{verify-web,browser-regressions,dev-browser,browser-checks}.log`，额外浏览器结果为 `component-browser-checks.json`。这些证明当前工作区的本地前端行为，不覆盖全部缺失功能、真实 Core 写入、CI、提交合并或发布。其他页面的“已覆盖”仍包含源码/契约对照结论，不能扩张成所有页面运行验收。

后续优先级：先接入已有服务器创建/凭据领域实现及对应真实界面，再建立节点协议/完整保存契约并实现 11 协议表单；同时按独立页面补当前契约已支持的名称选择、移动卡片和条件表单。全站统计、订阅模板、主题/插件生命周期与礼品卡模型分别确认权威数据和业务语义后推进。浏览器逐页桌面/移动截图、键盘与嵌套层操作、错误和空态、服务端写入结果均须单独验收；没有这些证据，不应标为完整参考等价或发布就绪。
