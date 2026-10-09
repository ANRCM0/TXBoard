# TXBoard Extension Admin — Phase 4 (Theme / Plugin)

> 开发阶段变更：TXAPI 为唯一官方管理接口。此次仅迁移 TXBoard 自带的 Theme/Plugin 管理功能，不为 V2 管理路由保留兼容层。

## Native admin surface

在 `/txapi/admin/{admin_path}` 下，需要登录且具有管理员权限：

| 方法 | 路径 | 功能 |
|---|---|---|
| GET | themes | 获取主题、活跃主题 |
| GET / PUT | themes/{name}/config | 获取 / 保存配置 |
| POST | themes/upload | 上传 ZIP（最大 10 MB，ThemeService 完整检查） |
| DELETE | themes/{name} | 删除非系统、非活跃主题 |
| GET | plugins/types | 插件类型列表 |
| GET | plugins | 插件目录，支持 type 筛选 |
| GET / PUT | plugins/{code}/config | 已安装插件配置 |
| POST | plugins/{code}/actions/{action} | install、uninstall、enable、disable、upgrade |
| POST | plugins/upload | ZIP 上传（最大 10 MB，PluginManager 完整检查） |
| DELETE | plugins/{code} | 删除非核心插件 |

协议错误为标准 TXAPI `error.code`，每次操作沿用动态管理路径、管理员鉴权、写入审计与请求 ID。不返回内部异常信息。主题与插件标识符使用白名单，防止路径操作冒险。插件安装/升级会运行插件拥有的 PHP 代码与 migrations，不是沙箱行为；应当只允许经过审查和信任的插件包进入管理员上传环节。

### Boundary and rollback

- 主题 ZIP 安全检查（ZIP slip/符号链接、manifest、版本）以及升级期间 staging/backup/restore，复用当前 `ThemeService`，未重造第二套提取器。
- 插件归档校验、依赖检查、manifest 身份校验以及文件阶段性备份/恢复，复用 `PluginManager`。**插件升级执行迁移时对 MySQL DDL 和外部副作用不能保证事务式完全回滚**，必须通过隔离环境备份/实际恢复验收；这不是完全热回滚能力。
- 管理员插件动态页面/其自定义 CRUD API 不是被清理的 V2 Theme/Plugin 控制面；仍通过独立插件接口运行，由插件功能自己的鉴权契约约束。
- 旧 V2 `theme/*`、`plugin/*` 管理路由与控制器删除，保留 `module/*` 模块注册管理。
- React Admin 官方 Theme 模块调用 `theme.ts`，Plugin 官方管理调用独立 `plugin-admin.ts`；插件业务路由 `plugin.ts` 仍为混合动态调用方，不能将其标记成完全原生模块。
- 全部完成只是源码及 CI；staging ZIP 上传、插件升级失败回滚、完整审计保护与真实运行时执行必须在 release gate 中完成。

## Next

推进 Agent 管理、Stats/Analytics 与剩余 V2 路由精简，最终运行 `node scripts/native-admin-release-audit.mjs --strict` 并完成生产模拟环境与数据备份恢复验收。
