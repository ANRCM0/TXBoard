# Gateway 文档维护规范

## 稳定入口与唯一状态来源

- 根 README：长期稳定的项目定位、职责、基本部署、开发入口，不复制阶段 PR 数量和测试数量。
- [文档中心](README.md)：按读者任务组织内容。
- [开发状态台账](development-status.md)：最新实现进度、CI 证据、真实环境验收限制的**唯一汇总**。
- [Contracts](../contracts/gateway-v1.md)：当前协议、身份与错误语义；未实施方案必须保持 Target / Draft 标注。
- `docs/adr-*.md`：设计决策历史；ADR 存在不等于功能实现或生产发布。

## 内容标准

1. 明确区分 **Current / Experimental / Target / Historical**。
2. `/gateway/v1` 是当前接口；`/txapi/bff/v1` 的目标设计不应被写成生产实现。
3. 变更 SDK、主题示例、认证或 API 时，更新对应实现、测试、协议和开发者文档。
4. 不在 README 多处维护 PR 列表/测试计数；在状态台账更新时注明验证证据与未验证边界。
5. 每个 Markdown 文档只有一个 H1，目录内使用相对链接；跨仓使用完整 GitHub URL。
6. 执行 `node scripts/check-docs.mjs` 检查公开文档相对路径。此工具不验证外部 URL、锚点和代码执行结果。

真实数据库、CAPTCHA、生产反代与回滚仍需独立验收；设计完成、CI 通过或合并 PR 不意味着已经放量。