# 扩展运行时安全与生命周期规范

- Plugin Package、Theme Package、Module Package、Admin Bridge 与 Module Lifecycle 的字段与操作由 `contracts/` 下相应文件管理。
- PHP 插件在 Laravel/Octane 进程内执行，**不具备进程级隔离沙箱**；仅安装可信来源。ZIP 结构检查不等于发布者或代码安全审计。
- 归档上传拒绝目录穿越、绝对路径、符号链接、冲突/重复文件、超限文件数或体积、不符合规范的 manifest / 入口文件。
- 插件依赖按声明的名称与版本约束校验；依赖缺失或未启用时禁止激活；卸载/禁用被其他模块依赖的能力应被拦截。
- 升级使用受控的 staging/backup/rename。第三方数据库迁移、PHP 任意清理逻辑和外部副作用不能保证完全自动回滚，必须提供独立恢复计划。
- 禁用或卸载后，Octane 中可能仍缓存插件已注册路由或类；**所有调用仍必须由 enabled guard 拦截**，必要时重启相关 Worker。
- Module Registry 是只读运行时投影，生命周期操作委托 PluginManager / ThemeService，不能在 Registry 直接改变安装或激活状态。
- 新的对外扩展路由须从实际插件/服务器注册表确认方法、权限和依赖；不得仅凭文档推测可调用的 URL。
- 核验：恶意归档、无效 manifest、依赖冲突、失败升级恢复、禁用后旧路由隔离、Octane Worker 重启和参考插件真实联调。

参阅 [Module Platform](module-platform-v1.md)、[Theme Package](../../contracts/theme-package/README.md)、[Plugin Package](../../contracts/plugin-package/README.md)。
