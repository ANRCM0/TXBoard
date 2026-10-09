# React Admin TXAPI 迁移 Batch 4：用户读取与订阅安全

范围：仅 TXBoard 主仓 Laravel、React Admin API 适配、UI；不适配 TX-Node、Gateway、第三方主题/插件与支付服务商。

- GET /txapi/admin/{admin_path}/users：分页、邮件/套餐/封禁筛选，严格排序字段白名单，余额按旧后台 UI 需要的“元”返回。
- GET /txapi/admin/{admin_path}/users/{id}：后台用户详情 DTO，保留常用编辑字段和关联名称，不返回 password/hash、token、uuid 及订阅 URL。
- GET /txapi/admin/{admin_path}/users/{id}/subscription-link：独立的管理员显式操作才取得私人订阅地址；用户批量列表不再散发订阅令牌。
- React Admin 用户列表和用户详情改用 TXAPI；用户页与详情页的“复制订阅地址”按钮即时调用新受保护资源。
- 用户更新、批量封禁、创建、删除、密钥重置、群发邮件仍走 V2，需下一轮写操作逐项迁移；此阶段不会删除其控制器或旧路由。
- 校验：动态安全路径和管理员权限、跨角色拒绝、负向输入、分页上限、排序白名单、价格单位、私密字段不外露、复制订阅链接在按钮触发时请求。通过 CI 不等同于发布/外部联调验收。

应与 [大版本收尾清单](core-release-closeout.md) 联动；之后继续迁移 Admin 写端、配置/主题、订单修改、Node/Agent 功能本体，再按内部引用清理 V2。
