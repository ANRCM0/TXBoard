# 参考管理端逐文件审查清单

> 历史审查基线说明：2026-10-21 已更新参考至 `6cb42a9`，新旧差异及后续修复顺序见 [新版执行计划](admin-reference-update-plan.md)。本文旧状态不构成当前验收。

日期：2026-10-20。参考根目录：`../references/webb/xboard-admin-rebuild`。

范围：141 个项目自有文件，排除 node_modules、dist 和 .git。四组记录已合并，缺失 0、重复 0，全部 SHA-256 与起始清单一致。

以下内容保存各文件的审查时点结论；后续已修正的问题以总报告和当前实现说明为准。源码审查不代表运行验收或参考功能等价。

总报告：[组件与页面审查](admin-component-audit.md)。节点/服务器：[字段与协议矩阵](admin-network-component-audit.md)。

## artifacts/manifest.json

Read immutable bundle/image provenance, stack and historical visual baseline.

- 状态：已审查；分工：root
- SHA-256：`19da47a79302e34a7a6736f36a698769bc71a5e46c3a972058440cb216fab870`
- TXBoard 对应：`web/admin/package.json`、`web/admin/vite.config.ts`、`package-lock.json`

依据：

- upstream_commit 4f48e61a2cbc6db5338872b6bdb45ef954ec1256; admin submodule ef5f43da335092cbff8fdf0ad7ff9b4d92d7d0d7.
- bundle 6534570 bytes; CSS 283863 bytes; digest values retained in source.

对照结论：

- Historical reference provenance only. Its latest image label is not evidence of current upstream latest.
- The 4.51% / 0.77% ratios compare original Xboard and its reconstruction, not TXBoard. No TXBoard pixel parity follows.

## locales/en-US.json

Parsed every namespace and leaf key: 23 namespaces, 2287 leaf keys.

- 状态：已审查；分工：shell
- SHA-256：`8cab801e16b3f3d6a5729b4c1da74ec71f25e2e6a091e592d8de808a1add8189`
- TXBoard 对应：`web/admin/src/components/layout/TopBar.tsx`、`web/admin/src/pages/SignInPage.tsx`、`web/admin/src/native/OverviewPage.tsx`

依据：

- All 23 namespaces inspected structurally; full missing/extra lists in audit-shell-locales.json.
- Root locale and scaffold locale are byte-identical for this language.

对照结论：

- Locale coverage gap: TXBoard admin uses Chinese literals and has no en-US/ru-RU language selector or translation resources.
- Reference resource quality: 34 Chinese leaf keys absent; 29 extra keys compared with zh-CN. Do not treat reference translations as complete.

## locales/ru-RU.json

Parsed every namespace and leaf key: 23 namespaces, 2225 leaf keys.

- 状态：已审查；分工：shell
- SHA-256：`f5dab78c398370c36bd4bd9155d02ffd99caef84e8ff66742831beb1381de06d`
- TXBoard 对应：`web/admin/src/components/layout/TopBar.tsx`、`web/admin/src/pages/SignInPage.tsx`、`web/admin/src/native/OverviewPage.tsx`

依据：

- All 23 namespaces inspected structurally; full missing/extra lists in audit-shell-locales.json.
- Root locale and scaffold locale are byte-identical for this language.

对照结论：

- Locale coverage gap: TXBoard admin uses Chinese literals and has no en-US/ru-RU language selector or translation resources.
- Reference resource quality: 97 Chinese leaf keys absent; 30 extra keys compared with zh-CN. Do not treat reference translations as complete.

## locales/zh-CN.json

Parsed every namespace and leaf key: 23 namespaces, 2292 leaf keys.

- 状态：已审查；分工：shell
- SHA-256：`a847a29495ee81b2ae868a9d827ed79d158254d1bee67be848bcbee73fc0c897`
- TXBoard 对应：`web/admin/src/components/layout/TopBar.tsx`、`web/admin/src/pages/SignInPage.tsx`、`web/admin/src/native/OverviewPage.tsx`

依据：

- All 23 namespaces inspected structurally; full missing/extra lists in audit-shell-locales.json.
- Root locale and scaffold locale are byte-identical for this language.

对照结论：

- Locale coverage gap: TXBoard admin uses Chinese literals and has no en-US/ru-RU language selector or translation resources.
- Reference resource quality: 0 Chinese leaf keys absent; 0 extra keys compared with zh-CN. Do not treat reference translations as complete.

## meta/components.json

Structurally reviewed all 266 component records and all 33 recursive trees.

- 状态：已审查；分工：root
- SHA-256：`3057a83a4a76e9134551e8f7792706708097309834d4584090504714aa204156`
- TXBoard 对应：`web/admin/src/native/NodesPage.tsx`、`web/admin/src/native/NodeDetailPage.tsx`、`web/admin/src/native/MachinesPage.tsx`、`web/admin/src/native/MachineDetailPage.tsx`、`contracts/core-api/operations.v1.json`

依据：

- audit-meta-components.json preserves every component, offset, callees, classes, keys, hooks and tree.
- 2395 tree nodes, 504 leaf markers, 566 cycle markers. Network J5t -> X5t, B3t -> F3t -> A3t, U3t -> z3t, Y3t -> G3t.

对照结论：

- Extraction is shallow: e.g. A3t is a 45-byte declaration, not the full machine drawer. Metadata alone does not prove all protocol form details.
- Form details below use scaffold implementation; original evidence is attributed only where actually present in extracted pages.

## meta/endpoints.json

Read all 95 extracted paths grouped by module.

- 状态：已审查；分工：root
- SHA-256：`cd5ba3b333e81cfbd8dc087f17fdec8a7d1fddcbc64178ce9bb203295927dc4c`
- TXBoard 对应：`contracts/core-api/operations.v1.json`、`web/admin/src/native/api.ts`

依据：

- server includes manage/save/update/copy/sort, machine/save/getToken/resetToken/history/installCommand, group and route saves.

对照结论：

- Path scan is not a complete callable API schema and does not encode request/response/permission semantics.
- Do not copy secure_path URLs into native frontend. Full node save/machine create/credentials/history are absent from current Core operation catalog.

## meta/page_index.json

Read all 33 page-index records and cross-checked route/component/offset associations.

- 状态：已审查；分工：root
- SHA-256：`54673032c329cc27263e667a29e98a9f9cc0f2e7e8f1e15e5fa50b4733721f02`
- TXBoard 对应：`web/admin/src/router.tsx`、`web/admin/src/native/routes.ts`

依据：

- 33 entries include dashboard twice; system parent/index are separate entries.

对照结论：

- 33 index records are not 33 distinct top-level business pages; index aliases and repeated dashboard must be deduplicated.

## meta/pages.json

Read all 33 page records, namespaces, hooks, API/JSX and class samples.

- 状态：已审查；分工：root
- SHA-256：`5abad6a5dc099a98c1c060f5c62bdbb06b5606fd31177a3fccf94e642a4b7b3a`
- TXBoard 对应：`web/admin/src/router.tsx`、`web/admin/src/native/routes.ts`

依据：

- Network wrapper J5t/B3t/U3t/Y3t delegates to page-specific components.

对照结论：

- Wrapper hook/API counts cannot stand for complete descendant behavior; omitted deep components require separate reading.

## meta/route_modules.json

Read all 41 nested route/module entries, including non-module/error/wildcard routes.

- 状态：已审查；分工：root
- SHA-256：`d4d58240c76324bac5c8eede5d4b9397edd16e74b7b5ede07670850f3709c2d5`
- TXBoard 对应：`web/admin/src/router.tsx`、`web/admin/src/native/routes.ts`

依据：

- depth/full/seg/ref distinguish config/system parent, index and APP path case.

对照结论：

- Plugin wildcard is a real runtime surface; redirecting it to ModulesPage is not equivalent.

## meta/routes.json

Read complete hash-router hierarchy and error routes.

- 状态：已审查；分工：root
- SHA-256：`734a0c1a35a43edf59c442e17d50fe887e0a162ef4277b840830d08dc8f4e093`
- TXBoard 对应：`web/admin/src/router.tsx`、`web/admin/src/native/routes.ts`

依据：

