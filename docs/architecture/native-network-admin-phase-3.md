# TXBoard Native Network Admin — Phase 3 (Machine)

> 开发阶段记录。TXBoard 原生管理接口为唯一官方新接口；不为 Node 保留 V2 管理兼容层。以下是源码变更及验收计划，不代表真实部署联调已经完成。

## 管理接口（动态安全路径）

全部为 `/txapi/admin/{admin_path}` 下的管理员受保护端点：

| 方法 | 路径 | 行为 |
|---|---|---|
| GET | network-machines | 分页机器列表，最大 100 条/页，输出白名单状态和服务器关联数量，不输出 Token |
| POST | network-machines | 创建机器，返回 ID、一次性展示用 Token 与安装命令，HTTP 201 |
| PUT | network-machines/{id} | 编辑名称、备注和启用状态 |
| POST | network-machines/{id}/credentials | 管理员显式读取当前凭据与安装命令，no-store、不在 GET URL 中传输 |
| POST | network-machines/{id}/token/rotate | 数据库行锁刷新 Token，返回新凭据与安装命令；限流 |
| DELETE | network-machines/{id} | 事务内解除节点绑定、删除机器，完成后发送空节点列表通知 |
| GET | network-machines/{id}/nodes | 关联节点分页及有界字段 |
| GET | network-machines/{id}/history | 负载历史限额 10–1440，时间范围 1–24 小时 |
| POST | network-machines/{id}/runtime/update | 仅允许 target=latest，委托既有 `MachineRuntimeUpdateService` 在线检查/冷却/安全派发 |

## 设计与安全

- `/api/v2/{admin_path}/server/machine/*` 全部移除，同时删除对应旧 Controller 与空的旧 V2 Admin ServerRoute 模块；Node HTTP/WS 运行时端点属于另一条协议边界，不在这批迁移范围。
- 主机列表、节点列表均使用稳定 ID 排序及后端分页，React Admin 维持现有数组式页面状态，按页有界累计且失败时显式报错。
- Token/安装命令不进列表 DTO；仅管理员明确执行敏感 POST 可取，响应 `Cache-Control: private, no-store`。旧机器数据库 Token 当前仍是可读取形式，后续凭据哈希/只展示一次改造需与 Node 注册和 Installer 一起设计；不能把此次迁移描述成完整凭据存储加固。
- 轮换 Token 修改持久化认证材料，但现有已建立的 WebSocket 会话可能仍需独立踢线/重新握手。**生产安全验收必须验证旧连接撤销语义**；待 TX-Node 端后续主动适配，不引入临时 V2 兼容通道。
- 机器 Runtime Upgrade 仍由 TXBoard 只管理发送/审计，TX-Node Installer 实际升级、回滚和执行在其独立仓库完成。
- 机器删除解除节点绑定通过事务执行；删除后向机器注册表发布空关联，避免旧数据继续用于调度。权限继续由动态 admin path + Sanctum Admin + 审计中间件校验。

## 待办

机器 Token 老连接失效联调、真实部署/负载历史验证、完整 Node 同步、备份恢复/回滚、生产镜像 smoke 均未因 CI 通过而自动关闭；见 Release Gate Issue #168。下一批开始主题/插件管理及 Agent/统计其他剩余 Admin 模块。
