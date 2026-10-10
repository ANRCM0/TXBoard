# TXBoard 对外接口文档

按接入方选择接口与扩展规范：

- **[外部主题 / TXAPI 用户接口](http/theme-integration-current.md)**：公开配置、登录、账户、套餐、订单、支付、充值、订阅与工单。
- **[TXNode HTTP / WebSocket 接口](node-protocol/txnode-integration-current.md)**：节点/机器鉴权、配置同步、流量幂等上报、WebSocket 帧。
- [对外接口总览](http/external-adapter-current.md)：管理员、Agent、支付回调、Telegram、插件的权限边界。
- [项目开发规则与 HTTP 路由导出](../AGENTS.md)：以 Laravel 注册路由与实际运行环境为准。
- [Theme Package v1](theme-package/README.md)、[Plugin Package v1](plugin-package/README.md)、[Module Package v1](module-package/README.md)：扩展包格式。
- [Module Registry v1](http/module-registry-v1.md)、[Module Management v1](http/module-management-v1.md)。
- [Agent Ops v1](http/agent-ops-v1.md)、[Agent Support v1](http/agent-support-v1.md)。

实际可用的 URL、Method、鉴权以当前服务端路由及协议处理器为准。WebSocket 与插件动态路由须单独核实。
