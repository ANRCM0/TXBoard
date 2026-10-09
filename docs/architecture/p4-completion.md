# P4 TXBoard Node 协议和流量结算交付记录

> 2026-10-09 · TXBoard 代码与自动化验证范围。**TX-Node 尚未修改，也未开展真实双端联调**。

## 已交付

- [#127](https://github.com/ANRCM0/TXBoard/pull/127)：原生 `/txapi/node/v1/*`，机器/节点 Bearer headers 身份认证，机器与节点归属校验，握手、配置和用户快照 ETag、流量强校验和 202 接收、机器清单与机器状态。HTTP-only；旧 V1/V2/WS 保留。
- [#128](https://github.com/ANRCM0/TXBoard/pull/128)：`TrafficBatchJob` 用户与日统计读取分块批量预取，保持行锁、去重、溢出保护、SQL 原子结算及 Redis 提交后通知。
- 完整线协议和上报重试语义参见 [Native Node Protocol v1](../../contracts/node-protocol/node-native-v1.md)。

## 自动化门禁和验证范围

- 现有 API SQLite、MySQL 8.4、Docker/Octane 和发布门禁。
- 身份越权：GET query/body token 不作为认证，机器 token 不能访问其他机器节点，已禁用机器/节点拒收。
- 配置与用户快照：ProtocolRegistry 输出、受限用户查询、条件 ETag/304。
- 流量：批次 ID 必须稳定，原始 unsigned bytes 校验，202 仅表示入队；既有 `v2_traffic_batch` 唯一键和 TrafficBatchJob 提供 SQL 去重、同 ID 不同 payload 不多计费、溢出事务回滚。
- 40 用户流量批次回归检查用户+统计表 SQL SELECT 次数不随人数线性增加，同时检验服务器与统计总数准确。

## 未完成/不可宣称

- **TX-Node 客户端适配和真实双端网络联调未开始**；当前未修改 TX-Node 仓库，不应将代理节点切换到 native path。
- 原生 WebSocket 尚未提供：握手的 `websocket.enabled=false` 是明确约束，旧 WS 继续运行。
- 202 并非持久账本回执：队列成功接收后的丢失/死亡需要失败队列监控和运维对账。全链路 exactly-once 交付只能在完成可靠队列/收据方案后验证。
- MySQL CI 不是生产节点吞吐负载测试，未声明稳定 p95/p99、Redis 内存、跨机真实并发和网络断连恢复指标。
- 不删除旧 node API、机器接口、WS、AccessAudit 可选插件，也不变更 TX-Node Installer。

**阶段结论：P4 的 TXBoard 服务端代码交付完成，外部 Node 适配与真实联调保留为下一方工作，不计入已验收范围。**
