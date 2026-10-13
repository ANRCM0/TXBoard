# 隔离管理用户读取联调

仅运行预先列举的 `txboard_admin_users_test` 空白合成MySQL库，不清空已有数据。构建锁定Gateway依赖后执行管理客户端→真实mTLS→Core→MySQL的19项检查，保留已验收登录、管理读取与会话边界，新增原生管理User目录/详情、精确INT余额、超过53位字节、无秘密DTO、读取前后业务行hash一致、未知User404、动态查询拒绝及当前降权。最后清理所有测试Token；私钥与凭据由EXIT trap清理。

结果只保存脱敏receipt；仅原生会话撤销，不增加业务写入、公开管理Gateway路由、SC-v2切换或生产放量。
