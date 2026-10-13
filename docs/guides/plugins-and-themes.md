# 插件、主题与 Module Platform

TXBoard 的扩展体系基于 **Plugin、Theme 与 Module Platform**。Runtime 的唯一状态来源是 TXBoard 本身；一个包自称“已安装/已启用/健康”不能替代服务端实际检查。

## 选择扩展类型

| 类型 | 适合 | 主要约束 |
| --- | --- | --- |
| Plugin | PHP 后端扩展、可选管理 UI | 可信进程内 PHP；不是沙箱 |
| Theme | 用户端主题及可选静态前端 | 活跃主题的唯一设置是 `frontend_theme` |
| Module Registry | 统一只读发现、能力和健康目录 | Registry 不允许在读操作中执行安装/卸载 |
| Module Lifecycle | 受权限控制的插件/主题生命周期委托 | 修改操作由对应专有运行时处理 |

## 安装前与升级时

1. 核对包来源、manifest、兼容版本、依赖与资源限制。
2. 检查插件的后端可执行权限；即使 ZIP 路径校验通过，也不意味着插件可信。
3. 在预发环境验证坏包、依赖冲突、主题切换、禁用后的旧路由和 Octane/Worker 缓存。
4. 执行升级前保留**可恢复的数据库和文件备份**。第三方 PHP 副作用无法保证自动回滚。
5. 对跨仓包接口的破坏性变化，应明确升级版本，不默默改变现有 v1 兼容语义。

## 规范入口

- [插件开发入门](../development/plugin-development.md)
- [Plugin Package v1](../../contracts/plugin-package/README.md)
- [Theme Package v1](../../contracts/theme-package/README.md)
- [Module Package v1](../../contracts/module-package/README.md)
- [Module Platform 架构](../architecture/module-platform-v1.md)
- [安全基线](../security/README.md)

可选的 [TXBoard-Gateway](https://github.com/ANRCM0/TXBoard-Gateway) 是独立用户主题 API BFF，并非 Plugin/Theme Registry；Agent/MCP 已退役。