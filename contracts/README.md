# TXBoard 对外接口文档

按接入方选择接口与扩展规范：

- **[外部主题 / TXAPI 用户接口](http/theme-integration-current.md)**：公开配置、登录、账户、套餐、订单、支付、充值、订阅与工单。
- **[TXNode HTTP / WebSocket 接口](node-protocol/txnode-integration-current.md)**：节点/机器鉴权、配置同步、流量幂等上报、WebSocket 帧。
- [对外接口总览](http/external-adapter-current.md)：管理员、用户、Node/Machine、支付回调、Telegram、插件的权限边界。
- [项目开发规则与 HTTP 路由导出](../AGENTS.md)：以 Laravel 注册路由与实际运行环境为准。
- [Theme Package](theme-package/README.md)、[Plugin Package v1](plugin-package/README.md)、[Module Package v1](module-package/README.md)：扩展包格式。
- [Module Registry v1](http/module-registry-v1.md)、[Module Management v1](http/module-management-v1.md)。

Agent/MCP 已退役，不再列为接入契约；拒绝旧凭据与保留历史数据的规则统一见[退役说明](../docs/architecture/v2/admin-only-scope.md)，原协议可从 Git 历史查询。

实际可用的 URL、Method、鉴权以当前服务端路由及协议处理器为准。WebSocket 与插件动态路由须单独核实。

Core native 与跨仓协议见 [Core First v2 契约目录](v2/README.md)和[实现基线及限制](../docs/architecture/v2/current-progress.md)，按各自状态阅读。历史库存、Target/Draft 和已退役协议不构成当前接口支持承诺。