- 41 structural entries with AppShell auth guard and /500,/404,/503/*.

对照结论：

- TXBoard browser-router/base-path deployment and native detail routes are intentional differences; legacy aliases are incomplete.

## meta/ui-inventory.json

Read every UI marker count and null token field.

- 状态：已审查；分工：root
- SHA-256：`4b4063b4dce4569f2bbc4f340d77542c6bb8257fab062e685234b31e43ac3599`
- TXBoard 对应：`web/admin/src/components/ui/Modal.tsx`、`web/admin/src/components/ui/Popover.tsx`

依据：

- 16 lines: Dialog/AlertDialog/Sheet 3, Drawer 49, sonner 85, Calendar 6, tokens null.

对照结论：

- Marker counts are extraction hints, not usage or behavior coverage. Do not infer that 49 drawers or 85 toast components need recreation.

## pages/config-knowledge.md

原件 bundle 提取文档：知识库支持标题/分类/语言/Markdown 正文与显示状态编辑，编辑先读完整正文；列表支持筛选、启停、删除及全量排序。

- 状态：已审查；分工：business
- SHA-256：`dfe64ee82a026ecdc49669cf0d147b1a7cea8b7092e35e7e45e84a062495570b`
- TXBoard 对应：`web/admin/src/native/ContentPage.tsx`、`web/admin/src/components/ui/MarkdownEditor.tsx`、`web/admin/src/components/ui/TagInput.tsx`

依据：

- pages/config-knowledge.md:17 function t4t
- pages/config-knowledge.md:84 function J2t
- pages/config-knowledge.md:243 onConfirm

对照结论：

- [已覆盖] TX 以 get 读取完整正文，提供发布范围/时间、Markdown、可见性、归档、完整排序和操作审计；保留其版本与幂等安全语义。
- [前端缺口] 参考用带语言名称的 Select，TX language 仍为自由文本输入；可以提供常用语言选项并保留自定义值，不需要新接口。
- [前端缺口] 参考分类/显示筛选与排序操作工具更直观；TX 只有标题过滤，若 Core list 不支持分类查询，必须标明当前页筛选或扩展契约。
- [布局差异] 参考编辑器是 max-w-4xl 模态并有独立滚动区，TX 是页内区块；不能把外壳风格已接近说成编辑交互完全一致。

## pages/config-notice.md

原件 bundle 提取文档：公告支持标题、Markdown、封面 URL、标签、显示开关，列表显示状态与批量排序；破坏操作有确认。

- 状态：已审查；分工：business
- SHA-256：`d775f0f957b0ff62e44ddd92723bf00c553b98099d5eb8c01fb01e7ee2016d43`
- TXBoard 对应：`web/admin/src/native/ContentPage.tsx`、`web/admin/src/components/ui/MarkdownEditor.tsx`、`web/admin/src/components/ui/TagInput.tsx`

依据：

- pages/config-notice.md:17 function K2t
- pages/config-notice.md:107 name:"show"
- pages/config-notice.md:237 onConfirm

对照结论：

- [已覆盖] TX 已有公告新增/编辑、完整正文读取、Markdown、标签、封面、状态、全量排序；还包含 Core 支持的弹窗/受众/发布时间。
- [布局差异] TX 尚未复用参考 EditorDialog 的固定头/脚和70vh独立滚动正文；是否改成弹层应与编辑草稿关闭保护一起设计。
- [语义差异] 参考删除与 TX 归档不应按按钮名称强行等同；归档后的可见性/恢复能力依 Core 契约。

## pages/config-payment.md

原件 bundle 提取文档：支付列表读渠道、显示状态、费率与排序；新增/编辑先取提供方动态配置，支持状态切换、删除和排序。

- 状态：已审查；分工：business
- SHA-256：`34784e7cc672d343557360b09fa0c8abb5e7f8482b77d1a61788de8db227b632`
- TXBoard 对应：`web/admin/src/native/PaymentMethodsPage.tsx`、`web/admin/src/native/PaymentMethodsPage.test.tsx`

依据：

- pages/config-payment.md:17 function fQt
- pages/config-payment.md:86 function mQt

对照结论：

- [已覆盖] TX 动态获取表单、商户密钥不回显、支持币种摘要、编辑聚焦、修改版本/幂等与未知结果恢复均有实现。
- [契约不支持] 参考非零费率、notify_domain、删除、专用排序接口，当前 Core admin-payment save 不能按原件字段任意扩充；TX 零手续费约束应保留。
- [原件提取限制] payment 文档仅106行且 mQt/HGt 签名截断，完整表单字段以 scaffold 补充推断，不能全部宣称来自原件完整源码。

## pages/config-plugin-crud-demo.md

原件 bundle 提取文档：演示 schema 驱动表格与编辑/删除对话框：文本、数值、布尔、选项字段；创建/编辑使用 schema 默认值并支持搜索与排序。

- 状态：已审查；分工：business
- SHA-256：`ca21f5ba14092f87092d2674807bc1cb6c14f88b08a7de60708dd2c4f999196c`
- TXBoard 对应：`web/admin/src/native/ModulesPage.tsx`、`packages/core-sdk/src/contracts.generated.ts`

依据：

- pages/config-plugin-crud-demo.md:20 function v1t
- pages/config-plugin-crud-demo.md:101 onConfirm
- pages/config-plugin-crud-demo.md:59 runtime

对照结论：

- [重建范围] scaffold 的 DEMO_SCHEMA 与 seedRecords 是本地演示，保存/删除只是延时修改 React state，不是当前 Core 的可用业务。
- [未实现] TX 没有插件 schema CRUD 渲染器或该演示页。
- [契约不支持] 若扩展真实插件 CRUD，必须经现有 Core 权限/输入 schema/幂等边界；不能直接执行插件传入任意 URL。

## pages/config-plugin-menu-demo.md

原件 bundle 提取文档：插件菜单声明演示，修改 id/title/path/icon/label 后生成归一化 href 和侧栏预览，展示插件元数据。

- 状态：已审查；分工：business
- SHA-256：`9a4151d3bda47a0d7b4df6610b8f369496e70f9ab6632d7565cbc1bd0bdf350f`
- TXBoard 对应：`web/admin/src/native/ModulesPage.tsx`、`packages/core-sdk/src/contracts.generated.ts`、`web/admin/src/components/layout/Sidebar.tsx`

依据：

- pages/config-plugin-menu-demo.md:5 runtime.addMenu
- pages/config-plugin-menu-demo.md:116 function m1t

对照结论：

- [未实现] TX 无插件 admin_menus 贡献预览/声明编辑页。
- [重建范围] scaffold 是 DEMO_PLUGIN 的本地状态演示，不可作为模块管理菜单持久化接口。
- [架构差异] 插件声明需受导航路由与权限所有者约束，不能照参考任意拼接并注册有副作用的路由。

## pages/config-plugin.md

原件 bundle 提取文档：插件目录按类型/安装状态筛选，提供 README、schema 配置抽屉、ZIP 上传及安装/启停/升级/卸载等生命周期入口。

- 状态：已审查；分工：business
- SHA-256：`b68eabce1ff3cb4b556a882f7e79b3d7191275a438db68e2cbf340eeed1a77ae`
- TXBoard 对应：`web/admin/src/native/ModulesPage.tsx`、`packages/core-sdk/src/contracts.generated.ts`

依据：

- pages/config-plugin.md:3 g1t
- pages/config-plugin.md:5 messages.uninstallSuccess

对照结论：

- [已覆盖] TX ModulesPage 有名称/类型/版本/来源/安装/启用/健康列表、详情、操作记录和权限撤销后清理。
- [契约不支持] Core 只有 modules list/get/operations.list，缺上传、安装、启停、升级、卸载和 schema 配置写接口；当前只读边界是真实限制，不能添加假成功按钮。
- [可继续盘点] README/作者/链接等元数据若 Core 未返回，不得从参考内置示例冒充当前模块信息。

## pages/config-system-app.md

原件 bundle 提取文档：Windows/macOS/Android 三平台各提供版本与下载地址，共六个字段；原件按配置组读写并显示保存中状态。

- 状态：已审查；分工：business
- SHA-256：`842dbfb204dd74f95759e62f16dd23fe411d7b51053ca0bb3599a2b26c3779eb`
- TXBoard 对应：`web/admin/src/native/ConfigurationPage.tsx`、`web/admin/src/native/SettingsPage.tsx`、`web/admin/src/native/configurationPresentation.ts`、`contracts/core-api/admin-settings.v1.json`

依据：

- pages/config-system-app.md:62 name:"windows_version"
- pages/config-system-app.md:107 name:"macos_download_url"
- pages/config-system-app.md:137 name:"android_download_url"

对照结论：

- [已覆盖] TX metadata.app 六字段齐全，configurationSection 按平台分组，并显示编辑/修改计数与保存错误。
- [交互差异] 参考为自动保存，TX 为显式编辑/按分类提交并使用版本检查；这是写入策略差异，不应为外观一致擅自改成自动写。

## pages/config-system-email.md

原件 bundle 提取文档：SMTP 配置与邮件模板两区；模板按场景切换，dirty 保护、变量说明/样例/光标插入、HTML 预览、自定义重置与发送测试。

- 状态：已审查；分工：business
- SHA-256：`97cf18162cb6f52125446bb78aa8ae75b12d4d1599ff196297be464946e2d49d`
- TXBoard 对应：`web/admin/src/native/ConfigurationPage.tsx`、`web/admin/src/native/SettingsPage.tsx`、`web/admin/src/native/configurationPresentation.ts`、`contracts/core-api/admin-settings.v1.json`、`web/admin/src/native/MailTemplatesPage.tsx`、`web/admin/src/native/MailPreview.tsx`、`web/admin/src/native/ConfigurationMail.test.tsx`

依据：

- pages/config-system-email.md:20 function bYt
- pages/config-system-email.md:168 onInsertPlaceholder
- pages/config-system-email.md:185 email_template.discard_confirm
- pages/config-system-email.md:428 name:"remind_mail_enable"

对照结论：

- [本轮补齐并针对测试] TX 增加脏草稿切换/取消确认、源码选区插入变量并保留复制；测试发送后继续保留草稿，相关测试通过。
- [已覆盖] TX MailPreview 保持 sandbox 空权限、严格 CSP、阻止外部资源；不能照原件的 HTML 直出行为弱化隔离。
- [前端缺口] TX 仍只显示变量名及必需/可选，缺变量含义/演示样例、替换样例的预览与变量插入主题；可用明确标注的演示数据完善，不冒充服务端返回值。
- [契约不支持] remind_mail_enable 不在当前 admin-settings metadata；SMTP 独立诊断接口也不在当前 Core，不能复制原件诊断成功提示。

## pages/config-system-frontend.md

原件 bundle 提取文档：持久化设置侧栏/顶部样式、主题配色和登录背景地址，使用显式保存。

- 状态：已审查；分工：business
- SHA-256：`8b5e9d9ba73813f8dcb0146a36c69666e33b00185970fea488c59d7dffb71361`
- TXBoard 对应：`web/admin/src/native/ConfigurationPage.tsx`、`web/admin/src/native/SettingsPage.tsx`、`web/admin/src/native/configurationPresentation.ts`、`contracts/core-api/admin-settings.v1.json`、`web/admin/src/components/ui/ThemeToggle.tsx`

依据：

- pages/config-system-frontend.md:42 name:"frontend_theme_sidebar"
- pages/config-system-frontend.md:74 name:"frontend_theme_color"
- pages/config-system-frontend.md:103 name:"frontend_background_url"

对照结论：

- [未实现/契约不支持] TX 无 frontend 配置组且 metadata 无上述四字段；当前三态 ThemeToggle 是本机管理端外观，不等于站点前端主题持久化配置。
- [重建质量] scaffold 该文件的部分字面文案已存在编码异常；直接复制会引入乱码，不能以视觉接近掩盖文本问题。

## pages/config-system-invite.md

原件 bundle 提取文档：邀请模式、邀请生成限额/有效期、首单返佣、自动审核、提现限制/方式/关闭提现和三级分配比例等策略设置。

- 状态：已审查；分工：business
- SHA-256：`ae0c7d3c41248a4cda8be26eae8906e5a79ea0e5ed63d48cddf43cea103306a5`
- TXBoard 对应：`web/admin/src/native/CommissionsPage.tsx`、`web/admin/src/native/SettingsPage.tsx`、`contracts/core-api/admin-settings.v1.json`

依据：

- pages/config-system-invite.md:67 name:"invite_force"
- pages/config-system-invite.md:169 name:"commission_withdraw_limit"
- pages/config-system-invite.md:221 name:"commission_distribution_enable"

对照结论：

- [语义差异] TX /config/system/invite 路由使用单级佣金策略及钱包转入审核；已有rate_bps/hold_seconds/版本/退款策略。
- [契约不支持] Core 当前不是三级分销/外部提现模型；参考 invite_force/多级分成/银行提现字段不能混入单级奖励策略。
- [需明确产品范围] 邀请码生成上限/有效期与首单策略若需实现，先补充权威模型与契约，不能仅补几个设置控件。

## pages/config-system-safe.md

原件 bundle 提取文档：注册与登录频率、邮箱验证/Gmail 限制/域名白名单、验证码服务与对应密钥、安全模式和安全路径。

- 状态：已审查；分工：business
- SHA-256：`8194155106c63c0f3ef107167e27bc20edbd706f2f31fc848e6577a00d75b785`
- TXBoard 对应：`web/admin/src/native/ConfigurationPage.tsx`、`web/admin/src/native/SettingsPage.tsx`、`web/admin/src/native/configurationPresentation.ts`、`contracts/core-api/admin-settings.v1.json`

依据：

- pages/config-system-safe.md:159 name:"email_whitelist_suffix"
- pages/config-system-safe.md:197 name:"captcha_type"
- pages/config-system-safe.md:124 name:"secure_path"

对照结论：

- [已覆盖] TX 已有邮箱/频率限制、三类验证码服务密钥与凭据留空保留；敏感值不回显，CAS冲突后需要重新编辑。
- [前端缺口] TX 通用 metadata 编辑器按组平铺所有验证码字段，参考只显示当前启用服务对应字段；可做条件显示但必须保留未展示服务的原配置。
- [契约不支持/名称差异] safe_mode_enable、secure_path、recaptcha_v3_score_threshold 未纳入TX metadata；password_limit*由TX login_limit*语义覆盖，不能按字面重复添加。

## pages/config-system-server.md

原件 bundle 提取文档：服务端 token 显示/重生成、拉取/上报间隔、WebSocket 开关及 URL；原件从站点信息组合默认地址。

- 状态：已审查；分工：business
- SHA-256：`f85ad61cbe5e8b3583e3bb0b512928a9a9ba393c1fd244bcd162d441ff0e9027`
- TXBoard 对应：`web/admin/src/native/ConfigurationPage.tsx`、`web/admin/src/native/SettingsPage.tsx`、`web/admin/src/native/configurationPresentation.ts`、`contracts/core-api/admin-settings.v1.json`

依据：

- pages/config-system-server.md:67 name:"server_token"
- pages/config-system-server.md:108 name:"server_pull_interval"
- pages/config-system-server.md:146 name:"server_ws_enable"

对照结论：

- [已覆盖] TX 当前仅配置 server_pull_interval/server_push_interval/device_limit_mode。
- [契约不支持] server_token 和 server_ws_enable/server_ws_url 未在 admin-settings schema 中，不能自行生成随机token并显示已生效。
- [结构差异] 参考 URL默认构成依旧后端/site模型，TX 节点部署与 Agent通道需按自己的权威路径展示。

## pages/config-system-subscribe-template.md

原件 bundle 提取文档：六种订阅模板 tab（singbox JSON、clash/clashmeta/stash YAML、surge/surfboard INI），Monaco 语法、500px 编辑区与1500ms自动保存。

- 状态：已审查；分工：business
- SHA-256：`216ccb813af3bf6a40a5bca83c0eb8a8cb2d4708049df85f5d1c67a78aa0596f`
- TXBoard 对应：`web/admin/src/native/ConfigurationPage.tsx`、`web/admin/src/native/SettingsPage.tsx`、`web/admin/src/native/configurationPresentation.ts`、`contracts/core-api/admin-settings.v1.json`

依据：

- pages/config-system-subscribe-template.md:18 function VQe
- pages/config-system-subscribe-template.md:91 onValueChange:s
- pages/config-system-subscribe-template.md:5 subscribe_template

对照结论：

- [未实现] TX 无 subscribe-template 路由/分组、六模板字段及代码编辑器，这是完整页面能力缺口。
- [契约不支持] metadata 不含 subscribe_template_*，Core settings schema不接受这些字段；不能仅建可输入但无法保存的伪完成界面。
- [实施顺序] 需先定义模板所有者/编译与校验契约，再复用tab/编辑器；必须区分模板语法校验与真实订阅输出验收。

## pages/config-system-subscribe.md

原件 bundle 提取文档：套餐变更、流量重置、结余折抵、新购/续费/变更事件、订阅地址和服务端信息展示。

- 状态：已审查；分工：business
- SHA-256：`d3969d270887b4f87d429a6017941d72594961782e60b6fa4c728fd718c6313e`
- TXBoard 对应：`web/admin/src/native/ConfigurationPage.tsx`、`web/admin/src/native/SettingsPage.tsx`、`web/admin/src/native/configurationPresentation.ts`、`contracts/core-api/admin-settings.v1.json`

依据：

- pages/config-system-subscribe.md:62 name:"plan_change_enable"
- pages/config-system-subscribe.md:110 name:"surplus_enable"
- pages/config-system-subscribe.md:127 name:"new_order_event_id"
- pages/config-system-subscribe.md:208 name:"subscribe_path"

对照结论：

- [已覆盖] TX 支持套餐变更、流量重置及三种订单事件字段。
- [契约不支持] surplus_enable、subscribe_path、show_info_to_server_enable、show_protocol_to_server_enable 未在TX metadata，结余折抵尤其不能仅前端增加开关。
- [前端缺口] 参考订单事件选项提供业务友好名称，TX metadata.select 值目前较通用，可扩展配置字典而不改变提交值。

## pages/config-system-telegram.md

原件 bundle 提取文档：机器人token、是否启用、自定义Webhook和讨论群链接；显式执行设置Webhook并展示目标说明与官方说明链接。

- 状态：已审查；分工：business
- SHA-256：`bf58a1a7a48b4f4c1739e5fd8ebaf62c40333a0956faafdaaf21172f8943ce4d`
- TXBoard 对应：`web/admin/src/native/ConfigurationPage.tsx`、`web/admin/src/native/SettingsPage.tsx`、`web/admin/src/native/configurationPresentation.ts`、`contracts/core-api/admin-settings.v1.json`

依据：

- pages/config-system-telegram.md:92 name:"telegram_bot_token"
- pages/config-system-telegram.md:118 telegram.webhook.button
- pages/config-system-telegram.md:152 name:"telegram_discuss_link"

对照结论：

- [已覆盖] TX 配置组包含四字段，token write_only不回显。
- [契约不支持] TX没有设置/诊断Webhook operation，保存telegram_webhook_url不等于已向Telegram注册成功；原件按钮不能直接复制。
- [可做前端增强] 增加填写说明、目标地址来源解释和官方帮助链接，但不能泄露token或后台请求结果。

## pages/config-system.md

原件 bundle 提取文档：站点名称/介绍/URL/Logo/条款/停止注册，及强制HTTPS、订阅URL、工单等待、试用套餐/时长、币种信息。

- 状态：已审查；分工：business
- SHA-256：`a091ef321e0493869aac482995d50fe2f02e82ea6e2be6109fd0bb90ae6934f5`
- TXBoard 对应：`web/admin/src/native/ConfigurationPage.tsx`、`web/admin/src/native/SettingsPage.tsx`、`web/admin/src/native/configurationPresentation.ts`、`contracts/core-api/admin-settings.v1.json`

依据：

- pages/config-system.md:120 name:"force_https"
- pages/config-system.md:207 name:"ticket_must_wait_reply"
- pages/config-system.md:225 name:"try_out_plan_id"

对照结论：

- [已覆盖] TX 支持 app_name/app_description/app_url/logo/tos_url/stop_register 六字段，有按组显式保存和版本检查。
- [契约不支持] force_https/subscribe_url/ticket_must_wait_reply/try_out_plan_id/try_out_hour/currency/currency_symbol不在TX当前settings写schema；不能宣称站点设置已按原件全量完成。
- [可做前端增强] URL和Logo/下载地址链接展示、帮助说明可在已授权字段上继续完善；不能自动修改部署层HTTPS行为。

## pages/config-theme.md

原件 bundle 提取文档：主题列表/当前主题、截图轮播预览、ZIP拖放上传、主题schema配置、激活与删除确认。

- 状态：已审查；分工：business
- SHA-256：`5dce9424756b01d7d4d887e76908c7868b9abaac71bc691c24bcae2725efc337`
- TXBoard 对应：`web/admin/src/native/ModulesPage.tsx`、`packages/core-sdk/src/contracts.generated.ts`

依据：

- pages/config-theme.md:6 BL()
- pages/config-theme.md:5 card.activateTheme
- pages/config-theme.md:104 function k1t

对照结论：

- [已覆盖] TX ModulesPage(kind=theme)仅提供主题模块基本信息、状态、详情和操作历史。
- [未实现/契约不支持] 当前Core无主题上传/配置/激活/删除接口，也不返回主题截图配置schema，无法前端独立补齐。
- [概念区分] ThemeToggle 三态外观是管理端偏好，不是主题市场当前主题或用户站点主题。

## pages/dashboard.md

Reviewed original extracted dashboard components, revenue date selection, responsive dialogs and Horizon monitor context.

- 状态：已审查；分工：shell
- SHA-256：`76689a82e463e212a90e66185d96237e454b78ab622f8aae99ede22ef44e45b4`
- TXBoard 对应：`web/admin/src/native/OverviewPage.tsx`、`web/admin/src/native/overviewCharts.ts`、`web/admin/src/native/QueuePage.tsx`、`packages/core-sdk/src/contracts.generated.ts`

依据：

- L20 UGt queueStats/failed jobs; L240 $$t amount/count and 30d/custom dates; L510 uqt and L518 eKt are truncated declarations; L530-L655 queue context.
- Extraction is incomplete for uqt/eKt and includes chart-library internals L420-L507; scaffold fills additional structure.

对照结论：

- Coverage: four live resource totals, recent-order sample income chart and order-status donut, node history chart, recent orders, and node list exist. These are not reference dashboard equivalence.
- Missing dashboard presentation: eight reference metric cards; amount/count revenue tabs; 7/30/90/180/365-day and custom date range; income/commission summary; node and user traffic ranking; embedded queue monitor and nested failure dialogs.
- Core contract gap: no getStats/getOrder/getTrafficRank equivalent in CORE_ADMIN_OPERATIONS. No authoritative today/month income and growth, monthly new users/growth, active/online users/devices, global upload/download totals, date-filtered income/commission series or prior-period node/user ranking.
- Current order chart reads at most 100 recent orders and correctly labels sample/currency/UTC scope; cannot produce global revenue/commission with that sample. Node history accepts only days 7/14/30 and 1-20 node IDs, UTC settled raw usage; cumulative top five within first 100 nodes is not global time-range traffic rank.
- Queue coverage exists on /system/queue rather than dashboard. Core snapshot supports nullable observed counters; failures.list is limit 1-30 without pagination, failures.get exposes id/connection/queue/job/failed_at only. Raw exception/payload, pausedMasters, throughput/runtime maxima and retention periods cannot be copied from Horizon UI.
- Polling difference: reference statistics 300s, charts/rank/queue 30s; OverviewPage has explicit manual refresh, QueuePage opt-in 30s refresh. Reviewed source, not browser acceptance.

## pages/finance-coupon.md

核对 F6t 服务端分页、手动筛选/排序、右侧操作固定与移动卡片声明。

- 状态：已审查；分工：tables
- SHA-256：`21a2f9b8975264da20f60e7f693312296d54a1797a23a9ee35c34c4feb593b8e`
- TXBoard 对应：`web/admin/src/native/CouponsPage.tsx`、`web/admin/src/native/ReadList.tsx`、`packages/core-sdk/src/contracts.generated.ts`

依据：

- pages/finance-coupon.md:17 F6t
- pages/finance-coupon.md:27 queryKey/coupon fetch
- pages/finance-coupon.md:48 mobilePrimaryField/name + mobileGridFields

对照结论：

- 覆盖：TX 有真实优惠券列表、总量、分页大小和折扣/次数/有效期展示。
- 前端待补：TX 优惠券没有 mobilePrimaryKey，窄屏仍是横向表格；disable 操作列未被固定列键规则识别。
- 契约差异：Core billing.coupons.list 只收 page/per_page，最大 50；参考端 filter/sort 及编辑/删除不能直接套用。

## pages/finance-gift-card.md

逐段核对模板、兑换码、使用记录、统计四块及各自查询/分页。

- 状态：已审查；分工：tables
- SHA-256：`08eaca6753e18294f9cf10474e6c4f93c1267bca3b04df79815941358c49fb78`
- TXBoard 对应：`web/admin/src/native/GiftCardsPage.tsx`、`packages/core-sdk/src/contracts.generated.ts`

依据：

- pages/finance-gift-card.md:20 t8t codes
- pages/finance-gift-card.md:77 Y6t templates
- pages/finance-gift-card.md:144 i8t usages
- pages/finance-gift-card.md:199 r8t total_stats

对照结论：

- 覆盖：TX 有单卡、批次、批次内逐卡状态及撤销。
- 契约差异：TX 是钱包出资/预留/兑换/退回发行人的资金模型，参考是奖励模板+生成兑换码，不能用模板统计替代账本。
- 未覆盖：全站使用记录、模板管理与全局统计没有对应原生管理员契约；当前批次四个状态计数只属于该批次，不是全站统计。

## pages/finance-order.md

核对 E6t 的 URL 条件、订单金额/周期/状态/佣金列以及移动卡片字段。

- 状态：已审查；分工：tables
- SHA-256：`d870ef71e3800d6de8e46adeb5f13a8d4458424c3cd39958d16fe163d6418294`
- TXBoard 对应：`web/admin/src/pages/finance/OrdersPage.tsx`、`web/admin/src/native/OrderReadDialog.tsx`、`web/admin/src/native/OrderAdministration.tsx`

依据：

- pages/finance-order.md:25 user_id/order_id/commission_status/status URL filters
- pages/finance-order.md:57 mobilePrimaryField trade_no
- pages/finance-order.md:113 total_amount
- pages/finance-order.md:153 PENDING actions
- pages/finance-order.md:183 commission_balance

对照结论：

- 覆盖：TX 按用户 ID/原始订单号/状态查询，显示中文类型/周期、精确金额、购买/支付时间，详情含金额拆分、时间线和原生操作。
- 契约差异：Core 列表没有佣金金额/佣金状态或排序输入，不能声称参考佣金列、排序和多面板筛选已实现。
- 参考侧页码及条件从 URL 读取；TX 当前 URL page/per_page 可保留，清除和逐项删筛选会回到第一页。

## pages/finance-plan.md

原件 bundle 提取文档：套餐编辑完整字段、标签、权限组选择并新增分组、8周期价格/基础价批量填入/清空、说明模板与Markdown预览、列表拖动排序和force_update。

- 状态：已审查；分工：business
- SHA-256：`101f034f6a66ddca6b7b8a4d77b0beb31d4a349fa1077ded7636187ac1502502`
- TXBoard 对应：`web/admin/src/native/CatalogPage.tsx`、`web/admin/src/native/CatalogPage.test.tsx`、`web/admin/src/native/commerce-format.ts`

依据：

- pages/finance-plan.md:91 function l6t
- pages/finance-plan.md:138 name:"force_update"
- pages/finance-plan.md:172 name:"group_id"
- pages/finance-plan.md:229 plan.form.content.template

对照结论：

- [已覆盖] TX 从authoritative get读取完整条款，区分Plan/Revision/Offer；标签、精确金额、访问额度、8周期价、历史与可用性编辑均实现。
- [本轮补齐并针对测试] 套餐说明增加按当前流量/限制生成模板，有正文须确认替换；原价格/分组/CAS保持不变，测试通过。
- [前端缺口] 仍使用纯group ID输入，没有现有network.groups.list可支持的友好名称选择及inline新增；可复用分组组件但需独立检查读取/写权限。
- [前端缺口] 缺周期价格一键清空与明确公式的批量草稿填充；不得照原件浮点base*months*discount造成分单位精度或自动改价。
- [契约/模型差异] 不支持旧套餐force_update及旧ID原地改价/拖拽sort模型；TX不可变修订与旧订单冻结条款不可被视觉复刻破坏。

## pages/plugins-plugincode.md

原件 bundle 提取文档：按插件声明的admin_menus/admin_crud匹配通配路由，未找到/禁用分支、概览README/元数据和设置；原件i4t由schema调用CRUD list/save/delete。

- 状态：已审查；分工：business
- SHA-256：`c0e6ee7aa9497eb2c5b5956aeb4354362a26a78aff12f635d2409f602d639029`
- TXBoard 对应：`web/admin/src/native/ModulesPage.tsx`、`packages/core-sdk/src/contracts.generated.ts`、`web/admin/src/router.tsx`

依据：

- pages/plugins-plugincode.md:76 function i4t
- pages/plugins-plugincode.md:89 ET.crudList
- pages/plugins-plugincode.md:92 ET.crudSave
- pages/plugins-plugincode.md:94 ET.crudDelete

对照结论：

- [原件与重建不一致] 原件文档i4t具有真实schema CRUD请求，而scaffold明确当前版本未实现、只打印JSON占位，不能把该占位当成原件全部功能。
- [未实现] TX没有/plugins/:pluginCode/*运行时页面和schema CRUD renderer；ModulesPage读取模块元数据不等于插件运行时执行。
- [契约不支持] 当前Core没有任意插件CRUD入口、README/config schema和菜单贡献协议，需要正式受控契约而非直连参考API。

## pages/README.md

Read full route-to-component index and extraction method.

- 状态：已审查；分工：root
- SHA-256：`abd248fac634a45122ed95051d2e27cbd4fde0e8595be6fa117ef375b31a5e77`
- TXBoard 对应：`docs/admin-component-audit.md`

依据：

- 46 lines: shared wrappers vs own components, route scan and helper recovery method.

对照结论：

- Duplicate dashboard and parent/index routes require explicit counting; document index alone misses deep form component bodies.

## pages/server-group.md

Read all original group wrapper/column/form context.

- 状态：已审查；分工：root
- SHA-256：`9aff571f6e702848179101a4fc8b5a944b85816c9ce629682b6ae40469d88c50`
- TXBoard 对应：`web/admin/src/native/GroupsPage.tsx`、`contracts/core-api/operations.v1.json`

依据：

- U3t -> z3t, group list/save/drop, original c4t form/name schema.

对照结论：

- Covered group create/edit/delete and user/node counts; TXBoard also exposes plan count.
- Reference name 2-50 and restricted alphabet differs from Core nonempty/max255; do not silently narrow valid existing TXBoard names.

## pages/server-machine.md

Read full original F3t and component context.

- 状态：已审查；分工：root
- SHA-256：`04f43c266601d1638a72c51845c0b566d461ac7aa427a166e1a4ddf4ab45ec20`
- TXBoard 对应：`web/admin/src/native/NodesPage.tsx`、`web/admin/src/native/NodeDetailPage.tsx`、`web/admin/src/native/MachinesPage.tsx`、`web/admin/src/native/MachineDetailPage.tsx`、`contracts/core-api/operations.v1.json`

依据：

- F3t: 30s staleTime/60s poll, machine_id URL opens A3t, status/overview/table; A3t body is not fully extracted.

对照结论：

- Covered: list/detail, name/notes/is_active/image_channel update, paginated associated nodes.
- Missing Core: create, credentials/rotation/install command, telemetry/history. Detail host is a route, not the original right drawer.

## pages/server-manage.md

Read full original node-list wrapper and extracted schema/context.

- 状态：已审查；分工：root
- SHA-256：`2cc5ae0f68277fb15dd0bc703bf7e48ff6805e94659c2dcce57d7bc2d0c1d2e9`
- TXBoard 对应：`web/admin/src/native/NodesPage.tsx`、`web/admin/src/native/NodeDetailPage.tsx`、`web/admin/src/native/MachinesPage.tsx`、`web/admin/src/native/MachineDetailPage.tsx`、`contracts/core-api/operations.v1.json`

依据：

- X5t: list query, 30s polling outside sort mode, full-set drag sort, groups/machines and URL machine_id/open_create.
- Current native list and detail compared against original wrapper plus 4500-line scaffold.

对照结论：

- Covered: list/detail/string IDs, visibility/enabled/copy/delete/reset/batch operations, traffic chart.
- Missing: full create/edit node, protocol fields, group/route editing, advanced settings, full ordering UI, original list columns/facets.
- Core nodes.patch only allows node_id/show/enabled/machine_id; sort accepts at most 100, so cannot submit an arbitrarily paged subset as global order.

## pages/server-route.md

Read full original route wrapper/column/form context.

- 状态：已审查；分工：root
- SHA-256：`b098a948a0b6cd3e841607c7e281f31967cf58e4f1eb929b836e812d3a2130f0`
- TXBoard 对应：`web/admin/src/native/RoutesPage.tsx`、`contracts/core-api/operations.v1.json`

依据：

- Y3t -> G3t, Z3t columns/K3t toolbar/q3t form, action block/dns/direct/proxy.

对照结论：

- Covered CRUD, conditional action_value, full route reorder and read-only simulation.
- Core additionally has enabled/sort/count fields; original default block differs from TXBoard direct and is a product choice, not proof of invalidity.

## pages/sign-in.md

Reviewed original sign-in extraction, reset-help keys, Card and responsive dialog desktop/mobile branches.

- 状态：已审查；分工：shell
- SHA-256：`17a18e6afa22cca98d2759c30e4349c166a62210d4e686277e5e6ffaa55c924f`
- TXBoard 对应：`web/admin/src/pages/SignInPage.tsx`、`web/admin/src/components/CaptchaWidget.tsx`、`web/admin/src/native/session.ts`、`web/admin/src/lib/basePath.ts`

依据：

- L3 Jst; L5 resetPassword command/description/title keys; L81-L127 responsive dialog content (mobile bottom drawer, desktop max-height dialog).

对照结论：

- Coverage: centered login card, email/password icons, real Core admin session, optional provider captcha, error state, pending disable, theme switch, password visibility and validated internal redirect.
- Missing reference UI: language selector and administrator reset-help dialog/command copy. TXBoard branding and glass/gradient card also differ from reference flat card and configurable title/description.
- Contract difference: reference Sanctum auth_data/localStorage and PHP artisan reset command are legacy Xboard. Core login must retain accepted session lifecycle and must not store or derive identity from that token format.
- Scaffold adds self-service /passport/auth/forget and manual pasted captcha token. Its own L29-L33 comment separates original reset instructions from reconstruction additions; original pages/sign-in.md does not establish self-service reset parity. No accepted Core admin reset operation in generated admin operation inventory.
- Current captcha configuration errors explicitly pause sign-in and offer retry. Reference silently clears commConfig on load error and login post omits captcha; do not reproduce those failure paths.

## pages/user-manage.md

核对 G8t 用户查询、默认隐藏角色列与 K8t 编辑表单中的身份/手机号/账户/订阅/限制字段。

- 状态：已审查；分工：tables
- SHA-256：`23405230fe280f109836a3c654e46ea6d6b3a894f6b278f38f6fa27bd0b8b700`
- TXBoard 对应：`web/admin/src/pages/users/UsersPage.tsx`、`web/admin/src/pages/users/UserDetailPage.tsx`、`packages/core-sdk/src/contracts.generated.ts`

依据：

- pages/user-manage.md:17 G8t
- pages/user-manage.md:25 email URL input
- pages/user-manage.md:83 K8t
- pages/user-manage.md:131 invite_user_email
- pages/user-manage.md:146 phone_auth
- pages/user-manage.md:267 speed_limit
- pages/user-manage.md:305 is_admin/is_staff
- pages/user-manage.md:356 dirtyFields patch

对照结论：

- 覆盖：TX 真实账户列表/详情、新建单用户、封禁/解封、当前订阅与生命周期操作、最近订单和复制身份。
- 未覆盖：手机号、邀请人、员工角色、备注、余额/佣金编辑、流量用量直接改写、速度/设备限制等参考编辑字段不在当前账户管理写契约内。
- 不可等同：TX 独立详情页是安全可读管理入口，不等同参考完整用户编辑抽屉；当前列表也没有批量生成/导出/群发/批量封禁。

## pages/user-ticket.md

原件 bundle 提取文档：工单按状态服务端分页、详情会话窗口、可折叠同状态侧栏、独立滚动消息、用户资料入口、回复和关闭确认；手机卡片字段有专门定义。

- 状态：已审查；分工：business
- SHA-256：`701f3cd3931163f8b77790e7366a4497b0f018336f41905e1b390248a8112efe`
- TXBoard 对应：`web/admin/src/native/TicketsPage.tsx`、`web/admin/src/native/AttachmentsPanel.tsx`、`web/admin/src/native/SupportContent.test.tsx`

依据：

- pages/user-ticket.md:52 mobilePrimaryField:"subject"
- pages/user-ticket.md:62 queryKey:["ticketSidebarList"]
- pages/user-ticket.md:76 Q.jsx(b9t

对照结论：

- [已覆盖] TX拥有状态/用户/归属过滤、详情、消息游标、字节限制、认领/释放、附件和审计，均走Core版本/幂等。
- [前端缺口] 本核对时TX详情仍为页内长区块，缺固定回复区/独立会话滚动；父线程正由其他代理推进此项，审查记录不将未合并修改宣称已完成。
- [交互建议] 可保留当前筛选上下文并增加草稿关闭/切换保护；不要照搬Enter直接发消息而忽略中文IME。
- [原件提取限制] 文档K8t实为用户编辑器片段而非完整会话正文，:358上下文也被截断；会话完整布局/自动滚动细节来自scaffold补充，不可混淆证据来源。

## pages/user-traffic-reset-logs.md

核对 S9t 日志分页、date_range 参数转换、触发源/重置类型/清除流量及双时间列。

- 状态：已审查；分工：tables
- SHA-256：`6e9810faf8c1c815bd26766233f9a724904602ed853ec2d192f1c57ea46082ed`
- TXBoard 对应：`web/admin/src/native/TrafficResetPage.tsx`、`packages/core-sdk/src/admin.generated.ts`

依据：

- pages/user-traffic-reset-logs.md:17 S9t
- pages/user-traffic-reset-logs.md:28 date_range reduce
- pages/user-traffic-reset-logs.md:39 trafficResetLogs query

对照结论：

- 重要未覆盖：TX 当前 TrafficResetPage 是读取单用户订阅后执行周期重置，不是流量重置历史日志。
- 契约不支持：当前管理员操作清单没有 traffic-reset logs、日志时间区间筛选或日志导出接口，不能以重置操作页声称此参考页已覆盖。
- 保留边界：TX 重置通过 expected_version 开启新周期并保留旧用量，不能用参考前端 old_traffic 模型反推可直接清零。

## scaffold/index.html

Read entire boot HTML and inline window.settings.

- 状态：已审查；分工：root
- SHA-256：`d0abb0e1fb5d808fa8ce81a9586fbb2ece0369ac3cd31d11e89906dd2fe310ea`
- TXBoard 对应：`web/admin/index.html`、`web/admin/src/main.tsx`

依据：

- 5 lines: fixed Xboard branding/version/secure_path admin and module entry.

对照结论：

- Demonstration globals, not TXBoard deployment configuration. Do not copy fixed title/version/security path.

## scaffold/package-lock.json

Parsed complete lockfile and inspected all 342 package records.

- 状态：已审查；分工：root
- SHA-256：`361703ffee24428b03b633e921b072efd6cdbf6a304add9d8e46e4c2709608a0`
- TXBoard 对应：`web/admin/package.json`、`web/admin/vite.config.ts`、`package-lock.json`

依据：

- lockfileVersion 3; all package versions and non-link integrity entries present.
- root dependency/devDependency maps match package.json: true / true.

对照结论：

- Dependency metadata review only; no install/security audit/build of the reference performed. Do not import its lock tree into TXBoard.

## scaffold/package.json

Read all scripts, runtime and development dependencies.

- 状态：已审查；分工：root
- SHA-256：`10359833f2838d1f690fa89cc29dc05ae94ddf199bd9b99687c08641eef1d87d`
- TXBoard 对应：`web/admin/package.json`、`web/admin/vite.config.ts`、`package-lock.json`

依据：

- React18/Radix/TanStackTable/Monaco/Markdown/Recharts stack; build is tsc -b && vite build.

对照结论：

- build emits beside src because tsconfig has no noEmit/outDir; reference harness deliberately uses tsc --noEmit.
- Reuse behavior with existing TXBoard stack; adding all dependencies is unnecessary. Monaco default CDN is an offline gap.

## scaffold/postcss.config.js

Read single-line Tailwind/autoprefixer configuration.

- 状态：已审查；分工：root
- SHA-256：`66b7f2e4108d42359ecc183c1efb8cead02ea91817024011d11b3c8216382223`
- TXBoard 对应：`web/admin/package.json`、`web/admin/vite.config.ts`、`package-lock.json`

依据：

- plugins tailwindcss and autoprefixer.

对照结论：

- Build adapter only; TXBoard existing CSS pipeline is the equivalent responsibility.

## scaffold/README.md

Read reconstruction setup, file map, behaviors, deviations and historical regression instructions.

- 状态：已审查；分工：root
- SHA-256：`d6a5f7cf016f615a58f4532503964c70900da58b69820282752924a692e5e920`
- TXBoard 对应：`web/admin/package.json`、`web/admin/vite.config.ts`、`package-lock.json`

依据：

- 29-page/34-source claim, original offsets, table/API/token behavior, known deviation table and visual harness.

对照结论：

- Stale statement machine drawer not implemented conflicts with current 2121-line machine.tsx implementation.
- Several historical claims are stale (toolbar/calendar/forms restored later); use current source and tools history with explicit attribution.
- Legacy localStorage auth and secure_path APIs cannot replace native Core session.

## scaffold/src/api/index.ts

Reviewed every exported legacy endpoint group: config, coupon, gift_card, knowledge, notice, order, payment, plan, server, stat, theme, ticket, traffic_reset, user.

- 状态：已审查；分工：shell
- SHA-256：`b8a37158745c4989298ffa1212997129b1a3e04effe9f7d0312fa8f5f06ac1f3`
- TXBoard 对应：`web/admin/src/native/api.ts`、`web/admin/src/native/session.ts`、`packages/core-sdk/src/admin.generated.ts`、`packages/core-sdk/src/contracts.generated.ts`

依据：

- L3 secure_path; L10-L226 endpoint table; L118-L169 machine/node/group/route; L171-L180 statistics endpoints.

对照结论：

- Architecture difference: native admin uses typed named Core operations with permissions, validation, idempotent writes and session invalidation; legacy secure_path REST strings are not admissible replacements.
- Not all legacy capabilities have Core equivalents: global dashboard stat/rank, machine token/install command/create, node protocol-specific create/generate ECH, gift template creation/code generation, legacy order assign/mark-paid and user mass email/export must each remain explicit gaps where absent from generated operations.
- API table itself is not complete application capability proof: dashboard direct system endpoints and config/save are called outside this file. Page/contract evidence takes precedence over export presence.

## scaffold/src/components/data-table/data-table-column-header.tsx

核对可排序表头三态图标、非排序纯标题和说明提示。

- 状态：已审查；分工：tables
- SHA-256：`2d58cfd88d947b1f8445823cfc5c9f676c4f7bb807ae6a8ce160167b83ca3976`
- TXBoard 对应：`web/admin/src/components/ui/DataTable.tsx`、`web/admin/src/components/ui/Popover.tsx`

依据：

- scaffold/src/components/data-table/data-table-column-header.tsx:41 getCanSort
- scaffold/src/components/data-table/data-table-column-header.tsx:58 toggleSorting
- scaffold/src/components/data-table/data-table-column-header.tsx:30 tooltip

对照结论：

- 覆盖：TX Column.description 有可聚焦说明按钮和弹层。
- 未覆盖：TX Column 没有排序回调或三态排序状态；相关业务 Core 列表当前也未暴露 sort，不能仅加图标制造可排序表象。
- 移动差距：TX 卡片中的字段 dt 没有复用 description 说明入口。

## scaffold/src/components/data-table/data-table-pagination.tsx

核对已选/总数、分页大小、输入跳转和首/上/下/尾四键。

- 状态：已审查；分工：tables
- SHA-256：`2c723d224cb62a0de17dc9006a8671d8da1ca4bef5ba14ebad6eb8f644e0a5ec`
- TXBoard 对应：`web/admin/src/components/ui/TablePagination.tsx`、`web/admin/src/native/useListPagination.ts`、`web/admin/src/native/ReadList.tsx`

依据：

- scaffold/src/components/data-table/data-table-pagination.tsx:34 selected summary
- scaffold/src/components/data-table/data-table-pagination.tsx:47 10/20/30/40/50/100/500
- scaffold/src/components/data-table/data-table-pagination.tsx:58 pageInput
- scaffold/src/components/data-table/data-table-pagination.tsx:72 boundary buttons

对照结论：

- 覆盖：TX 有总数/总页数、当前页选中数、严格整数跳转与四键，加载/失权/错误会禁用元数据驱动操作。
- 契约适配：TX 只提供 10/20/50/100，优惠券与礼品卡最大 50；参考 30/40/500 不可直接传给现有读取契约。
- 边界待测：数据删除导致当前 page 大于 last_page 时组件不会自动修正，现有行数缩减需页面回到有效页；不是此次新增特有保证。

## scaffold/src/components/data-table/data-table.tsx

核对列宽、左右累积固定偏移、选择态、空/加载行、HTML5 拖拽与移动主字段。

- 状态：已审查；分工：tables
- SHA-256：`af6daff28892ac2d9a11677b1ce9aa82ac341cb59338f1be254961edc3d5184f`
- TXBoard 对应：`web/admin/src/components/ui/DataTable.tsx`、`web/admin/src/components/ui/table-details.css`、`web/admin/src/native/ReadList.tsx`

依据：

- scaffold/src/components/data-table/data-table.tsx:50 offsetOf
- scaffold/src/components/data-table/data-table.tsx:87 column widths
- scaffold/src/components/data-table/data-table.tsx:119 selected state
- scaffold/src/components/data-table/data-table.tsx:121 draggable
- scaffold/src/components/data-table/data-table.tsx:153 mobilePrimaryField

对照结论：

- 覆盖：TX 表格语义、宽度、当前页勾选/半选、固定首尾、空态，用户/订单在 600px 以下只渲染一份完整卡片和一份分页。
- 差距：TX 固定列只靠 left/right CSS 规则，没有参考对多个同侧固定列按实际宽度累积偏移的通用能力；当前一侧一列适用。
- 差距：TX DataTable 自身无通用 draggable API；可排序页面目前自行维护重排控制，应按页审查而非宣称共用表格全覆盖。

## scaffold/src/components/data-table/filter-builder.tsx

核对字段/操作符/单位、规则增删、应用与整体清除。

- 状态：已审查；分工：tables
- SHA-256：`dc0d12332f4485bf9f47d99c8e6faf699dea03900189b29312df6e1e19d2cc0c`
- TXBoard 对应：`web/admin/src/components/ui/FilterBar.tsx`、`web/admin/src/pages/users/UsersPage.tsx`、`web/admin/src/pages/finance/OrdersPage.tsx`

依据：

- scaffold/src/components/data-table/filter-builder.tsx:10 FilterFieldType
- scaffold/src/components/data-table/filter-builder.tsx:45 add rule
- scaffold/src/components/data-table/filter-builder.tsx:51 query encoding
- scaffold/src/components/data-table/filter-builder.tsx:92 rule removal

对照结论：

- 覆盖：TX FilterBar 支持条件芯片逐项删除、整体清除、提示，用户/订单保持 per_page 并重置 page。
- 未覆盖：参考任意字段/gt/lt/contains/date/boolean 规则构建器未落地；TX 用户仅邮箱精确和 banned，订单仅 user_id/trade_no/status。
- 契约限制：不能以本页数据客户端筛选冒充所有分页的高级筛选。

## scaffold/src/components/layout/app-shell.tsx

Reviewed token gate, desktop/mobile sidebar, branding, root item, collapsible groups, footer version, menu backdrop behavior and Outlet.

- 状态：已审查；分工：shell
- SHA-256：`3c60364cb15990dec47249274e64ca51c33a3c8850a6af42dda9f8e47cb3177e`
- TXBoard 对应：`web/admin/src/components/layout/AdminLayout.tsx`、`web/admin/src/components/layout/Sidebar.tsx`、`web/admin/src/components/layout/AuthGuard.tsx`

依据：

- L35 AppShell; L41 token guard; L52 fixed w64 sidebar; L90 nav groups; L149 footer version; L159 mobile button.

对照结论：

- Coverage: grouped collapsible navigation, persistent open groups/rail width, mobile overlay with dialog focus, active route, route focus/scroll recovery.
- Visual difference: TXBoard uses 88px compact rail/251px expanded sidebar and footer toolbar, while reference uses w64 sidebar and separate top toolbar. Footer version/status and configurable branding are absent.
- Reference mobile navigation does not close on every link and lacks native TXBoard focus/inert protections; copying it would regress keyboard/mobile behavior.

## scaffold/src/components/layout/header-widgets.tsx

Reviewed SearchPalette, ThemeSwitch, LangSwitch and UserNav including keyboard shortcut labels, menu behavior and identity display.

- 状态：已审查；分工：shell
- SHA-256：`70f6e4a657d34fb3908a72229543b02b0aeaf7d5e14ecf8b8b9a9d464940f13e`
- TXBoard 对应：`web/admin/src/components/layout/TopBar.tsx`、`web/admin/src/components/ui/ThemeToggle.tsx`、`web/admin/src/components/ui/Popover.tsx`、`web/admin/src/lib/dismissLayers.ts`

依据：

- L14 SearchPalette; L88 ThemeSwitch; L114 LangSwitch; L137 UserNav.

对照结论：

- Implemented this slice: explicit account popover reads identity.admin.get, offers settings/sessions/logout, handles identity retry, restores focus and closes on selection; theme menu now closes on choice.
- Coverage: Ctrl/Cmd+K, search filtering, keyboard arrow/Enter navigation, no-results state; shared viewport-aware popover with nested Escape ownership.
- Gap: language dropdown absent. Reference displayed settings/logout shortcut hints are not implemented as hotkeys by scaffold; do not claim they are functional reference shortcuts.
- Reference UserNav guesses email by atob(token prefix), which is invalid identity authority; real Core identity is deliberately retained.

## scaffold/src/components/layout/layout.tsx

Reviewed Layout, LayoutHeader inline branch, shared tools and LayoutBody dense/fixed-height layout.

- 状态：已审查；分工：shell
- SHA-256：`1b24ad658625da7d2a2caf2db5e970e348e4b92a630d844a1646c7083a8dabc4`
- TXBoard 对应：`web/admin/src/components/layout/AdminLayout.tsx`、`web/admin/src/components/ui/PageHeader.tsx`、`web/admin/src/admin-theme.css`

依据：

- L6 Layout; L32 LayoutHeader; L50 LayoutBody.

对照结论：

- Page title/body/tool controls exist but positions, gutters and card spacing differ: reference 64px top toolbar with tools above title; current tools live at sidebar footer.
- fadedBelow gradient and fixedHeight/dense wrapper flags have no general equivalent; individual scroll containers handle constrained pages. This is visual divergence, not unsupported backend contract.

## scaffold/src/components/layout/nav-config.ts

Reviewed all actual navigation entries and grouped route targets.

- 状态：已审查；分工：shell
- SHA-256：`7e47014141276708ab514a0706faae4a77662fbc8d712586cc3023bf109f7a8c`
- TXBoard 对应：`web/admin/src/navigation/core.ts`、`web/admin/src/router.tsx`、`web/admin/src/components/layout/Sidebar.tsx`

依据：

- L11 comment says 22 items, but actual navGroups contains 18 entries: 1 dashboard + 6 system + 4 network + 4 finance + 3 user.

对照结论：

- Main resource routes exist with additional Core administration pages. Traffic reset route differs: reference /user/traffic-reset-logs versus /user/traffic-reset.
- No legacy plugin menu-demo/crud-demo links in accepted Core nav; runtime module pages and redirects are explicit adaptations. Record actual routes, do not copy inaccurate reference count.

## scaffold/src/components/settings/settings.tsx

Reviewed SaveButton, SettingsSection, Field, useConfig/useSaveConfig and switch/input/textarea/select helpers.

- 状态：已审查；分工：shell
- SHA-256：`16f482f3804c1b2495f793fe6ff9ba129144078295d9a874a83979cca964c8ab`
- TXBoard 对应：`web/admin/src/native/SettingsPage.tsx`、`web/admin/src/native/ConfigurationPage.tsx`、`web/admin/src/components/ui/Field.tsx`、`web/admin/src/native/settings-reference.css`

依据：

- L14 SaveButton comment; L25 SettingsSection; L44 Field grid; L61 useConfig; L73 useSaveConfig; L87-L131 controls.

对照结论：

- Coverage: grouped settings navigation, label/help/control schema fields, read/edit/cancel/save states and pending feedback. Core metadata controls which fields and secrets exist.
- Reference source provenance issue: comment calls $f SaveButton, but original pages/config-system.md L280 identifies $f as Separator. Original settings use debounced auto-save. Neither scaffolding comment nor save wrapper proves original parity.
- Core uses versioned settings.configuration.update with explicit edit/save and credential preservation. Preserve concurrency/version and do not emulate legacy unversioned POST /config/save or auto-save.
- Visual gap: reference md label/control 1fr/360px horizontal grid versus current read-value grid followed by edit form; detailed per-category reference field review is owned by configuration audit.

## scaffold/src/components/theme-provider.tsx

Reviewed persisted light/dark/system theme context and matchMedia selection.

- 状态：已审查；分工：shell
- SHA-256：`336a538a8afdbd2d434e59355e33ac047fc8fe53b1967c7285c196dac1c4bc43`
- TXBoard 对应：`web/admin/src/lib/useTheme.ts`、`web/admin/src/components/ui/ThemeToggle.tsx`、`web/admin/src/design-tokens.css`

依据：

- L3 Theme; L19 persisted initialization; L23 root class selection; L31 setTheme.

对照结论：

- Coverage: all three preferences, persistence, system media changes and cross-component/tab synchronization. Native data-theme/key theme differs from legacy root class/key vite-ui-theme; no API contract involved.
- Reference main.tsx does not mount ThemeProvider, despite header using useTheme context; reference provider default setter is no-op. Do not copy this reconstruction integration defect.

## scaffold/src/components/ui/alert.tsx

核对默认/破坏性警告、标题描述与 role=alert。

- 状态：已审查；分工：tables
- SHA-256：`b84c613845f8e02b5cbc91611798b542636f2f3e75a14573a62c8f6e2263099e`
- TXBoard 对应：`web/admin/src/components/ui/QueryFeedback.tsx`、`web/admin/src/native/Feedback.tsx`、`web/admin/src/components/ui/ui.css`

依据：

- scaffold/src/components/ui/alert.tsx:6 alertVariants
- scaffold/src/components/ui/alert.tsx:24 role alert
- scaffold/src/components/ui/alert.tsx:28 AlertTitle/AlertDescription

对照结论：

- 覆盖：TX 错误反馈与原生管理反馈使用 alert/重试，并能区分失权和无记录。
- 实现差异：没有同名 Alert 组件，使用业务反馈组合；需以展示语义与页面实际调用判断覆盖，不能用文件名相同与否判断。

## scaffold/src/components/ui/badge.tsx

核对 default/secondary/destructive/outline 四种徽标与尺寸。

- 状态：已审查；分工：tables
- SHA-256：`84cda807322fb8c56097d67adb42a6ed2b44f8e23746a20433d3b284fd5fe83a`
- TXBoard 对应：`web/admin/src/components/ui/StatusBadge.tsx`、`web/admin/src/components/ui/ui.css`、`web/admin/src/native/commerce-reference.css`

依据：

- scaffold/src/components/ui/badge.tsx:5 badgeVariants
- scaffold/src/components/ui/badge.tsx:10 variants

对照结论：

- 覆盖：TX 状态徽标有 success/warning/danger/neutral/info，财务业务有 commerce-badge/reference-tag。
- 差异：并非所有标签统一走同一 Badge 组件，颜色/密度仍由页面样式承担；视觉一致性应继续在各页截图核验。

## scaffold/src/components/ui/button.tsx

核对六种按钮语义、大小、禁用/聚焦和 asChild。

- 状态：已审查；分工：tables
- SHA-256：`87a5a0da8903543147fb27c45e25e584c800ffcac5fad304c61bca2abff7f325`
- TXBoard 对应：`web/admin/src/components/ui/ui.css`、`web/admin/src/styles.css`、`web/admin/src/pages/users/UsersPage.tsx`

依据：

- scaffold/src/components/ui/button.tsx:6 buttonVariants
- scaffold/src/components/ui/button.tsx:11 variant list
- scaffold/src/components/ui/button.tsx:20 size list
- scaffold/src/components/ui/button.tsx:36 Slot/asChild

对照结论：

- 覆盖：TX 以原生 button/Link 和 button/primary/danger/icon-button 样式实现交互，具备禁用和可见焦点。
- 差异：没有一对一 CVA/Slot Button API；各页需继续检查按钮 type 和独立可访问名称，不能把所有调用点自动当作等价。

## scaffold/src/components/ui/calendar.tsx

核对月份导航、单日/区间、选中/今日/禁用/跨月及外部 DayPicker props。

- 状态：已审查；分工：tables
- SHA-256：`59bb2e96b65cbc8deec2a1432df31eeab4096f2bdf9a71f467879235de3b3372`
- TXBoard 对应：`web/admin/src/components/ui/Field.tsx`、`web/admin/src/native/CouponsPage.tsx`

依据：

- scaffold/src/components/ui/calendar.tsx:3 DayPicker
- scaffold/src/components/ui/calendar.tsx:10 Calendar
- scaffold/src/components/ui/calendar.tsx:15 classNames

对照结论：

- 未覆盖：TX 没有共享月历网格、双月选择或 Calendar 组件。
- 当前替代：优惠券使用原生 datetime-local；可以输入完整时间，但缺参考双月区间和快速定位交互，不应报告像素/交互等价。

## scaffold/src/components/ui/card.tsx

核对卡片 header/title/description/content/footer 分层与边框留白。

- 状态：已审查；分工：tables
- SHA-256：`d960539f3aff369765d22f3666523959aa2d32584f4bfa3c8a00b046cc675d2b`
- TXBoard 对应：`web/admin/src/components/ui/DetailSection.tsx`、`web/admin/src/native/ReadList.tsx`、`web/admin/src/components/ui/ui.css`

依据：

- scaffold/src/components/ui/card.tsx:4 Card
- scaffold/src/components/ui/card.tsx:11 CardHeader
- scaffold/src/components/ui/card.tsx:25 CardDescription
- scaffold/src/components/ui/card.tsx:38 CardFooter

对照结论：

- 覆盖：TX list-card、DetailSection、commerce-card-heading 分别承担列表、详情、编辑卡片结构。
- 差异：没有一对一 Card 原语族，复用 CSS/section；不是业务能力缺口，但不能声称通用 API 已移植。

## scaffold/src/components/ui/checkbox.tsx

核对 Radix checkbox checked/indeterminate、indicator、禁用与焦点。

- 状态：已审查；分工：tables
- SHA-256：`9b1f73d7de4918352ce4c38e04f406f4854064258fbce0459bcf5019d0675d04`
- TXBoard 对应：`web/admin/src/components/ui/Field.tsx`、`web/admin/src/components/ui/DataTable.tsx`

依据：

- scaffold/src/components/ui/checkbox.tsx:5 Checkbox
- scaffold/src/components/ui/checkbox.tsx:18 Indicator

对照结论：

- 覆盖：TX 原生 checkbox 通过 ref.indeterminate 实现当前页半选，行选择有中文可访问名称，disabled 透传。
- 差异：Field.Checkbox 是浏览器原生表现，不用参考 Radix 的 indicator 样式；视觉取决于统一 CSS 与平台。

## scaffold/src/components/ui/date-range-picker.tsx

核对范围摘要、Popover 双月 Calendar 与受控 onChange。

- 状态：已审查；分工：tables
- SHA-256：`de097b1b0b04276c94f65f592181e9f419902487d9ce6c8f20793fe74309878c`
- TXBoard 对应：`web/admin/src/native/CouponsPage.tsx`、`web/admin/src/components/ui/Field.tsx`

依据：

- scaffold/src/components/ui/date-range-picker.tsx:12 DateRangePicker
- scaffold/src/components/ui/date-range-picker.tsx:44 Calendar mode range
- scaffold/src/components/ui/date-range-picker.tsx:46 numberOfMonths

对照结论：

- 未覆盖：TX 尚无共享 DateRangePicker，也未把两时间字段组合为范围摘要弹层。
- 当前替代：优惠券起止 datetime-local 与 null=立即/长期语义可用；区间快捷选择、统一摘要、前端结束晚于开始反馈仍待补。

## scaffold/src/components/ui/dialog.tsx

核对 portal/遮罩/居中容器、标题描述、关闭控件与 footer。

- 状态：已审查；分工：tables
- SHA-256：`c712c342f0312c74a16ff75f4de96d34f8ee146100ed2e3beb1daeca0d618dd6`
- TXBoard 对应：`web/admin/src/components/ui/Modal.tsx`、`web/admin/src/lib/useDialog.ts`

依据：

- scaffold/src/components/ui/dialog.tsx:11 DialogOverlay
- scaffold/src/components/ui/dialog.tsx:29 DialogContent
- scaffold/src/components/ui/dialog.tsx:34 Portal
- scaffold/src/components/ui/dialog.tsx:44 Close

对照结论：

- 覆盖：TX Modal 有 portal、dialog/aria-modal、标题/描述、关闭按钮、焦点栈及右侧宽详情样式。
- 分工：浮层/Escape/焦点陷阱/嵌套恢复由浮层审查负责；本文件只确认表格业务页面使用该统一入口，未新增行为修改。

## scaffold/src/components/ui/dropdown-menu.tsx

核对菜单 portal、禁用项、组/分隔/快捷提示与 Radix 菜单语义。

- 状态：已审查；分工：tables
- SHA-256：`0663cfd943db9c90690a23e6422db058eb68f30896680a86386d7b3b70c9650a`
- TXBoard 对应：`web/admin/src/components/ui/Popover.tsx`、`web/admin/src/components/ui/ColumnSettings.tsx`、`web/admin/src/components/layout/TopBar.tsx`

依据：

- scaffold/src/components/ui/dropdown-menu.tsx:5 Root/Trigger/Group
- scaffold/src/components/ui/dropdown-menu.tsx:9 Content
- scaffold/src/components/ui/dropdown-menu.tsx:28 Item
- scaffold/src/components/ui/dropdown-menu.tsx:50 Separator

对照结论：

- 覆盖：列设置采用 Popover+原生带标签 checkbox；账户菜单存在单独页面组件。
- 差异：TX 没有共享 Radix menu/menuitem/roving-focus API，Popover 的 dialog 语义不能被当成完整 DropdownMenu 等价；菜单键盘由浮层/壳层审查跟进。

## scaffold/src/components/ui/input.tsx

核对 input 原生属性/ref、h-9、placeholder、focus 与 disabled。

- 状态：已审查；分工：tables
- SHA-256：`371c21218e6f4b3ce5b13b740b4b5040fba39d58577d3689714992e33daf6fb6`
- TXBoard 对应：`web/admin/src/components/ui/Field.tsx`、`web/admin/src/components/ui/ui.css`

依据：

- scaffold/src/components/ui/input.tsx:4 forwardRef Input
- scaffold/src/components/ui/input.tsx:9 control styling
- scaffold/src/components/ui/input.tsx:13 props spread

对照结论：

- 覆盖：TX Input 保留原生输入属性，Field 自动关联 label/hint/error，支持无障碍错误反馈。
- API 差异：当前 Input 函数没有显式 forwardRef；本轮表格业务没有依赖 ref 的调用；参考编辑优惠券的自动全选输入不能直接照搬到该 API。

## scaffold/src/components/ui/label.tsx

核对 label 根语义与 disabled 样式。

- 状态：已审查；分工：tables
- SHA-256：`7ea6f3f8e4011f5fe7c5f55cde6e39b9cfb8375787ef11c1d30a67c531181664`
- TXBoard 对应：`web/admin/src/components/ui/Field.tsx`

依据：

- scaffold/src/components/ui/label.tsx:5 Label
- scaffold/src/components/ui/label.tsx:9 LabelPrimitive.Root

对照结论：

- 覆盖：TX Field 生成控制 ID 并以 htmlFor 绑定，Checkbox/Switch 采用包裹 label。
- 差异：没有独立 Label 原语；覆盖由 Field 的关联语义承担。

## scaffold/src/components/ui/markdown-editor.tsx

scaffold封装react-markdown-editor-lite，默认320px，高度可配；MarkdownIt开启HTML/linkify/breaks，内部HTML视图/全屏被配置禁用。

- 状态：已审查；分工：business
- SHA-256：`132ddc9a2e6ceb3147cb219675d41fa99f5d8845a7b2dc5c83a9ab9b7de4526e`
- TXBoard 对应：`web/admin/src/components/ui/MarkdownEditor.tsx`

依据：

- scaffold/src/components/ui/markdown-editor.tsx:6 const mdParser
- scaffold/src/components/ui/markdown-editor.tsx:24 <MdEditor
- scaffold/src/components/ui/markdown-editor.tsx:32 canView

对照结论：

- [已覆盖] TX当前有格式工具、编辑/分屏/预览、B/I快捷键、maxLength与required可配置。
- [安全差异] scaffold html:true不是应无条件复用的规格，TX html:false与安全链接策略保留。
- [仍可盘点] 专业编辑器内置撤销/重做、图片/表格等是否需补应依据真实用例；未在该文件显式配置的插件功能不能仅凭库名宣称原件全量启用。

## scaffold/src/components/ui/popover.tsx

核对 portal 内容、默认对齐/偏移与背景边框。

- 状态：已审查；分工：tables
- SHA-256：`a5812873120aa5232f8021530ae3b86c577e808747bcc23f9855e07f8860f8d6`
- TXBoard 对应：`web/admin/src/components/ui/Popover.tsx`、`web/admin/src/components/ui/popover-layout.css`

依据：

- scaffold/src/components/ui/popover.tsx:5 Root/Trigger
- scaffold/src/components/ui/popover.tsx:11 align/sideOffset
- scaffold/src/components/ui/popover.tsx:12 Portal

对照结论：

- 覆盖：TX 已有受控开关、触发器名称、aria-expanded、碰撞位置与焦点恢复的说明/列设置弹层。
- 分工：完整定位/遮挡/嵌套行为由浮层审查提供验收，本组不把源级存在视为浏览器通过。

## scaffold/src/components/ui/scroll-area.tsx

核对轻实现 overflow-auto 和 overscroll-contain。

- 状态：已审查；分工：tables
- SHA-256：`853ec260a5003d22faee3dcbc4ac11eea258f8ed5f5ec3ef35d821e061d0ac25`
- TXBoard 对应：`web/admin/src/components/ui/ui.css`、`web/admin/src/components/ui/table-details.css`、`web/admin/src/native/commerce-reference.css`

依据：

- scaffold/src/components/ui/scroll-area.tsx:4 lightweight wrapper comment
- scaffold/src/components/ui/scroll-area.tsx:7 overflow-auto/overscroll-contain

对照结论：

- 覆盖：TX 表格 table-wrap、编辑正文与详情区域拥有各自 overflow。
- 差异：没有共享 ScrollArea 原语与统一 overscroll-contain；参考本身也是 div 轻实现，不是必须引入 Radix 的证据。

## scaffold/src/components/ui/select.tsx

核对 trigger/value、portal/viewport、item indicator、disabled 与聚焦。

- 状态：已审查；分工：tables
- SHA-256：`c658261d1eed4c9ca31be90ed3e189646ba5d7956c151e743f10dc6a5813b017`
- TXBoard 对应：`web/admin/src/components/ui/Field.tsx`、`web/admin/src/components/ui/ui.css`

依据：

- scaffold/src/components/ui/select.tsx:14 Trigger
- scaffold/src/components/ui/select.tsx:30 Content
- scaffold/src/components/ui/select.tsx:54 Item

对照结论：

- 覆盖：TX 原生 select 具备键盘和平台选择能力，业务标签由 Field 或 label 关联。
- 差异：没有参考自定义下拉浮层、分组 API、选中勾号和高度控制；大选项集搜索目前需业务组件单独实现。

## scaffold/src/components/ui/separator.tsx

核对横/竖分隔线与 decorative 默认值。

- 状态：已审查；分工：tables
- SHA-256：`de837bdfca14429a584762175f5bcdb20f009b6040f0b0ebce87327dd6ac6b16`
- TXBoard 对应：`web/admin/src/components/ui/ui.css`、`web/admin/src/native/commerce-reference.css`

依据：

- scaffold/src/components/ui/separator.tsx:8 orientation/decorative
- scaffold/src/components/ui/separator.tsx:13 sizing

对照结论：

- 覆盖：TX 通过 section/header/footer border 表现视觉分组，无业务数据依赖。
- 差异：没有独立可选非装饰 separator role 的共享 API；目前无需将所有边框替换成可访问分隔符。

## scaffold/src/components/ui/sonner.tsx

核对统一 toast 出口与位置/丰富色彩/关闭键。

- 状态：已审查；分工：tables
- SHA-256：`d181fc930276df3d3c94106a9a537738a467cc4efd097367e5cedeb2ccd09c4a`
- TXBoard 对应：`web/admin/src/main.tsx`、`web/admin/src/components/ui/CopyButton.tsx`

依据：

- scaffold/src/components/ui/sonner.tsx:4 Toaster
- scaffold/src/components/ui/sonner.tsx:5 top-center richColors closeButton

对照结论：

- 覆盖：TX main.tsx 已挂载 sonner richColors，复制成功/失败有 toast。
- 差异：TX 为 top-right 且未配置 closeButton，参考 top-center+closeButton；这是明确视觉/反馈策略差异。

## scaffold/src/components/ui/switch.tsx

核对 checked/unchecked、thumb 位移、禁用和 focus ring。

- 状态：已审查；分工：tables
- SHA-256：`b2244261f20144a23a88fe96c4f61f721776216590660b7d39560c0f3b7b022f`
- TXBoard 对应：`web/admin/src/components/ui/Field.tsx`、`web/admin/src/components/ui/ui.css`

依据：

- scaffold/src/components/ui/switch.tsx:5 Switch
- scaffold/src/components/ui/switch.tsx:11 checked styling
- scaffold/src/components/ui/switch.tsx:17 Thumb

对照结论：

- 覆盖：TX Switch 以原生 checkbox role=switch + label + track 实现受控 checked/disabled 和键盘操作。
- 差异：参考 Radix onCheckedChange 签名与 TX 原生 onChange 不同，迁移表单时必须显式转换。

## scaffold/src/components/ui/table.tsx

核对 Table/Thead/Tbody/Row/Head/Cell/Caption 基础语义、滚动和选择态。

- 状态：已审查；分工：tables
- SHA-256：`d1e838932c6ff0369071676e7dd39bcc308dd7431226673ac6120ec662711d15`
- TXBoard 对应：`web/admin/src/components/ui/DataTable.tsx`、`web/admin/src/components/ui/ui.css`

依据：

- scaffold/src/components/ui/table.tsx:4 Table
- scaffold/src/components/ui/table.tsx:13 TableHeader
- scaffold/src/components/ui/table.tsx:25 TableRow
- scaffold/src/components/ui/table.tsx:36 TableHead
- scaffold/src/components/ui/table.tsx:54 TableCaption

对照结论：

- 覆盖：TX DataTable 输出原生 table/thead/tbody/tr/th/td，th 有 scope=col，外层可聚焦 region，行有选中样式。
- 差异：没有独立 TableCaption 原语/prop；label 放在 region 上，数据表本身未提供 caption；视觉表格与语义可访问名称可继续统一。

## scaffold/src/components/ui/tabs.tsx

核对 Radix tablist/tab/tabpanel、active/disabled 样式与内容布局。

- 状态：已审查；分工：tables
- SHA-256：`a1e13c21f2dbe87dccc7dde0063f66565fd9a349b75d9638f5d99e93feb03c99`
- TXBoard 对应：`web/admin/src/native/GiftCardsPage.tsx`

依据：

- scaffold/src/components/ui/tabs.tsx:5 Tabs.Root
- scaffold/src/components/ui/tabs.tsx:11 TabsList
- scaffold/src/components/ui/tabs.tsx:23 TabsTrigger
- scaffold/src/components/ui/tabs.tsx:38 TabsContent

对照结论：

- 未覆盖：TX 礼品卡为单卡/批次/详情纵向区域，没有参考四段 tabs，也没有共享 Tabs 组件。
- 契约差异：不能先渲染模板/兑换码/使用/统计 tab 再用当前批次数据充数；要先明确新契约或用现有单卡/批次语义组织页面。

## scaffold/src/components/ui/tag-input.tsx

核对回车/逗号提交、失焦提交、Backspace 与逐项删除/去重。

- 状态：已审查；分工：tables
- SHA-256：`a03adf5cd89df7bbdfa66d0e5168067dbe2d16961ac88cbdadf4f97b636d363e`
- TXBoard 对应：`web/admin/src/components/ui/TagInput.tsx`、`web/admin/src/native/CatalogPage.tsx`

依据：

- scaffold/src/components/ui/tag-input.tsx:27 commit
- scaffold/src/components/ui/tag-input.tsx:50 remove
- scaffold/src/components/ui/tag-input.tsx:65 keyboard
- scaffold/src/components/ui/tag-input.tsx:74 blur

对照结论：

- 覆盖：TX TagInput 已覆盖这些交互，另有中文逗号、多项分隔、上限/长度校验、IME 保护和命名删除键。
- 集成：当前用于套餐标签；优惠券适用商品仍是逗号 ID 文本，没有因 TagInput 存在而自动变成选项多选器。

## scaffold/src/components/ui/textarea.tsx

核对 textarea 原生属性/ref、最小高度、focus/disabled。

- 状态：已审查；分工：tables
- SHA-256：`ec93e3eb84ffa29fe3f4d040f779cfb63f195a5198b8d336bb13d60b4d2c7908`
- TXBoard 对应：`web/admin/src/components/ui/Field.tsx`、`web/admin/src/components/ui/ui.css`

依据：

- scaffold/src/components/ui/textarea.tsx:4 forwardRef
- scaffold/src/components/ui/textarea.tsx:8 textarea styling
- scaffold/src/components/ui/textarea.tsx:12 props spread

对照结论：

- 覆盖：TX Textarea 原生属性与 Field 描述/错误关联可用，内容编辑另有 MarkdownEditor。
- 差异：没有显式 forwardRef API，与参考 ref 调用不能直接一一替换。

## scaffold/src/components/ui/tooltip.tsx

核对 Provider/Trigger/Portal、偏移和说明气泡样式。

- 状态：已审查；分工：tables
- SHA-256：`199cc6c26b0d6a84f178f38451b171728c13fbe43ce70131f9210b399c928971`
- TXBoard 对应：`web/admin/src/components/ui/Popover.tsx`、`web/admin/src/components/ui/DataTable.tsx`

依据：

- scaffold/src/components/ui/tooltip.tsx:5 Provider
- scaffold/src/components/ui/tooltip.tsx:9 Content
- scaffold/src/components/ui/tooltip.tsx:13 Portal

对照结论：

- 覆盖：TX 列说明是可点击可聚焦 Popover，可在触屏使用。
- 差异：没有 hover/focus Tooltip 延时 API，aria-haspopup=dialog 的点击说明不等同 role=tooltip；视觉与触发方式属于有意适配，但应记为不同。

## scaffold/src/global.d.ts

Reviewed every window.settings optional property.

- 状态：已审查；分工：shell
- SHA-256：`600b43e195f860b6af7344ee4a1257f33f9f7106771797aee46fc2be87b62901`
- TXBoard 对应：`web/admin/src/lib/basePath.ts`、`web/admin/src/api/comm.ts`、`web/admin/src/router.tsx`

依据：

- L5-L10 base_url/title/version/logo/secure_path.

对照结论：

- Core basename/guest configuration replace legacy injected secure path. Branding/title/version/logo are not fully surfaced in shell; known visual/configuration parity gap.
- No runtime behavior is proven by ambient type declarations. Optional declaration is not permission to send native operations through legacy secure_path.

## scaffold/src/lib/http.ts

Reviewed token TTL serialization/expiry removal, public endpoint allowlist, base URL, redirect, request language/auth and response unauthorized redirect.

- 状态：已审查；分工：shell
- SHA-256：`971cc080d7cb11d8f2dad7e14809738f3537eae133b0cd01c51130b92058f649`
- TXBoard 对应：`web/admin/src/native/api.ts`、`web/admin/src/native/session.ts`、`packages/core-sdk/src/admin-session.ts`、`web/admin/src/lib/basePath.ts`

依据：

- L21 storage; L41 setToken; L54 PUBLIC; L64 toSignIn; L76 axios; L82/L95 interceptors.

对照结论：

- Accepted Core session lifecycle remains authoritative. Legacy three-day localStorage Bearer token, immediate redirect on both 401/403, hash redirect and Content-Language are not drop-in parity requirements.
- Current session changes clear query/workflow/attachment/confirm state. This is required runtime behavior beyond visual reference; preserve it.
- Locale selection/Content-Language parity absent because native admin remains Chinese-only.

## scaffold/src/lib/i18n.ts

Reviewed all resources, language detector ordering, fallback and interpolation settings.

- 状态：已审查；分工：shell
- SHA-256：`bbfa67d44a5ec5ff8528cb2c98d651153b3ec98abc01db2ac7a6e0455333f682`
- TXBoard 对应：`web/admin/src/main.tsx`、`web/admin/src/components/layout/TopBar.tsx`、`web/admin/src/pages/SignInPage.tsx`

依据：

- L4-L6 resources; L8-L12 init: query lang > localStorage i18nextLng > navigator; fallback zh-CN; en-US/zh-CN/ru-RU.

对照结论：

- Missing: native admin has no equivalent i18n initialization/detection/resource pipeline. This affects all pages, not just language button.
- Resource structure audit is complete in audit-shell-locales.json, including missing and extra keys in every language.

## scaffold/src/lib/query.ts

Reviewed entire QueryClient module and checked actual application wiring.

- 状态：已审查；分工：shell
- SHA-256：`e9492cdbcc2060375b1cb6269bfb10af28cec39c3086160b51b5352ff97e98d7`
- TXBoard 对应：`web/admin/src/native/queryClient.ts`、`web/admin/src/main.tsx`

依据：

- L4-L6 retry1/no-focus-refetch client; scaffold main.tsx L11 instead creates a different new QueryClient().

对照结论：

- Reference integration defect: exported client defaults are not mounted by main, so do not report them as actual runtime defaults.
- Native client is mounted once, retries false, staleTime 30s, refetchOnWindowFocus false, mutation retry false; session lifecycle clears same client.

## scaffold/src/lib/utils.ts

Reviewed complete cn helper combining clsx and tailwind-merge.

- 状态：已审查；分工：shell
- SHA-256：`c0adf27efbfa148d5abcf99e860f8e3e6c8fc77a1f99e023c08ef4f12e6ece29`
- TXBoard 对应：`web/admin/src/admin-theme.css`、`web/admin/src/components/ui/Field.tsx`、`web/admin/src/components/ui/Popover.tsx`

依据：

- L1-L6 pure class-name utility, no data or endpoint behavior.

对照结论：

- Not applicable as direct runtime feature: native admin composes CSS classes without Tailwind utility merge. Equivalent look/behavior must be checked per component, not by importing cn.

## scaffold/src/locales/en-US.json

Parsed every namespace and leaf key: 23 namespaces, 2287 leaf keys.

- 状态：已审查；分工：shell
- SHA-256：`8cab801e16b3f3d6a5729b4c1da74ec71f25e2e6a091e592d8de808a1add8189`
- TXBoard 对应：`web/admin/src/components/layout/TopBar.tsx`、`web/admin/src/pages/SignInPage.tsx`、`web/admin/src/native/OverviewPage.tsx`

依据：

- All 23 namespaces inspected structurally; full missing/extra lists in audit-shell-locales.json.
- Root locale and scaffold locale are byte-identical for this language.

对照结论：

- Locale coverage gap: TXBoard admin uses Chinese literals and has no en-US/ru-RU language selector or translation resources.
- Reference resource quality: 34 Chinese leaf keys absent; 29 extra keys compared with zh-CN. Do not treat reference translations as complete.

## scaffold/src/locales/ru-RU.json

Parsed every namespace and leaf key: 23 namespaces, 2225 leaf keys.

- 状态：已审查；分工：shell
- SHA-256：`f5dab78c398370c36bd4bd9155d02ffd99caef84e8ff66742831beb1381de06d`
- TXBoard 对应：`web/admin/src/components/layout/TopBar.tsx`、`web/admin/src/pages/SignInPage.tsx`、`web/admin/src/native/OverviewPage.tsx`

依据：

- All 23 namespaces inspected structurally; full missing/extra lists in audit-shell-locales.json.
- Root locale and scaffold locale are byte-identical for this language.

对照结论：

- Locale coverage gap: TXBoard admin uses Chinese literals and has no en-US/ru-RU language selector or translation resources.
- Reference resource quality: 97 Chinese leaf keys absent; 30 extra keys compared with zh-CN. Do not treat reference translations as complete.

## scaffold/src/locales/zh-CN.json

Parsed every namespace and leaf key: 23 namespaces, 2292 leaf keys.

- 状态：已审查；分工：shell
- SHA-256：`a847a29495ee81b2ae868a9d827ed79d158254d1bee67be848bcbee73fc0c897`
- TXBoard 对应：`web/admin/src/components/layout/TopBar.tsx`、`web/admin/src/pages/SignInPage.tsx`、`web/admin/src/native/OverviewPage.tsx`

依据：

- All 23 namespaces inspected structurally; full missing/extra lists in audit-shell-locales.json.
- Root locale and scaffold locale are byte-identical for this language.

对照结论：

- Locale coverage gap: TXBoard admin uses Chinese literals and has no en-US/ru-RU language selector or translation resources.
- Reference resource quality: 0 Chinese leaf keys absent; 0 extra keys compared with zh-CN. Do not treat reference translations as complete.

## scaffold/src/main.tsx

Reviewed StrictMode root, actual QueryClient provider, i18n/styles import, RouterProvider and rich top-right toaster.

- 状态：已审查；分工：shell
- SHA-256：`30b787fa81320e56ec953114770628bc2fb96b60fe0a8d5367ffaf4abc02f1d7`
- TXBoard 对应：`web/admin/src/main.tsx`、`web/admin/src/native/queryClient.ts`

依据：

- L7 i18n; L11 unconfigured qc; L14-L21 root composition.

对照结论：

- Coverage: React StrictMode, query provider, router and rich top-right toasts; current lazy loading has explicit accessible pending boundary.
- Gaps: native i18n absent; styles deliberately differ. Reference ThemeProvider is never mounted and lib/query.ts client unused, both reconstruction defects.

## scaffold/src/pages/_b/shared.tsx

逐块核对 API 包装、格式化、高级规则转换、选择列、多选 facet、确认、拖拽与同步行。

- 状态：已审查；分工：tables
- SHA-256：`00eab7d7ff2f5980e59ca95f10bc9f9cb3c466986bcb217ecdbcc7813fb8c6b2`
- TXBoard 对应：`web/admin/src/native/session.ts`、`web/admin/src/native/readPresentation.ts`、`web/admin/src/native/commerce-format.ts`、`web/admin/src/components/ui/DataTable.tsx`、`web/admin/src/components/ui/FilterBar.tsx`、`web/admin/src/components/ui/ConfirmDialog.tsx`、`web/admin/src/native/workflowActions.ts`

依据：

- scaffold/src/pages/_b/shared.tsx:39 SECURE
- scaffold/src/pages/_b/shared.tsx:48 apiGet
- scaffold/src/pages/_b/shared.tsx:62 apiPostBlob
- scaffold/src/pages/_b/shared.tsx:136 applyFilterRules
- scaffold/src/pages/_b/shared.tsx:165 makeSelectColumn
- scaffold/src/pages/_b/shared.tsx:220 FacetedFilter
- scaffold/src/pages/_b/shared.tsx:341 ConfirmDialog
- scaffold/src/pages/_b/shared.tsx:406 useDragSort

对照结论：

- 覆盖：TX 通过 Core SDK/session 和 workflowActions 保留版本/幂等恢复；金额以字符串精确格式化，没有照抄参考 Number/100。
- 未覆盖：通用高级规则、可搜索多选 facet、Blob 导出、通用拖拽 hook 没有同等共享入口。
- 确认差异：参考确认按钮 await action 后才关闭，TX ConfirmDialog 当前先关闭再调 action，错误由页面管理反馈呈现；不能声称弹窗内异步错误行为相同。

## scaffold/src/pages/auth/sign-in.tsx

Reviewed login validation/request/redirect, public configuration load, auth card, reset instructions/copy, email verification cooldown and added self-service form.

- 状态：已审查；分工：shell
- SHA-256：`353b74b04631c72cce5e983e86f001fea1e8b48599b755de13e802a8c6099568`
- TXBoard 对应：`web/admin/src/pages/SignInPage.tsx`、`web/admin/src/components/CaptchaWidget.tsx`、`web/admin/src/native/session.ts`、`web/admin/src/lib/basePath.ts`

依据：

- L21-L34 original-versus-added behavior; L60 redirect; L93 guest config; L110 login; L150 card; L234 reset state; L260 send code; L274 reset submit; L302 dialog.

对照结论：

- Coverage: centered login card, email/password icons, real Core admin session, optional provider captcha, error state, pending disable, theme switch, password visibility and validated internal redirect.
- Missing reference UI: language selector and administrator reset-help dialog/command copy. TXBoard branding and glass/gradient card also differ from reference flat card and configurable title/description.
- Contract difference: reference Sanctum auth_data/localStorage and PHP artisan reset command are legacy Xboard. Core login must retain accepted session lifecycle and must not store or derive identity from that token format.
- Scaffold adds self-service /passport/auth/forget and manual pasted captcha token. Its own L29-L33 comment separates original reset instructions from reconstruction additions; original pages/sign-in.md does not establish self-service reset parity. No accepted Core admin reset operation in generated admin operation inventory.
- Current captcha configuration errors explicitly pause sign-in and offer retry. Reference silently clears commConfig on load error and login post omits captcha; do not reproduce those failure paths.

## scaffold/src/pages/config/form-dialog.tsx

公告/知识共用EditorDialog：max-w-4xl、头体脚三段、正文70vh滚动、font-mono与400px MarkdownField。

- 状态：已审查；分工：business
- SHA-256：`88905cd294d66cfe9b94c8fb35c6a0f766fd60a3fca53d7811e237c4ed024464`
- TXBoard 对应：`web/admin/src/native/ContentPage.tsx`、`web/admin/src/components/ui/MarkdownEditor.tsx`、`web/admin/src/components/ui/TagInput.tsx`、`web/admin/src/components/ui/Modal.tsx`

依据：

- scaffold/src/pages/config/form-dialog.tsx:24 export function EditorDialog
- scaffold/src/pages/config/form-dialog.tsx:20 max-h-[70vh]
- scaffold/src/pages/config/form-dialog.tsx:85 export function MarkdownField

对照结论：

- [布局差异] TX ContentPage内嵌编辑区未使用该结构，尤其长正文下保存/关闭会随整页滚动。
- [可复用] TX已有Modal portal/focus trap/inert，无需复制Radix根组件；可在业务层复用现有Modal头/脚插槽。
- [安全边界] 表单提交仍须保留现有权限/expected_version/未知结果恢复，不能用参考submit函数替换。

## scaffold/src/pages/config/knowledge.tsx

scaffold 重建实现（非原件源码）：知识库支持标题/分类/语言/Markdown 正文与显示状态编辑，编辑先读完整正文；列表支持筛选、启停、删除及全量排序。

- 状态：已审查；分工：business
- SHA-256：`14ef4781db60d57ee94b7a71abb8ef17e3eb3b1ec5d8e7f1cd7b4ebf3011048b`
- TXBoard 对应：`web/admin/src/native/ContentPage.tsx`、`web/admin/src/components/ui/MarkdownEditor.tsx`、`web/admin/src/components/ui/TagInput.tsx`

依据：

- scaffold/src/pages/config/knowledge.tsx:71 fetchKnowledgeById
- scaffold/src/pages/config/knowledge.tsx:66 LANGUAGES
- scaffold/src/pages/config/knowledge.tsx:226 <MarkdownField

对照结论：

- [已覆盖] TX 以 get 读取完整正文，提供发布范围/时间、Markdown、可见性、归档、完整排序和操作审计；保留其版本与幂等安全语义。
- [前端缺口] 参考用带语言名称的 Select，TX language 仍为自由文本输入；可以提供常用语言选项并保留自定义值，不需要新接口。
- [前端缺口] 参考分类/显示筛选与排序操作工具更直观；TX 只有标题过滤，若 Core list 不支持分类查询，必须标明当前页筛选或扩展契约。
- [布局差异] 参考编辑器是 max-w-4xl 模态并有独立滚动区，TX 是页内区块；不能把外壳风格已接近说成编辑交互完全一致。

## scaffold/src/pages/config/notice.tsx

scaffold 重建实现（非原件源码）：公告支持标题、Markdown、封面 URL、标签、显示开关，列表显示状态与批量排序；破坏操作有确认。

- 状态：已审查；分工：business
- SHA-256：`dc876c264264ee7431bd9b1d34911097b477d64ed6cc2170a537566d187b3760`
- TXBoard 对应：`web/admin/src/native/ContentPage.tsx`、`web/admin/src/components/ui/MarkdownEditor.tsx`、`web/admin/src/components/ui/TagInput.tsx`

依据：

- scaffold/src/pages/config/notice.tsx:75 function NoticeDialog
- scaffold/src/pages/config/notice.tsx:184 <TagInput
- scaffold/src/pages/config/notice.tsx:73 sortNotices

对照结论：

- [已覆盖] TX 已有公告新增/编辑、完整正文读取、Markdown、标签、封面、状态、全量排序；还包含 Core 支持的弹窗/受众/发布时间。
- [布局差异] TX 尚未复用参考 EditorDialog 的固定头/脚和70vh独立滚动正文；是否改成弹层应与编辑草稿关闭保护一起设计。
- [语义差异] 参考删除与 TX 归档不应按按钮名称强行等同；归档后的可见性/恢复能力依 Core 契约。

## scaffold/src/pages/config/payment.tsx

scaffold 重建实现（非原件源码）：支付列表读渠道、显示状态、费率与排序；新增/编辑先取提供方动态配置，支持状态切换、删除和排序。

- 状态：已审查；分工：business
- SHA-256：`b312993ec41a92f597c4aab8d6a88cfc3a37ca635047d615d89031b576fdda51`
- TXBoard 对应：`web/admin/src/native/PaymentMethodsPage.tsx`、`web/admin/src/native/PaymentMethodsPage.test.tsx`

依据：

- scaffold/src/pages/config/payment.tsx:105 function PaymentDialog
- scaffold/src/pages/config/payment.tsx:165 fetchPaymentForm(payload)
- scaffold/src/pages/config/payment.tsx:59 handling_fee_percent

对照结论：

- [已覆盖] TX 动态获取表单、商户密钥不回显、支持币种摘要、编辑聚焦、修改版本/幂等与未知结果恢复均有实现。
- [契约不支持] 参考非零费率、notify_domain、删除、专用排序接口，当前 Core admin-payment save 不能按原件字段任意扩充；TX 零手续费约束应保留。
- [原件提取限制] payment 文档仅106行且 mQt/HGt 签名截断，完整表单字段以 scaffold 补充推断，不能全部宣称来自原件完整源码。

## scaffold/src/pages/config/plugin.tsx

scaffold 重建实现（非原件源码）：插件目录按类型/安装状态筛选，提供 README、schema 配置抽屉、ZIP 上传及安装/启停/升级/卸载等生命周期入口。

- 状态：已审查；分工：business
- SHA-256：`0b21e184eeffc67f37d767bc679d6b20f8de17ecc0e9d179c92cff82e09c2f93`
- TXBoard 对应：`web/admin/src/native/ModulesPage.tsx`、`packages/core-sdk/src/contracts.generated.ts`

依据：

- scaffold/src/pages/config/plugin.tsx:175 runMutation
- scaffold/src/pages/config/plugin.tsx:593 function PluginConfigDrawer
- scaffold/src/pages/config/plugin.tsx:501 type="file"

对照结论：

- [已覆盖] TX ModulesPage 有名称/类型/版本/来源/安装/启用/健康列表、详情、操作记录和权限撤销后清理。
- [契约不支持] Core 只有 modules list/get/operations.list，缺上传、安装、启停、升级、卸载和 schema 配置写接口；当前只读边界是真实限制，不能添加假成功按钮。
- [可继续盘点] README/作者/链接等元数据若 Core 未返回，不得从参考内置示例冒充当前模块信息。

## scaffold/src/pages/config/plugin/crud-demo.tsx

scaffold 重建实现（非原件源码）：演示 schema 驱动表格与编辑/删除对话框：文本、数值、布尔、选项字段；创建/编辑使用 schema 默认值并支持搜索与排序。

- 状态：已审查；分工：business
- SHA-256：`b81c13db16dab09c44bd246004940e1ce18d00807f54ff78bfe3a5aaf745cd91`
- TXBoard 对应：`web/admin/src/native/ModulesPage.tsx`、`packages/core-sdk/src/contracts.generated.ts`

依据：

- scaffold/src/pages/config/plugin/crud-demo.tsx:74 DEMO_SCHEMA
- scaffold/src/pages/config/plugin/crud-demo.tsx:177 const [rows, setRows]
- scaffold/src/pages/config/plugin/crud-demo.tsx:218 await sleep(400)
- scaffold/src/pages/config/plugin/crud-demo.tsx:460 function SchemaField

对照结论：

- [重建范围] scaffold 的 DEMO_SCHEMA 与 seedRecords 是本地演示，保存/删除只是延时修改 React state，不是当前 Core 的可用业务。
- [未实现] TX 没有插件 schema CRUD 渲染器或该演示页。
- [契约不支持] 若扩展真实插件 CRUD，必须经现有 Core 权限/输入 schema/幂等边界；不能直接执行插件传入任意 URL。

## scaffold/src/pages/config/plugin/menu-demo.tsx

scaffold 重建实现（非原件源码）：插件菜单声明演示，修改 id/title/path/icon/label 后生成归一化 href 和侧栏预览，展示插件元数据。

- 状态：已审查；分工：business
- SHA-256：`ef747c491668cbf28d8fc4cdf416e64b8fd9c2fe63c68defa9aead3e7fb4b05c`
- TXBoard 对应：`web/admin/src/native/ModulesPage.tsx`、`packages/core-sdk/src/contracts.generated.ts`、`web/admin/src/components/layout/Sidebar.tsx`

依据：

- scaffold/src/pages/config/plugin/menu-demo.tsx:144 const [plugin, setPlugin]
- scaffold/src/pages/config/plugin/menu-demo.tsx:146 const patchMenu
- scaffold/src/pages/config/plugin/menu-demo.tsx:153 const previewItems

对照结论：

- [未实现] TX 无插件 admin_menus 贡献预览/声明编辑页。
- [重建范围] scaffold 是 DEMO_PLUGIN 的本地状态演示，不可作为模块管理菜单持久化接口。
- [架构差异] 插件声明需受导航路由与权限所有者约束，不能照参考任意拼接并注册有副作用的路由。

## scaffold/src/pages/config/system.tsx

系统设置入口只组合SystemNav、站点标题与SiteSettingsContent，不拥有独立业务写接口。

- 状态：已审查；分工：business
- SHA-256：`2e17498614354db8d416734b020321db659ce21cdd0986c18b86dc8bcbce638e`
- TXBoard 对应：`web/admin/src/native/SettingsPage.tsx`、`web/admin/src/native/ConfigurationPage.tsx`

依据：

- scaffold/src/pages/config/system.tsx:5 SystemNav
- scaffold/src/pages/config/system.tsx:6 SiteSettingsContent

对照结论：

- [已覆盖] TX按分类导航并复用ConfigurationPage。
- [范围差异] 参考侧栏有frontend/subscribe-template等未映射入口；缺口应按相应契约单独记账，不应建立可点击但无保存能力的空页面。

## scaffold/src/pages/config/system/app.tsx

scaffold 重建实现（非原件源码）：Windows/macOS/Android 三平台各提供版本与下载地址，共六个字段；原件按配置组读写并显示保存中状态。

- 状态：已审查；分工：business
- SHA-256：`6956adf5587f73ae1fafda69eca490b6ce63ddfcd1b81a6091ffeaedfacddd9f`
- TXBoard 对应：`web/admin/src/native/ConfigurationPage.tsx`、`web/admin/src/native/SettingsPage.tsx`、`web/admin/src/native/configurationPresentation.ts`、`contracts/core-api/admin-settings.v1.json`

依据：

- scaffold/src/pages/config/system/app.tsx:19 const PLATFORMS
- scaffold/src/pages/config/system/app.tsx:40 `${platform.key}_version`
- scaffold/src/pages/config/system/app.tsx:47 `${platform.key}_download_url`

对照结论：

- [已覆盖] TX metadata.app 六字段齐全，configurationSection 按平台分组，并显示编辑/修改计数与保存错误。
- [交互差异] 参考为自动保存，TX 为显式编辑/按分类提交并使用版本检查；这是写入策略差异，不应为外观一致擅自改成自动写。

## scaffold/src/pages/config/system/email.tsx

scaffold 重建实现（非原件源码）：SMTP 配置与邮件模板两区；模板按场景切换，dirty 保护、变量说明/样例/光标插入、HTML 预览、自定义重置与发送测试。

- 状态：已审查；分工：business
- SHA-256：`017e2988560b75cc75c266906e5a3208bacaa1c8b62ac195a1a8b0620d04877d`
- TXBoard 对应：`web/admin/src/native/ConfigurationPage.tsx`、`web/admin/src/native/SettingsPage.tsx`、`web/admin/src/native/configurationPresentation.ts`、`contracts/core-api/admin-settings.v1.json`、`web/admin/src/native/MailTemplatesPage.tsx`、`web/admin/src/native/MailPreview.tsx`、`web/admin/src/native/ConfigurationMail.test.tsx`

依据：

- scaffold/src/pages/config/system/email.tsx:159 function EmailSettingsTab
- scaffold/src/pages/config/system/email.tsx:331 function EmailTemplatesTab
- scaffold/src/pages/config/system/email.tsx:434 handleTabChange
- scaffold/src/pages/config/system/email.tsx:458 insertPlaceholder
- scaffold/src/pages/config/system/email.tsx:342 discardOpen

对照结论：

- [本轮补齐并针对测试] TX 增加脏草稿切换/取消确认、源码选区插入变量并保留复制；测试发送后继续保留草稿，相关测试通过。
- [已覆盖] TX MailPreview 保持 sandbox 空权限、严格 CSP、阻止外部资源；不能照原件的 HTML 直出行为弱化隔离。
- [前端缺口] TX 仍只显示变量名及必需/可选，缺变量含义/演示样例、替换样例的预览与变量插入主题；可用明确标注的演示数据完善，不冒充服务端返回值。
- [契约不支持] remind_mail_enable 不在当前 admin-settings metadata；SMTP 独立诊断接口也不在当前 Core，不能复制原件诊断成功提示。

## scaffold/src/pages/config/system/frontend.tsx

scaffold 重建实现（非原件源码）：持久化设置侧栏/顶部样式、主题配色和登录背景地址，使用显式保存。

- 状态：已审查；分工：business
- SHA-256：`b2b7a3839ebccb61b8c2ec1eed2af55773c02d36f165d3f306bcafc0df41a318`
- TXBoard 对应：`web/admin/src/native/ConfigurationPage.tsx`、`web/admin/src/native/SettingsPage.tsx`、`web/admin/src/native/configurationPresentation.ts`、`contracts/core-api/admin-settings.v1.json`、`web/admin/src/components/ui/ThemeToggle.tsx`

依据：

- scaffold/src/pages/config/system/frontend.tsx:37 useSectionConfig("frontend"
- scaffold/src/pages/config/system/frontend.tsx:48 frontend_theme_sidebar
- scaffold/src/pages/config/system/frontend.tsx:77 frontend_background_url

对照结论：

- [未实现/契约不支持] TX 无 frontend 配置组且 metadata 无上述四字段；当前三态 ThemeToggle 是本机管理端外观，不等于站点前端主题持久化配置。
- [重建质量] scaffold 该文件的部分字面文案已存在编码异常；直接复制会引入乱码，不能以视觉接近掩盖文本问题。

## scaffold/src/pages/config/system/index.tsx

scaffold 重建实现（非原件源码）：站点名称/介绍/URL/Logo/条款/停止注册，及强制HTTPS、订阅URL、工单等待、试用套餐/时长、币种信息。

- 状态：已审查；分工：business
- SHA-256：`371964c236ad00055274755561ecf871452c1546ffd8fdc52e4481d54ec0df9b`
- TXBoard 对应：`web/admin/src/native/ConfigurationPage.tsx`、`web/admin/src/native/SettingsPage.tsx`、`web/admin/src/native/configurationPresentation.ts`、`contracts/core-api/admin-settings.v1.json`

依据：

- scaffold/src/pages/config/system/index.tsx:56 function SiteSettingsContent
- scaffold/src/pages/config/system/index.tsx:60 try_out_plan_id
- scaffold/src/pages/config/system/index.tsx:154 currency_symbol

对照结论：

- [已覆盖] TX 支持 app_name/app_description/app_url/logo/tos_url/stop_register 六字段，有按组显式保存和版本检查。
- [契约不支持] force_https/subscribe_url/ticket_must_wait_reply/try_out_plan_id/try_out_hour/currency/currency_symbol不在TX当前settings写schema；不能宣称站点设置已按原件全量完成。
- [可做前端增强] URL和Logo/下载地址链接展示、帮助说明可在已授权字段上继续完善；不能自动修改部署层HTTPS行为。

## scaffold/src/pages/config/system/invite.tsx

scaffold 重建实现（非原件源码）：邀请模式、邀请生成限额/有效期、首单返佣、自动审核、提现限制/方式/关闭提现和三级分配比例等策略设置。

- 状态：已审查；分工：business
- SHA-256：`7c2e2c109dd24eb052c8b63ec44bda8dcfc1df40a99d94c0fca3edebb1f01e34`
- TXBoard 对应：`web/admin/src/native/CommissionsPage.tsx`、`web/admin/src/native/SettingsPage.tsx`、`contracts/core-api/admin-settings.v1.json`

依据：

- scaffold/src/pages/config/system/invite.tsx:30 commission_distribution_enable
- scaffold/src/pages/config/system/invite.tsx:110 commission_distribution_l1
- scaffold/src/pages/config/system/invite.tsx:124 commission_distribution_l3

对照结论：

- [语义差异] TX /config/system/invite 路由使用单级佣金策略及钱包转入审核；已有rate_bps/hold_seconds/版本/退款策略。
- [契约不支持] Core 当前不是三级分销/外部提现模型；参考 invite_force/多级分成/银行提现字段不能混入单级奖励策略。
- [需明确产品范围] 邀请码生成上限/有效期与首单策略若需实现，先补充权威模型与契约，不能仅补几个设置控件。

## scaffold/src/pages/config/system/safe.tsx

scaffold 重建实现（非原件源码）：注册与登录频率、邮箱验证/Gmail 限制/域名白名单、验证码服务与对应密钥、安全模式和安全路径。

- 状态：已审查；分工：business
- SHA-256：`5ac24b8a7cdda2cc5238e8102545fc63e843e30f54b9595000239eb28e11a95c`
- TXBoard 对应：`web/admin/src/native/ConfigurationPage.tsx`、`web/admin/src/native/SettingsPage.tsx`、`web/admin/src/native/configurationPresentation.ts`、`contracts/core-api/admin-settings.v1.json`

依据：

- scaffold/src/pages/config/system/safe.tsx:92 captchaEnable &&
- scaffold/src/pages/config/system/safe.tsx:123 captchaType === "recaptcha-v3"
- scaffold/src/pages/config/system/safe.tsx:143 recaptcha_v3_score_threshold
- scaffold/src/pages/config/system/safe.tsx:23 password_limit_enable

对照结论：

- [已覆盖] TX 已有邮箱/频率限制、三类验证码服务密钥与凭据留空保留；敏感值不回显，CAS冲突后需要重新编辑。
- [前端缺口] TX 通用 metadata 编辑器按组平铺所有验证码字段，参考只显示当前启用服务对应字段；可做条件显示但必须保留未展示服务的原配置。
- [契约不支持/名称差异] safe_mode_enable、secure_path、recaptcha_v3_score_threshold 未纳入TX metadata；password_limit*由TX login_limit*语义覆盖，不能按字面重复添加。

## scaffold/src/pages/config/system/server.tsx

scaffold 重建实现（非原件源码）：服务端 token 显示/重生成、拉取/上报间隔、WebSocket 开关及 URL；原件从站点信息组合默认地址。

- 状态：已审查；分工：business
- SHA-256：`17c5f4963c724d192f61909de66e0529513b99a70af9f23a93f8048879c1e9d5`
- TXBoard 对应：`web/admin/src/native/ConfigurationPage.tsx`、`web/admin/src/native/SettingsPage.tsx`、`web/admin/src/native/configurationPresentation.ts`、`contracts/core-api/admin-settings.v1.json`

依据：

- scaffold/src/pages/config/system/server.tsx:50 server_token
- scaffold/src/pages/config/system/server.tsx:52 server_ws_enable
- scaffold/src/pages/config/system/server.tsx:51 server_ws_url

对照结论：

- [已覆盖] TX 当前仅配置 server_pull_interval/server_push_interval/device_limit_mode。
- [契约不支持] server_token 和 server_ws_enable/server_ws_url 未在 admin-settings schema 中，不能自行生成随机token并显示已生效。
- [结构差异] 参考 URL默认构成依旧后端/site模型，TX 节点部署与 Agent通道需按自己的权威路径展示。

## scaffold/src/pages/config/system/subscribe-template.tsx

scaffold 重建实现（非原件源码）：六种订阅模板 tab（singbox JSON、clash/clashmeta/stash YAML、surge/surfboard INI），Monaco 语法、500px 编辑区与1500ms自动保存。

- 状态：已审查；分工：business
- SHA-256：`37a73ef8f7c5625a2dfdb9e594229bd99846daede048312f67ff7a97f476b66a`
- TXBoard 对应：`web/admin/src/native/ConfigurationPage.tsx`、`web/admin/src/native/SettingsPage.tsx`、`web/admin/src/native/configurationPresentation.ts`、`contracts/core-api/admin-settings.v1.json`

依据：

- scaffold/src/pages/config/system/subscribe-template.tsx:23 const TEMPLATE_TABS
- scaffold/src/pages/config/system/subscribe-template.tsx:64 <Editor
- scaffold/src/pages/config/system/subscribe-template.tsx:65 height="500px"
- scaffold/src/pages/config/system/subscribe-template.tsx:36 delay: 1500

对照结论：

- [未实现] TX 无 subscribe-template 路由/分组、六模板字段及代码编辑器，这是完整页面能力缺口。
- [契约不支持] metadata 不含 subscribe_template_*，Core settings schema不接受这些字段；不能仅建可输入但无法保存的伪完成界面。
- [实施顺序] 需先定义模板所有者/编译与校验契约，再复用tab/编辑器；必须区分模板语法校验与真实订阅输出验收。

## scaffold/src/pages/config/system/subscribe.tsx

scaffold 重建实现（非原件源码）：套餐变更、流量重置、结余折抵、新购/续费/变更事件、订阅地址和服务端信息展示。

- 状态：已审查；分工：business
- SHA-256：`994b7efdd7b3fe1e37af864d6dc7d00a34ed14c7f125ce424e2fb2375e988c5a`
- TXBoard 对应：`web/admin/src/native/ConfigurationPage.tsx`、`web/admin/src/native/SettingsPage.tsx`、`web/admin/src/native/configurationPresentation.ts`、`contracts/core-api/admin-settings.v1.json`

依据：

- scaffold/src/pages/config/system/subscribe.tsx:35 plan_change_enable
- scaffold/src/pages/config/system/subscribe.tsx:51 surplus_enable
- scaffold/src/pages/config/system/subscribe.tsx:28 subscribe_path
- scaffold/src/pages/config/system/subscribe.tsx:106 show_protocol_to_server_enable

对照结论：

- [已覆盖] TX 支持套餐变更、流量重置及三种订单事件字段。
- [契约不支持] surplus_enable、subscribe_path、show_info_to_server_enable、show_protocol_to_server_enable 未在TX metadata，结余折抵尤其不能仅前端增加开关。
- [前端缺口] 参考订单事件选项提供业务友好名称，TX metadata.select 值目前较通用，可扩展配置字典而不改变提交值。

## scaffold/src/pages/config/system/system-shared.tsx

共享设置hook处理分组读取、一次回填、防刷新覆盖草稿、debounce写入、显式save与卸载清理，导航提供桌面链接/手机select。

- 状态：已审查；分工：business
- SHA-256：`0faa08a24559e5ac715a1d5b01cec5c7ba503e750e3a4ede217cae7d2e811a36`
- TXBoard 对应：`web/admin/src/native/ConfigurationPage.tsx`、`web/admin/src/native/SettingsPage.tsx`、`web/admin/src/native/configurationPresentation.ts`、`contracts/core-api/admin-settings.v1.json`

依据：

- scaffold/src/pages/config/system/system-shared.tsx:132 export function useSectionConfig
- scaffold/src/pages/config/system/system-shared.tsx:151 if (ready) return
- scaffold/src/pages/config/system/system-shared.tsx:176 const schedule
- scaffold/src/pages/config/system/system-shared.tsx:235 export function SystemNav

对照结论：

- [已覆盖] TX不在刷新时覆盖草稿，按组保存并有权限/CAS/未知结果恢复；保存策略为显式提交。
- [前端缺口] TX分类导航在手机仍为全部链接，可评估参考的受控select来减少换行，但不能掩盖缺失类别。
- [语义差异] 不应直接复制参考1000ms自动保存，特别涉及凭据/权限字段时会改变用户授权时点。

## scaffold/src/pages/config/system/telegram.tsx

scaffold 重建实现（非原件源码）：机器人token、是否启用、自定义Webhook和讨论群链接；显式执行设置Webhook并展示目标说明与官方说明链接。

- 状态：已审查；分工：business
- SHA-256：`b5447d37be67d023be61cb931686adaa79927689ad2b5ee0dca5402c311df79f`
- TXBoard 对应：`web/admin/src/native/ConfigurationPage.tsx`、`web/admin/src/native/SettingsPage.tsx`、`web/admin/src/native/configurationPresentation.ts`、`contracts/core-api/admin-settings.v1.json`

依据：

- scaffold/src/pages/config/system/telegram.tsx:38 webhookMutation
- scaffold/src/pages/config/system/telegram.tsx:70 webhookTarget
- scaffold/src/pages/config/system/telegram.tsx:123 telegram_bot_enable

对照结论：

- [已覆盖] TX 配置组包含四字段，token write_only不回显。
- [契约不支持] TX没有设置/诊断Webhook operation，保存telegram_webhook_url不等于已向Telegram注册成功；原件按钮不能直接复制。
- [可做前端增强] 增加填写说明、目标地址来源解释和官方帮助链接，但不能泄露token或后台请求结果。

## scaffold/src/pages/config/theme.tsx

scaffold 重建实现（非原件源码）：主题列表/当前主题、截图轮播预览、ZIP拖放上传、主题schema配置、激活与删除确认。

- 状态：已审查；分工：business
- SHA-256：`c819de31d11fb122b42e6a598a639e2e478dea41266c06149bdf534e25d38f85`
- TXBoard 对应：`web/admin/src/native/ModulesPage.tsx`、`packages/core-sdk/src/contracts.generated.ts`

依据：

- scaffold/src/pages/config/theme.tsx:95 async function uploadTheme
- scaffold/src/pages/config/theme.tsx:124 async function activateTheme
- scaffold/src/pages/config/theme.tsx:463 function ThemeConfigDialog
- scaffold/src/pages/config/theme.tsx:83 setPreviewIndex

对照结论：

- [已覆盖] TX ModulesPage(kind=theme)仅提供主题模块基本信息、状态、详情和操作历史。
- [未实现/契约不支持] 当前Core无主题上传/配置/激活/删除接口，也不返回主题截图配置schema，无法前端独立补齐。
- [概念区分] ThemeToggle 三态外观是管理端偏好，不是主题市场当前主题或用户站点主题。

## scaffold/src/pages/dashboard/index.tsx

Reviewed all four dashboard blocks, metric schemas, formatting, queries, range calculation, chart series, ranking and failed-job detail.

- 状态：已审查；分工：shell
- SHA-256：`cbe6eb75fa2739831e15935b1a375a89d0034139e95f616f3b58f806a437fa1e`
- TXBoard 对应：`web/admin/src/native/OverviewPage.tsx`、`web/admin/src/native/overviewCharts.ts`、`web/admin/src/native/QueuePage.tsx`、`packages/core-sdk/src/contracts.generated.ts`

依据：

- L142-L225 stats/order/rank/queue DTOs; L227 dashboard; L315 StatsCards; L454 RevenueOverview; L683 TrafficRankCard; L829 QueueMonitor; L1140 prettyJson.

对照结论：

- Coverage: four live resource totals, recent-order sample income chart and order-status donut, node history chart, recent orders, and node list exist. These are not reference dashboard equivalence.
- Missing dashboard presentation: eight reference metric cards; amount/count revenue tabs; 7/30/90/180/365-day and custom date range; income/commission summary; node and user traffic ranking; embedded queue monitor and nested failure dialogs.
- Core contract gap: no getStats/getOrder/getTrafficRank equivalent in CORE_ADMIN_OPERATIONS. No authoritative today/month income and growth, monthly new users/growth, active/online users/devices, global upload/download totals, date-filtered income/commission series or prior-period node/user ranking.
- Current order chart reads at most 100 recent orders and correctly labels sample/currency/UTC scope; cannot produce global revenue/commission with that sample. Node history accepts only days 7/14/30 and 1-20 node IDs, UTC settled raw usage; cumulative top five within first 100 nodes is not global time-range traffic rank.
- Queue coverage exists on /system/queue rather than dashboard. Core snapshot supports nullable observed counters; failures.list is limit 1-30 without pagination, failures.get exposes id/connection/queue/job/failed_at only. Raw exception/payload, pausedMasters, throughput/runtime maxima and retention periods cannot be copied from Horizon UI.
- Polling difference: reference statistics 300s, charts/rank/queue 30s; OverviewPage has explicit manual refresh, QueuePage opt-in 30s refresh. Reviewed source, not browser acceptance.
- Reference reconstruction weaknesses: absent/error stats stay skeleton; empty/error rank renders loading; money uses JS numbers and fixed yuan; dates force UTC+8. These are not requirements to reproduce.

## scaffold/src/pages/finance/coupon.tsx

完整核对列表、类型筛选、启停/编辑/删除、创建表单、快速有效期、多选周期/套餐与批量 CSV。

- 状态：已审查；分工：tables
- SHA-256：`f327ddcfcb669e36e92710ce67dbf094513cac14d4fcb8b9206315666a381cc3`
- TXBoard 对应：`web/admin/src/native/CouponsPage.tsx`、`web/admin/src/components/ui/Field.tsx`、`packages/core-sdk/src/contracts.generated.ts`

依据：

- scaffold/src/pages/finance/coupon.tsx:108 TIME_PRESETS
- scaffold/src/pages/finance/coupon.tsx:243 CouponMultiSelect
- scaffold/src/pages/finance/coupon.tsx:359 CouponDialog
- scaffold/src/pages/finance/coupon.tsx:843 FinanceCoupon
- scaffold/src/pages/finance/coupon.tsx:1108 right pinned actions
- scaffold/src/pages/finance/coupon.tsx:1175 mobilePrimaryField

对照结论：

- 覆盖：TX 折扣+门槛、全局/单用户次数、起止、适用商品创建，显示使用/有效期/状态和复制；失权隐藏与原操作恢复在源中存在。
- 可直接补的前端缺口：移动卡片、停用操作固定、适用商品名称/可搜索选择、日期快速设置和清晰的区间错误提示。
- 契约不支持：当前创建后固定，只可停用；参考编辑/删除/重新启用、批量生成/CSV、按类型服务端筛选/排序、按周期限制均无对应操作。
- 字段语义差异：TX 比例用 basis_points，金额输入为分，适用 offer_ids；参考比例/元输入与 plan_ids/periods 不能复用原提交体。

## scaffold/src/pages/finance/gift-card.tsx

完整核对四段 Tabs、模板奖励/外观编辑、生成/CSV、兑换码状态、使用记录和全局统计；也核对参考脚手架实际当前页搜索降级。

- 状态：已审查；分工：tables
- SHA-256：`826d25d5943f7ee84f548a2ef2dba057929f17de3ee891252c513a14d9ef0ce7`
- TXBoard 对应：`web/admin/src/native/GiftCardsPage.tsx`、`packages/core-sdk/src/contracts.generated.ts`

依据：

- scaffold/src/pages/finance/gift-card.tsx:36 filter/sort ignored and current-page filtering comment
- scaffold/src/pages/finance/gift-card.tsx:165 FinanceGiftCard
- scaffold/src/pages/finance/gift-card.tsx:279 TemplatesPanel
- scaffold/src/pages/finance/gift-card.tsx:515 TemplateFormDialog
- scaffold/src/pages/finance/gift-card.tsx:767 CodesPanel
- scaffold/src/pages/finance/gift-card.tsx:951 GenerateCodesDialog
- scaffold/src/pages/finance/gift-card.tsx:1093 UsagesPanel
- scaffold/src/pages/finance/gift-card.tsx:1185 StatisticsPanel

对照结论：

- 覆盖：TX 单卡/批次分页、资金金额精确显示、兑换者/发行者/到期状态、批次内卡统计和版本化撤销。
- 可补前端：单卡与批次可用卡片在手机呈现并合并为本地两个 section/tab；原生列表的 created_at/closed_at 已返回但当前未呈现。
- 契约不支持：奖励模板、外观、生成兑换码/CSV、禁用后重新启用、使用记录/奖励倍数、全局统计不在当前管理员能力；后台不能取得发行人保管的兑换码。
- 参考自身限制：脚手架搜索模板/卡码/邮箱仅过滤当前页，不能作为全局查询标准，也不能复制到 TX 后声称全局搜索。

## scaffold/src/pages/finance/order.tsx

完整核对订单分配、十类列、金额/状态排序、类型/周期/状态/佣金多选 facet、待支付菜单与佣金状态操作。

- 状态：已审查；分工：tables
- SHA-256：`30821f6d975a39c2d4d655c99f456d3c47947116e1587a1754eaf5e9280fa379`
- TXBoard 对应：`web/admin/src/pages/finance/OrdersPage.tsx`、`web/admin/src/native/OrderReadDialog.tsx`、`web/admin/src/native/OrderAdministration.tsx`、`web/admin/src/native/orderPresentation.ts`、`packages/core-sdk/src/contracts.generated.ts`

依据：

- scaffold/src/pages/finance/order.tsx:121 AssignOrderDialog
- scaffold/src/pages/finance/order.tsx:244 FinanceOrder
- scaffold/src/pages/finance/order.tsx:342 total_amount
- scaffold/src/pages/finance/order.tsx:398 mark/cancel actions
- scaffold/src/pages/finance/order.tsx:545 toolbar facets
- scaffold/src/pages/finance/order.tsx:662 mobile trade_no

对照结论：

- 覆盖：TX 用户/订单号/状态查询、中文订单类型/周期、创建/支付时间、精确金额、复制、详情抽屉/履约与审计；移动卡片与逐项删筛选已在当前未提交源中。
- 契约差距：没有管理员分配订单、直接标记付款、修改佣金状态或基于 type/period/commission 排序/筛选操作；已有 close/retry 不能改名冒充 mark-paid。
- 注意引用源：页面 md 的 E6t 读取 user_id/order_id/commission 条件，脚手架 FinanceOrder 没有对应 useSearchParams 逻辑；两份参考本身不同，TX 仅采用原生契约允许的 URL 条件。

## scaffold/src/pages/finance/plan.tsx

scaffold 重建实现（非原件源码）：套餐编辑完整字段、标签、权限组选择并新增分组、8周期价格/基础价批量填入/清空、说明模板与Markdown预览、列表拖动排序和force_update。

- 状态：已审查；分工：business
- SHA-256：`87a6417f71eb717ff64c17cc29d6049b58d3375591563a13544ddbf383ce5ae4`
- TXBoard 对应：`web/admin/src/native/CatalogPage.tsx`、`web/admin/src/native/CatalogPage.test.tsx`、`web/admin/src/native/commerce-format.ts`

依据：

- scaffold/src/pages/finance/plan.tsx:367 function PlanDialog
- scaffold/src/pages/finance/plan.tsx:298 function PlanTagsInput
- scaffold/src/pages/finance/plan.tsx:115 fetchGroups
- scaffold/src/pages/finance/plan.tsx:168 emptyPrices
- scaffold/src/pages/finance/plan.tsx:94 force_update

对照结论：

- [已覆盖] TX 从authoritative get读取完整条款，区分Plan/Revision/Offer；标签、精确金额、访问额度、8周期价、历史与可用性编辑均实现。
- [本轮补齐并针对测试] 套餐说明增加按当前流量/限制生成模板，有正文须确认替换；原价格/分组/CAS保持不变，测试通过。
- [前端缺口] 仍使用纯group ID输入，没有现有network.groups.list可支持的友好名称选择及inline新增；可复用分组组件但需独立检查读取/写权限。
- [前端缺口] 缺周期价格一键清空与明确公式的批量草稿填充；不得照原件浮点base*months*discount造成分单位精度或自动改价。
- [契约/模型差异] 不支持旧套餐force_update及旧ID原地改价/拖拽sort模型；TX不可变修订与旧订单冻结条款不可被视觉复刻破坏。

## scaffold/src/pages/plugins/index.tsx

scaffold 重建实现（非原件源码）：按插件声明的admin_menus/admin_crud匹配通配路由，未找到/禁用分支、概览README/元数据和设置；原件i4t由schema调用CRUD list/save/delete。

- 状态：已审查；分工：business
- SHA-256：`f18044ea6ebf9de42095899238d8768357ccc01d4675e5b4cb11e82d5cc68d55`
- TXBoard 对应：`web/admin/src/native/ModulesPage.tsx`、`packages/core-sdk/src/contracts.generated.ts`、`web/admin/src/router.tsx`

依据：

- scaffold/src/pages/plugins/index.tsx:22 当前版本未实现
- scaffold/src/pages/plugins/index.tsx:159 crud ?
- scaffold/src/pages/plugins/index.tsx:169 <pre
- scaffold/src/pages/plugins/index.tsx:76 function PluginPage

对照结论：

- [原件与重建不一致] 原件文档i4t具有真实schema CRUD请求，而scaffold明确当前版本未实现、只打印JSON占位，不能把该占位当成原件全部功能。
- [未实现] TX没有/plugins/:pluginCode/*运行时页面和schema CRUD renderer；ModulesPage读取模块元数据不等于插件运行时执行。
- [契约不支持] 当前Core没有任意插件CRUD入口、README/config schema和菜单贡献协议，需要正式受控契约而非直连参考API。

## scaffold/src/pages/server/group-dialog.tsx

Read all 191 lines: reusable create/edit group dialog.

- 状态：已审查；分工：root
- SHA-256：`923d855231dea2870697d7bbaf10ca816ed0b1b478ea42317fc99ce11c27f89b`
- TXBoard 对应：`web/admin/src/native/GroupsPage.tsx`、`contracts/core-api/operations.v1.json`

依据：

- GroupDialog validates trimmed name 2-50, restricted characters; create excludes id, edit includes id; busy spinner/footer.

对照结论：

- TXBoard has real group modal with Core validation semantics; shared inline create from node/plan editor still absent.
- Do not import Number IDs or old save URL. Node scaffold inline Add Group is still a toast placeholder despite plan reusing this dialog.

## scaffold/src/pages/server/group.tsx

Read all 237 lines: group list, toolbar, columns and actions.

- 状态：已审查；分工：root
- SHA-256：`31728a6ba6ff900308950537a1fe9be06de151330dcd91cb3d1d842132e45351`
- TXBoard 对应：`web/admin/src/native/GroupsPage.tsx`、`contracts/core-api/operations.v1.json`

依据：

- GroupDialog reused toolbar and edit; users_count/server_count columns; drop confirmation; table models.

对照结论：

- TXBoard covers supported CRUD/counts/pagination; group selection/bulk operation has no equivalent Core operation.
- Original label/description/styling not yet exact; do not mark pixel parity from source coverage.

## scaffold/src/pages/server/machine.tsx

Read all 2121 lines: create/edit form, telemetry, token, install, bind, drawer and table.

- 状态：已审查；分工：root
- SHA-256：`31d459aeb7eb3086757045ee1ac47aa096a2870426864a7abac06a3d92678ea8`
- TXBoard 对应：`web/admin/src/native/NodesPage.tsx`、`web/admin/src/native/NodeDetailPage.tsx`、`web/admin/src/native/MachinesPage.tsx`、`web/admin/src/native/MachineDetailPage.tsx`、`contracts/core-api/operations.v1.json`

依据：

- MachineDialog262; thresholds143; history range369; chart658; token830; install979; BindNodes1064; nodes1248; drawer1494; table1625+.
- Token auto-hide180s, reset confirmation, history1/6/12/24h, CPU/MEM/DISK/network, associated-node actions and machine_id deep links.

对照结论：

- Core only supports list/get/update/delete/associated nodes; telemetry/credentials/create are not in operation catalog.
- Legacy NetworkMachineAdminController already implements create/credentials/rotateToken/history; reuse requires native contract/authorization/idempotency/secret response design.
- Native delete currently unbinds nodes in controller; UI text claims service may reject bound resources. This mismatch needs correction or an explicit stricter server rule.

## scaffold/src/pages/server/manage.tsx

Read all 4500 lines, including 11 protocol panels and nested advanced/network editors.

- 状态：已审查；分工：root
- SHA-256：`ab4f0b3ec454b860d1bb460759b98b2863fe5302c1389724f0fe5c9be229e762`
- TXBoard 对应：`web/admin/src/native/NodesPage.tsx`、`web/admin/src/native/NodeDetailPage.tsx`、`web/admin/src/native/MachinesPage.tsx`、`web/admin/src/native/MachineDetailPage.tsx`、`contracts/core-api/operations.v1.json`

依据：

- NodeFormValues255/emptyNodeForm285; protocolDefaults666; network JSON1009; Reality1296; panels1503-2185; cert2236/mux2398/advanced2525; NodeDialog2732; list rows3600+.
- Common form has protocol selection, defaults/cache per protocol, parent candidate restriction, binding, dynamic rate, quota, code/key/tags/groups/routes/listen/excludes/IPs and exact submit conversion.

对照结论：

- Major missing UI: complete create/edit form and every protocol_settings branch; existing detail only modifies show/enabled/machine_id.
- Protocol matrix and field/default/condition audit is documented in docs/admin-network-component-audit.md.
- Scaffold TLS cert path intentionally differs from buggy original; ECH GET helper contradicts README POST claim; reconstruct comments alone are not proof of original full form equivalence.
- Number/parseInt IDs and GB Number conversions are incompatible with TXBoard exact string IDs/amounts. Must not copy raw submit implementation.

## scaffold/src/pages/server/route.tsx

Read all 576 lines: route CRUD, conditional fields, badges, toolbar and table.

- 状态：已审查；分工：root
- SHA-256：`566faa4dda50d648ccefbc833e32b2644c2a96377d8b6e5e3491a5184bb767bc`
- TXBoard 对应：`web/admin/src/native/RoutesPage.tsx`、`contracts/core-api/operations.v1.json`

依据：

- RouteForm/emptyForm64+, action colors83+, RouteDialog and save payload, columns/search/delete.

对照结论：

- TXBoard supported action editor/confirmation/order/simulation already present; route list mobile/facet presentation can improve.
- Reference list uses all rows; native has max500 ordering and fresh-set verification. Preserve full-set safety.

## scaffold/src/pages/user/manage.tsx

完整核对账号单/批量创建、编辑、订阅密钥/删除、列表全部字段、高级规则、选中/筛选/全部作用域导出群发与封禁。

- 状态：已审查；分工：tables
- SHA-256：`cfc6ab41d8b3a7bc65a6ac22559bff20071bbb7a2cd3ac3cfa1935b83eac1ecb`
- TXBoard 对应：`web/admin/src/pages/users/UsersPage.tsx`、`web/admin/src/pages/users/UserDetailPage.tsx`、`web/admin/src/native/TrafficResetPage.tsx`、`packages/core-sdk/src/contracts.generated.ts`

依据：

- scaffold/src/pages/user/manage.tsx:182 UserDialog
- scaffold/src/pages/user/manage.tsx:428 GenerateUsersDialog
- scaffold/src/pages/user/manage.tsx:653 SendMailDialog
- scaffold/src/pages/user/manage.tsx:812 UserManage
- scaffold/src/pages/user/manage.tsx:1166 filterFields
- scaffold/src/pages/user/manage.tsx:1325 toolbar scope
- scaffold/src/pages/user/manage.tsx:1470 mobile email

对照结论：

- 覆盖：TX 单账户创建、身份/状态/角色/注册/最近登录、详情与封禁、当前订阅流量、生命周期与最近订单；复制邮箱/ID、移动卡片和筛选逐项删除在未提交源中。
- 核心缺口：列表无在线设备/套餐/权限组/流量/到期/余额/佣金，后台账户列表输出只有身份字段；不能用当前页额外查询或静态占位伪造。
- 契约不支持：批量生成、CSV、群发、批量作用域封禁、用户删除/身份编辑/重置密钥及任意规则筛选未出现在管理员操作表。
- 不可等同：详情页授权读取订阅是已有能力，参考 UserDialog 对 email/transfer_enable/is_admin/is_staff 直接写入是不同权限与数据模型。

## scaffold/src/pages/user/ticket.tsx

scaffold 重建实现（非原件源码）：工单按状态服务端分页、详情会话窗口、可折叠同状态侧栏、独立滚动消息、用户资料入口、回复和关闭确认；手机卡片字段有专门定义。

- 状态：已审查；分工：business
- SHA-256：`5f49fcb62e6b789e2044c5d3cb207550b5b8b0e8629d8ac759145aed598f9f28`
- TXBoard 对应：`web/admin/src/native/TicketsPage.tsx`、`web/admin/src/native/AttachmentsPanel.tsx`、`web/admin/src/native/SupportContent.test.tsx`

依据：

- scaffold/src/pages/user/ticket.tsx:331 function TicketConversationDialog
- scaffold/src/pages/user/ticket.tsx:350 const scrollRef
- scaffold/src/pages/user/ticket.tsx:393 scrollToBottom
- scaffold/src/pages/user/ticket.tsx:452 <DialogContent
- scaffold/src/pages/user/ticket.tsx:623 event.key === "Enter"

对照结论：

- [已覆盖] TX拥有状态/用户/归属过滤、详情、消息游标、字节限制、认领/释放、附件和审计，均走Core版本/幂等。
- [前端缺口] 本核对时TX详情仍为页内长区块，缺固定回复区/独立会话滚动；父线程正由其他代理推进此项，审查记录不将未合并修改宣称已完成。
- [交互建议] 可保留当前筛选上下文并增加草稿关闭/切换保护；不要照搬Enter直接发消息而忽略中文IME。
- [原件提取限制] 文档K8t实为用户编辑器片段而非完整会话正文，:358上下文也被截断；会话完整布局/自动滚动细节来自scaffold补充，不可混淆证据来源。

## scaffold/src/pages/user/traffic-reset-logs.tsx

完整核对日志列、触发源/类型帮助、旧上传下载流量、日期筛选/导出和小屏降级。

- 状态：已审查；分工：tables
- SHA-256：`21956eb96ccac8456ea8d2d79b8b80bc0a6e68e1b6d9b2eecf85a2a6536ed743`
- TXBoard 对应：`web/admin/src/native/TrafficResetPage.tsx`、`packages/core-sdk/src/admin.generated.ts`

依据：

- scaffold/src/pages/user/traffic-reset-logs.tsx:98 UserTrafficResetLogs
- scaffold/src/pages/user/traffic-reset-logs.tsx:115 queryParams/date_range
- scaffold/src/pages/user/traffic-reset-logs.tsx:352 applyDateRange
- scaffold/src/pages/user/traffic-reset-logs.tsx:362 exportCsv
- scaffold/src/pages/user/traffic-reset-logs.tsx:406 toolbar

对照结论：

- 明确缺口：TX 没有此日志页；现有同一路由风格的流量重置工具不显示历史清零记录。
- 契约不支持：日志读取、来源/类型/起止过滤与全量 CSV 需要原生历史读取接口，不能从一次 reset 响应重建。
- 参考自身限制：脚手架小屏只显示邮箱查询，完整来源/类型/日期控件隐藏；导出固定请求 per_page=10000 不是无限完整导出保证。

## scaffold/src/router.tsx

Reviewed every imported page and route branch: sign-in/errors, dashboard, settings children, network, finance, user and plugin wildcard.

- 状态：已审查；分工：shell
- SHA-256：`57835cf10150649c75b942aa402c8c9ba2a4f9e0ec037db11306d7ee8befb89f`
- TXBoard 对应：`web/admin/src/router.tsx`、`web/admin/src/native/routes.ts`、`web/admin/src/navigation/core.ts`、`web/admin/src/lib/basePath.ts`

依据：

- L37 createHashRouter; L47-L57 settings children; L58-L76 resource routes; L79 catch-all.

对照结论：

- Most main resource routes exist in browser router with basename and authenticated guard; Core extras include audits, sessions, queue, detail and mail template pages.
- Route parity gaps: /user/traffic-reset-logs; /config/system/frontend, server, email, telegram, app, subscribe-template nested paths; /404,/500,/503 dedicated public error routes; plugin demos. Several functions exist under different native paths but old deep links are not all aliases.
- Legacy hash URLs and native browser URLs are different deployment contracts. Plugin wildcard redirects to module manager rather than rendering arbitrary legacy plugin content.

## scaffold/src/styles/globals.css

Reviewed all light/dark HSL tokens, header height, radius, Tailwind layers, body, scrollbar and universal border rules.

- 状态：已审查；分工：shell
- SHA-256：`6062a8ce0fcd15f0399ad6db14f095fe4aee8ec37565fbc92447501073b133b8`
- TXBoard 对应：`web/admin/src/design-tokens.css`、`web/admin/src/admin-theme.css`、`web/admin/src/styles.css`、`web/admin/src/components/ui/ui.css`

依据：

- L2-L23 light; L25-L44 dark; L46-L59 Tailwind/body/scrollbar/border.

对照结论：

- Visual gap: reference neutral black/white primary, 8px radius, 64px header and explicit card borders; native blue primary, 18px cards, gradient auth background, compact sidebar and softer borderless cards.
- Dark theme exists and is live media-aware. Scrollbars are not globally hidden as in reference, supporting long table/dialog discoverability.
- Theme tokens and geometry require browser comparison for pixel acceptance; source review alone does not prove equivalence.

## scaffold/tailwind.config.ts

Read complete token bridge, radius/spacing, class dark mode and animation plugin.

- 状态：已审查；分工：root
- SHA-256：`346d4c3bae22537f0d05469e3d6cd2e71c182ff86549520b79da376735fd81c5`
- TXBoard 对应：`web/admin/src/design-tokens.css`、`web/admin/src/admin-theme.css`

依据：

- 23 lines: hsl CSS vars, radius math, header spacing and tailwindcss-animate.

对照结论：

- Actual TXBoard token paths verified below; existing visual language differs intentionally from reference. No CSS file replacement implied.

## scaffold/tsconfig.json

Read all compiler flags and alias mapping.

- 状态：已审查；分工：root
- SHA-256：`b0f12450bee05ed920b221771aab561eb3e7b5910a58095ebf5d4f23c42adb44`
- TXBoard 对应：`web/admin/tsconfig.json`

依据：

- ES2020/DOM/ReactJSX/bundler/strict/skipLibCheck/JSON alias @ -> src.

对照结论：

- No noEmit/outDir means npm build writes generated JS beside source. TXBoard configuration remains independent.

## scaffold/vite.config.ts

Read full React build config and BASE_URL override.

- 状态：已审查；分工：root
- SHA-256：`b06df0d11348d75584aa3d1f1af2355867e76ac6d3f3a4366728435e0a461823`
- TXBoard 对应：`web/admin/vite.config.ts`

依据：

- base from process.env.BASE_URL with / fallback; @ alias.

对照结论：

- Reference harness builds /app/ while TXBoard admin base is /admin/. Aliases/base must be adapted, not copied.

## tools/build-and-serve.mjs

Read all75lines: child builds, environment base, server, error and shutdown handling.

- 状态：已审查；分工：root
- SHA-256：`4265db87707015bfc224b9677b1a4c4de352d11e688df86d03834e267bf4c05c`
- TXBoard 对应：`scripts/admin-preview.mjs`、`scripts/admin-browser.mjs`、`scripts/admin-workflows-browser.mjs`

依据：

- tsc --noEmit plus ViteBASE_URL=/app/, skip-build, EADDRINUSE feedback, startServer.

对照结论：

- Reference-only harness; original dist root not included in these141files. Do not start it or replace existing TXBoard preview merely for audit.

## tools/fx.js

Read all48lines of bundle function extraction.

- 状态：已审查；分工：root
- SHA-256：`7eb5c680d41c84257c1d2a18cc968fb8c6ce5852f5dfa6f654f17258db12f8ab`
- TXBoard 对应：`scripts/admin-preview.mjs`、`scripts/admin-browser.mjs`、`scripts/admin-workflows-browser.mjs`

依据：

- findStart patterns, quote-aware bracket matching, offset/length output.

对照结论：

- Heuristic extraction can truncate closures/regex/comment braces; offset/metadata must not be treated as a complete source AST.

## tools/measure.mjs

Read all56lines of CDP measurement helper.

- 状态：已审查；分工：root
- SHA-256：`42a17f6d49df85af272ddb3f5be24a9e6f6221b3b7fc182408d17186d73e3b27`
- TXBoard 对应：`scripts/admin-preview.mjs`、`scripts/admin-browser.mjs`、`scripts/admin-workflows-browser.mjs`

依据：

- Launch headless Chromium, metrics1440x900, Runtime.evaluate, return JSON, kill process.

对照结论：

- Measurement utility only; no stored assertion or TXBoard comparison. Native event interaction requires separate browser tests.

## tools/mock-api.mjs

Read1290line fixture definitions/routes/server; inspect every registered handler and data section.

- 状态：已审查；分工：root
- SHA-256：`68ca1e749ec8bca073133e36fee431d19332ece6148876ef47a5177f097ac111`
- TXBoard 对应：`scripts/admin-preview.mjs`、`scripts/admin-browser.mjs`、`scripts/admin-workflows-browser.mjs`

依据：

- FixedT0,131 route registrations, auth seed, 8MiBbody limit, pagination, no-write success, ignored filter/sort, static roots/path containment.

对照结论：

- Dummy successful writes and no-op filters cannot establish business behavior. seedPage interpolates query target into HTML/script without script-safe escaping; trusted-local fixture only and not production code to reuse.

## tools/oci-pull.sh

Read all210lines of OCI image download/verification/export.

- 状态：已审查；分工：root
- SHA-256：`b7a400ce7173aabc45e0a2e6ed021ac94784e45c45dd0fd334b2bb9b405a739f`
- TXBoard 对应：`scripts/admin-preview.mjs`、`scripts/admin-browser.mjs`、`scripts/admin-workflows-browser.mjs`

依据：

- Challenge auth, platform manifest, blobs hash/size, concurrentxargs, OCI index/report/tar.

对照结论：

- Provenance helper, not admin UI. Not executed; floatingtag resolution is historical. Report may include image environment metadata; do not copy into frontend.

## tools/pngdiff.py

Read all138lines: PNG decode/filter reconstruction, pixel delta and diff encoding.

- 状态：已审查；分工：root
- SHA-256：`3d337871821a95f1975f0ba307542f9dbdaa56091ceb8bcc852a82ddfe096a2b`
- TXBoard 对应：`scripts/admin-preview.mjs`、`scripts/admin-browser.mjs`、`scripts/admin-workflows-browser.mjs`

依据：

- 8bit non-interlaced supported types; compares intersection, thresholdRGBsum>24; size_mismatch separate.

对照结论：

- Intersection-only ratio can hide dimension mismatch; size mismatch must fail independently. No behavioral assertions.

## tools/README.md

Read all 634 lines, including fixture limits and all nine historical visual rounds.

- 状态：已审查；分工：root
- SHA-256：`2dcf07887384758e7212b5c4b7e8c9e8a1914cff83d8b0b2be166e69dbf01e48`
- TXBoard 对应：`scripts/admin-preview.mjs`、`scripts/admin-browser.mjs`、`scripts/admin-workflows-browser.mjs`

依据：

- Documents31routes/9modal tests, fixed2024time, dummy writes, ignored filters, token differences, final4.51%page/0.77%modal ratio.

对照结论：

- Historical visual reconstruction evidence is not current TXBoard acceptance. Stale sections conflict with later rounds and code; baseline did not validate persistent writes.

## tools/regress.sh

Read all104lines:31routes,9modal scenarios and TSV reports.

- 状态：已审查；分工：root
- SHA-256：`1bddd4e06d5e155738bcbdbc2a1e5b21ba816baaf5917d091e4b191306517542`
- TXBoard 对应：`scripts/admin-preview.mjs`、`scripts/admin-browser.mjs`、`scripts/admin-workflows-browser.mjs`

依据：

- headless screenshot plus pngdiff; modal shell uses shot helper; timeouts/files checked.

对照结论：

- No numerical failure threshold/contract assertion; screenshot existence and report output alone do not prove success.

## tools/shot.mjs

Read all173lines: Chromium/CDP lifecycle, clicking/script options and screenshot capture.

- 状态：已审查；分工：root
- SHA-256：`42784b4efd7eb7bfb09211221959f155063b3b60a7575229ba7da182e3744dcd`
- TXBoard 对应：`scripts/admin-preview.mjs`、`scripts/admin-browser.mjs`、`scripts/admin-workflows-browser.mjs`

依据：

- Runtime.evaluate el.click/text matching, optional scripts, fixed viewport, openOverlays count.

对照结论：

- Synthetic el.click cannot prove hit-testing/focus/real pointer actions. Overlay count includes many data-state=open elements and is not a dialog correctness assertion.
