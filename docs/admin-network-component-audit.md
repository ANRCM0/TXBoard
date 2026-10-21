# 节点与服务器组件审查

> 历史审查基线说明：2026-10-21 已更新参考至 `6cb42a9`，新旧差异及后续修复顺序见 [新版执行计划](admin-reference-update-plan.md)。本文旧状态不构成当前验收。

日期：2026-10-20。参考根目录为 `../references/webb/xboard-admin-rebuild`。这是源码、字段、交互和 Core 契约的差距审查，不是完整等价验收。

## 证据与主要结论

原件提取页 `pages/server-{manage,machine,group,route}.md` 已逐文件读取；重建源码 `scaffold/src/pages/server/manage.tsx` 4500 行、`machine.tsx` 2121 行、`group.tsx` 237 行、`group-dialog.tsx` 191 行、`route.tsx` 576 行均已分段读取。原件提取包含部分截断声明，深层协议细节以实际 scaffold 源码为依据，不能把注释里的“原件一致”直接当作独立验证。

TXBoard 的完整节点创建／编辑、服务器创建／凭据／安装／负载界面存在实质缺口。后端 `NetworkNodeAdminController` 已有 `save/replace/protocols`，`NetworkMachineAdminController` 已有 `create/credentials/rotateToken/history`；这些不在当前统一 Core 操作清单及管理员绑定中。应复用已有领域实现，补权威契约、输入输出投影、授权、幂等及凭据生命周期，再接前端。不能绕过 SDK 直接调用旧 REST。

## 创建／编辑节点的通用字段

依据：`manage.tsx:255` 的 `NodeFormValues`、`:285` 的默认值、`:2732` 的 `NodeDialog`、`:2963` 的提交逻辑。

| 字段／交互 | 参考默认或条件 | TXBoard 当前状态 |
| --- | --- | --- |
| 协议类型 `type` | 初始未选择；选后显示对应面板；每协议缓存设置 | 只有协议读取／展示，无选择和保存入口 |
| `name` | 空；必填 | 详情可读，patch 不允许修改 |
| `specific_key/code` | null／空；自定义身份标识 | 当前 Core 读写未开放 |
| `show/enabled` | false／null；显示与部署启用分开 | 单项及批量更新已有；Core enabled 是 boolean，没有参考 null 跟随语义 |
| `rate` | 字符串 `1`；非负数 | 缺读写和表单 |
| `rate_time_enable/rate_time_ranges` | false／空数组；每行 start/end/rate，可增加删除 | 缺读写和规则编辑器 |
| `tags` | 空数组；标签输入 | 详情可读，不能保存 |
| `transfer_enable_gb` | 空；提交转换为字节，零表示未设 | Core 节点投影缺额度；未来须用精确换算，不能复制 Number 大值转换 |
| `banned` | false；与 show/enabled 不同字段 | 当前投影和修改缺失 |
| `excludes/ips` | 空数组 | 缺读写／编辑 |
| `group_ids` | 空；多选，可搜索 | 详情仅 ID；缺节点组关联修改；参考“添加权限组”仍是 toast 占位 |
| `route_ids` | 空；多选路由组 | 详情仅 ID；缺节点关联修改 |
| `host/port/server_port` | 空；连接地址及两种端口必填；同步端口按钮 | 可读；不能修改；不能把范围端口强制转换为单个数值 |
| `listen_address` | 空；监听地址 | 缺读写 |
| `parent_id` | 字符串 `0`；候选为同协议、无父级、排除自身；子节点条件分支 | 可读，缺修改；Core 使用 null 表示无父节点，不能复制字符串零 |
| `machine_id` | null；可从服务器深链预填；活动服务器选择 | 详情／批量绑定已有；本轮补分页选择及读取失败拦截 |
| `protocol_settings` | 空对象；选协议补默认值，编辑合并已有配置 | 当前 Core 不返回和保存完整配置 |
| `custom_outbounds/custom_routes` | 空数组；JSON 编辑和数组校验 | 缺读写与高级编辑 |

参考弹窗为固定头部协议选择、可滚动字段区、独立页脚高级设置和提交。切换协议保留各自草稿；编辑回填会解析字符串或对象形式的协议设置。TXBoard 当前没有这套弹窗，不能把详情页几个按钮视为已覆盖。

## 11 种协议面板

以下均是已读 scaffold 的字段／条件；当前 Core 缺完整 `protocol_settings` 读写，所有面板都尚未接入 TXBoard。

