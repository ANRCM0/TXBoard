# 隔离管理订单列表联调

仅运行预先列举的 `txboard_admin_order_list_test` 空白合成MySQL库，不清空已有数据。构建锁定Gateway依赖后执行管理客户端→真实mTLS→Core→MySQL的12项检查，包含现有登录/身份/仪表盘边界和新增分页、状态4、孤儿User/Plan、私有引用缺失与当前降权拒绝。MySQL金额使用原生32位字段上界；SQLite专项单独覆盖超过53位值，不能据此宣称MySQL订单字段已扩容。

EXIT trap 清理私钥、凭据和私有配置，结果只保存脱敏receipt；没有公开管理Gateway路由、写入操作、SC-v2切换或生产放量。
