# Extension Runtime Policy / 扩展运行时约束

本文件整合已完成的 Phase 3 插件升级与安全记录。**CURRENT** 以现有 Plugin/Theme/Module contracts 和代码为准；§/txapi/extensions§ 为尚未部署的 TARGET。

- Plugin Package v1、Theme Package v1、Admin Bridge v1/2、Module Lifecycle v1 保持生效，不能无预告破坏安装插件。
- PHP 插件在 Laravel/Octane 进程内执行，**不具有进程隔离沙箱**，仅能安装可信来源。ZIP 结构检查不等于发布者验证。
- ZIP 安全检查包括路径穿越、绝对路径、反斜线、点/空目录段、symlink、重复路径（含大小写冲突）、过大文件/文件数、manifest/入口文件结构。
- 插件 manifest require 是依赖/版本 map；支持精确、比较、caret、tilde、通配，拒绝非法格式。旧 xboard requirement 仅是历史别名，新包优先声明 txboard。
- 未安装或未启用的依赖会阻止激活，已启用的依赖者阻止上游被卸载/禁用。
- 升级使用同文件系统 staging、backup、rename；metadata 尽力恢复，但第三方迁移与任意 PHP cleanup **无法保证完整回滚**。
- 停用或卸载后，Octane 持久缓存的旧 HTTP route 必须被 enabled guard 拒绝；已加载类不可卸载，应按需重启 Octane/queue/scheduler。
- Module Registry 是只读权威投影，Lifecycle 委托既有 PluginManager/ThemeService，不重写第二套系统。
- 新插件目标路径 §/txapi/extensions/{code}/v1/*§，要声明 capability 和权限；迁移需要真实插件作者/消费者验证与版本窗口。
- 关键测试：恶意归档、错误 manifest、依赖冲突、升级失败回滚、stale route、Octane Worker 状态、AccessAudit/参考插件联调。
