# Credential Provider 契约入口

当前独立 Provider 拥有代理凭据秘密、协议渲染及私有持久存储；Core 拥有订阅、Terms、Subject、授权政策与用量/资金归属。Provider 不是 PHP Plugin Package 的实现，也不代表通用插件沙箱。

## 当前契约

- [真实发行与恢复](native-issuance-v1.md)：独立进程、mTLS、私有 UUID 存储、issue/query/subject-revoke 及 Core 持久回执。
- [Node 材料交付](native-node-delivery-v1.md)：Core 非秘密签名、Node 独立身份及 Provider 直接材料通道。
- [有界 Native Runtime](../native-c2-runtime-v1.md)：就绪转换、即时轮换、接收者渲染、有限授权及原周期结算。
- [Provider 生命周期](../native-provider-lifecycle-v1.md)：显式信任转换、独立可撤销链接及有限渲染授权；不宣称生产 PKI/信任分发已验收。
- [Node 离线签名诊断](node-offline-signature-proof-v1.md)、[有限窗口诊断](node-offline-window-proof-v1.md)：仅诊断库契约，不授予准入。

## 共同边界

- `access_subject_id` 及 credential/material/delivery 引用是非秘密归属，不能直接认证、换取秘密或更换结算主体。实际 operation、字段和错误以各当前契约为准，不存在可按本文自由调用的通用协议。
- 代理 UUID、password、private key、可逆凭据和可直接使用的配置只由 Provider 向授权接收方交付，不进入 Core SQL/DTO/日志/Registry。Core 会话、服务身份和订阅链接验证摘要按独立契约保护。
- 接收方绑定当前服务身份、目标/Subject/版本及有限截止。禁止任意 URL、路径、协议脚本或回调；能力声明不是业务授权。
- Core 先持久登记意图，事务外调用 Provider，再最终重验与提交。超时/断连为未知结果，查询原操作、保留原键；不重复发行/履约，不靠内存锁替代持久幂等。
- 轮换/撤销不清除原周期用量、历史归属或未确认批次。Node 验证当前信任、高水位及有限期限；Provider 成功不证明全网 Node 已执行。
- Provider 存储、密钥、Core SQL 和 Node 高水位/原批次需要一致恢复边界；不能将旧备份当作安全回滚或将秘密迁回 Core。见[数据安全规则](../../../docs/development/native-development-baseline.md)。

旧合成 Provider、旧导入目标及旧包兼容不属于当前调用面；历史原文从 Git 查询，历史验证 JSON 不作为当前运行证明。
