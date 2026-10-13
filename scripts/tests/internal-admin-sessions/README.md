# 隔离管理会话联调

仅运行预先列举的 `txboard_admin_sessions_test` 空白合成MySQL库，不清空已有数据。构建锁定Gateway依赖后执行管理客户端→真实mTLS→Core→MySQL的17项检查，保留已验收登录、读取与套餐边界，新增原生owner会话列表、跨owner/重复撤销404、当前路径/降权拒绝、审计503隐藏已提交退出、刷新鉴权及成功退出。最后显式清理所有测试Token；私钥与凭据由EXIT trap清理。

结果只保存脱敏receipt；仅原生会话撤销，不增加业务写入、公开管理Gateway路由、SC-v2切换或生产放量。