| 协议 | 面板及条件 | 参考默认与辅助交互 |
| --- | --- | --- |
| Shadowsocks | cipher、plugin、plugin_opts、client_fingerprint | aes-128-gcm；7 种 cipher；None 及 6 种插件；可搜索 cipher |
| VMess | TLS 0/1；TLS 时 SNI/allow_insecure/uTLS/ECH；network/network_settings | TLS 0、TCP；ws/grpc/h2/httpupgrade/xhttp 等 JSON 模板 |
| VLESS | TLS 0/1/Reality；Reality 密钥/short_id/fingerprint；flow；独立加解密开关与双方参数；network | TLS 0、TCP；还支持 kcp；按 TLS 档展开不同字段；密钥生成和复制 |
| Trojan | TLS/Reality；SNI/allow_insecure；uTLS/ECH 常驻；network | TLS 1、TCP；不能照搬 VMess 的显示条件 |
| Hysteria | v1/v2；ALPN；混淆开关／类型／密码；上下行带宽；TLS/ECH；跳端口间隔 | v2、h2；版本切换对应不同字段 |
| TUIC | v4/v5；拥塞控制；ALPN 多选；UDP relay；TLS/ECH | v5、bbr、h3、native |
| AnyTLS | padding_scheme、多行默认方案；TLS/ECH | 可填默认 padding；数组保存 |
| SOCKS | 无协议专用字段；仍有共同路由配置 | 不能因面板空而跳过通用表单 |
| HTTP | TLS 档与对应 TLS 配置 | TLS 0；支持高级证书配置 |
| Naive | TLS 档与对应 TLS 配置 | TLS 0；与 HTTP 的标签和类型分别保留 |
| Mieru | transport、traffic_pattern | TCP／UDP；模式生成器 |

高级设置有 TLS、多路复用、自定义 Outbounds、自定义 Routes 四面板。证书分 none/http/dns/self/content，条件展开 domain/email/DNS 环境、HTTP 端口或证书／密钥内容。多路复用仅 VMess/VLESS/Trojan，包含 smux/yamux/h2mux、连接数、padding 和 brutal 上下行带宽。uTLS 有 fingerprint；ECH 有开关、配置、查询名、密钥生成。传输 JSON 编辑器有模板、解析错误和确认写回。

注意：重建稿明确修正了原件证书路径错误，且保留了原件 multiplex.enabled=0 被渲染为文本的怪癖；这些都不应机械复制。README 说 ECH POST，实际 `generateEchKey` helper 使用 GET，也需以真实契约定义取代注释。

## 节点列表与详情

已覆盖：名称、字符串 ID、协议、地址、服务器归属、显示与启用、精确流量、详情链接、分页、列设置；当前页批量启用／禁用／显示／隐藏／绑定／删除／重置；单节点复制和删除；7/14/30 天已结算原始流量图。

剩余前端缺口：参考在线／可用状态点、倍率／权限组／部署列、可搜索多选 facets、URL machine_id/open_create 联动、完整节点排序。Core 排序操作已存在，但最多 100 项。必须明确完整集合和并发变化语义；不能将搜索或分页子集冒充全量排序。

本轮修复：绑定服务器弹窗可独立翻页；500/403 后隐藏缓存选项且锁定写入；补批量显示；异步操作加挂载／视图／会话检查，A 节点慢删除返回后不会把新打开的 B 详情跳回列表。保留原操作 pending 和幂等恢复。

## 服务器列表、创建与详情

参考 `machine.tsx`：创建／编辑名称、备注、is_active；概览总数／在线／离线失联／高负载／承载节点；状态与承载 facet；负载单元格；右侧详情抽屉及 machine_id 深链。

详情包括概况、1/6/12/24h 历史 CPU／内存／磁盘／网络图、即时负载、Token、安装命令、关联节点。Token 主动读取，180 秒自动隐藏，重置需确认；安装命令可复制；关联节点可启停／解绑／绑定，并跳到带预填服务器的节点创建入口。

TXBoard 已有名称／备注／管理状态／镜像通道修改、最近心跳、绑定节点数、分页节点列表、删除。缺创建、凭据及轮换、安装命令、负载与趋势、关联节点区内操作和完整深链联动。管理状态不能直接称“在线”；节点日流量图也不能代替服务器负载历史。

本轮修正了一处语义错误：原 UI 描述“有绑定资源时服务端会拒绝删除”，但 `NetworkMachineAdminController.delete` 实际会解绑节点后删除服务器。现在危险区和删除确认均明确“绑定节点解除服务器归属、节点本身保留”，与当前服务端行为对应。

## 分组与路由

分组已覆盖真实创建／编辑／删除、用户／套餐／节点计数和分页。参考组名限制 2–50 字符及有限字符集，Core 是非空至 255；后者是当前有效契约，不能直接缩窄已有名称。待补共享名称选择及节点／套餐表单内联创建流程。

路由已覆盖 CRUD、四种动作、条件参数、启用状态、完整集合排序和只读模拟；排序前重新读取并验证集合，保留精确字符串 ID。剩余主要是显示密度、多选 facet 和移动呈现。参考默认 block、TXBoard 默认 direct 属明确差异；应按产品语义决定。参考 action_value 不应混同统一规则字符串；当前契约限制最多 200 项匹配、500 条排序须保留。

## 逐文件与运行证据

本组 35 个文件（网络源码／原件页、元数据、构建配置、锁文件、工具）记录在 `artifacts/admin-reference-details/audit-root.json` 和 `audit-root.md`。其中 `meta/components.json` 的 266 个组件及 33 棵递归树另保存为 `audit-meta-components.json`。锁文件以结构化方式读取全部 342 条 package 记录，并核对依赖根映射。工具源码已检查，未执行 OCI 拉取或参考写入服务。

参考截图比对的 4.51%／0.77% 是原件对重建稿的历史结果，不是 TXBoard 验收。参考 fixture 写入固定返回成功且不保存，筛选／排序多数被忽略，因此不能用它证明任何真实写入或筛选正确性。
