# React Admin Native TXAPI — 内容管理批次（公告与知识库）

2026-10-09。TXBoard 主仓本体实现，不涉及 TX-Node、Gateway、真实支付商或第三方插件协议适配。

## CURRENT 范围

- 公告/知识库的分页查询、搜索、编辑器详情和草稿（包括隐藏内容）进入 `/txapi/admin/{admin_path}/content/*`。
- 后台新建、更新、显示切换、删除、拖拽排序均接入 TXAPI。延续独立的动态 secure_path、管理员令牌和审计，不暴露至普通用户的 `/txapi/knowledge` 接口。
- 列表的最大 `per_page=100`；前端排序视图持续分页取完整列表，不静默省略后续页。排序的 ID 去重/存在性/事务锁和错误回滚作为门禁。
- 写操作补齐 HTTP PUT/PATCH/DELETE 审计；敏感字段继续使用原 RequestLog 递归脱敏，不在控制器直接向日志复制原始请求。
- 只投影明确定义的字段；正文仅在知识库管理员详情与公告编辑需要时呈现；业务公开内容继续走独立 ContentReader。

## 边界与下一阶段

- 本批不移除旧 `/api/v2/{admin_path}/notice/*` 和 `knowledge/*`：先验证既有内部 PHP 测试/插件消费者的调用链，再分 PR 删除旧注册和完全死代码。
- 尚未原生化的 React Admin 用户管理、订单写操作、后台设置、节点/机器、主题插件与财务管理仍需逐模块迁移。
- CI 通过不等于生产数据演练、后台全域完成，也不等于外部 Node 或 Gateway 可切换。

## 门禁

SQLite、MySQL、Web、P0、镜像构建；管理员身份/secure_path 轮换、草稿可见性、分页搜索、单据编辑、排序失败回滚、日志对四类写入动词的记录，以及旧消费者未误删检查。
