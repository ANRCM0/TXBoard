# Upgrade Manifest：跨组件升级与恢复

状态：**完整平台升级 Target / Draft；隔离影子 profile 已实现**。协议标识为 `txboard.upgrade-manifest/v1`；目录 v2 表示平台架构代际。Deploy 新增 `upgrade` 执行器，仅支持下述隔离演练切片；当前 `update.sh` 继续作为旧更新路径，并遵守新持久升级保护标记。

## 已实现的单机影子 profiles

`subscription-shadow/v1` 增加必填 `profile` 和 `billing` 字段，后者包含已核实的 `currency` / `billing_timezone`。仅接受 `isolated_shadow`、四阶段 `expand/backfill/verify/observe`、schema epoch 1（legacy authority）、固定的三项 migration/probe/recovery 组合与目标 OCI digest。支持签名 `plan/check/apply/status/recover/restore-check`，没有生产 authority switch、集群租约或自动整库恢复。完整字段、RSA detached envelope 和实测证据见 [Deploy 运行契约](https://github.com/ANRCM0/TXBoard-Deploy/blob/main/docs/durable-upgrade.md)。

此 profile 使用单机 flock、SQLite FULL 意图/结果及单调执行 token，Core 每块数据与游标同事务提交。所有页逐户对账后仅标记演练完成，停止状态和旧镜像启动保护仍保留。部分 DDL 形状不明时保持调查；已完整 DDL 或回填批次 commit 后丢失执行器结果可探测并恢复。真实 Docker/MariaDB 的批次 commit 后 SIGKILL 演练通过，但它不满足下文整个 B2 的生产/集群退出门槛。

| 已实现 profile | 捕获与核对范围 | Deploy 提交 |
| --- | --- | --- |
| `subscription-shadow/v1`（B2.1） | 稳定订阅、Terms、周期和 Subject 映射；全部用户分页核对 | `cf511b9`、`3f31744` |
| `plan-catalog-shadow/v1`（B2.2） | 当前 Plan revision/offers 与销售状态；全部当前商品分页核对 | `617cb93` |
| `commerce-shadow/v1`（B2.3） | wallet/commission 期初平衡分录与当前订单证据；user/order 双游标分页核对 | `ac9b811` |

三个 profile 的 migration/probe/recovery ID 各自封闭，不能混用。商品和财务 profile 仍使用固定的 expand/backfill/verify/observe、legacy authority 与停止副本；都已通过真实固定 Core/MariaDB 的批次 commit 后 SIGKILL 恢复，重复映射或期初分录为零。财务证据不代表真实付款或退款账本，商品证据不验证 superseded history。完整命令、固定制品和测试限制见 [Deploy 运行契约](https://github.com/ANRCM0/TXBoard-Deploy/blob/main/docs/durable-upgrade.md)。

## 1. 当前能力和边界

`TXBoard-Deploy/update.sh` 已有本机 flock、容器归属检查、原生 `tx_*` 表预检、停机备份、checksum/gzip 校验、运行旧镜像 ID 保存、Laravel migrate 与关键用户/订单金额总数校验。尝试迁移后失败会停机，明确禁止直接启动旧镜像。其 phase 和 migrate 标记为进程内变量，不能证明 SIGKILL/主机崩溃后的阶段；备份校验也不等于恢复演练。现有脚本主要协调一个 TXBoard 服务，尚不负责 v2 跨角色矩阵、持久检查点和前向恢复。

B2 在这些保护之上增量实现，不能绕过现有 native-only 检查。旧 `v2_*` 数据仍使用独立离线导入流程，混合、未知或缺失 migration history 的数据库不得自动迁移。

## 2. Manifest 字段与验证

Manifest 是发布方提供的非秘密、不可变数据；不得包含命令行脚本、任意 SQL、私钥、密码、令牌或任意下载执行地址。迁移 ID 和探测 ID 必须对应 Deploy/Core 版本内置的允许清单，不能让第三方包获得通用 shell。先按协议 Schema 校验，再验证发布签名和制品摘要，最后校验运行环境；未知必填字段、未知契约主版本和不受支持迁移都拒绝。签名是外部 detached envelope，绑定原始 Manifest 摘要及可信发布公钥 ID；SHA-256 完整性不能替代可信来源验证。

| 字段 | 语义与必需校验 |
| --- | --- |
| `manifest_version`、`release_id`、`created_at` | 协议固定标识、唯一发布 ID、UTC Unix 秒安全整数；重新发布不同内容必须用新 ID |
| `components` | Core/Gateway/Deploy/Node、Frontend、可选 Plugin/Installer 的具体目标制品、版本及 required 标志；制品须固定 OCI digest 或归档 SHA-256 |
| `compatibility` | 明确的已验证版本组合、schema 读写 epoch、Internal API/SC/Node/Provider 协议及 required capabilities；不是仅比较某个 semver |
| `schema` | 当前允许来源 epoch、过渡 epoch、最终 epoch、原生表约束；epoch 是 Core schema 能力版本，不是 Laravel migration batch number |
| `migrations` | 按依赖排序的内置迁移 ID、阶段、来源/目标 epoch、checkpoint/probe、可恢复类型和不变量校验 ID |
| `upgrade_policy` | 角色顺序、允许滚动与否、排空条件、写入栅栏和允许旧镜像范围 |
| `backup_policy` | 必备数据集合、验证/恢复演练门槛、恢复覆盖及保留规则 |

版本矩阵必须校验正在运行和离线待回归节点、所有 Worker、插件及 Installer；不可只检查 Core/Gateway 镜像标签。没有远端 Installer 清单时记录 `unknown`，不能推断兼容或自动更新节点。标签用于展示，执行和 checkpoint 保存拉取后验证的 digest。容器本地 image ID 与 OCI registry digest 是不同标识，均可记录，不能相互冒充。

节点清单必须明确 `(core_instance_id,native node_id,source_epoch)`、Machine 分配和协议代际；当前原生 node_id 对应 Core `tx_server.id`，不是节点配置 code 或 Machine ID。v2 迁移保留原节点主键及历史映射，不在每个实例内重新编号后合并。换 Core 实例或重建身份时为新采集注册新 source_epoch，旧 pending 与去重记录沿用原实例/来源映射并先排空或显式迁移；禁止在新 epoch 下重放旧字节。恢复 receipt generation 与身份 source_epoch 是不同机制，不得借更新 generation 清掉去重记录。

下面是**虚构测试发布**的具体 JSON，版本和重复十六进制 digest 仅供校验器/故障测试，不能作为可拉取制品或已验证发布：

```json
{
  "manifest_version": "txboard.upgrade-manifest/v1",
  "release_id": "fixture-platform-v2-alpha1",
  "created_at": 1791770400,
  "components": {
    "core": {"version": "2.0.0-alpha.1", "artifact": "ghcr.io/anrcm0/txboard@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", "required": true},
    "gateway": {"version": "2.0.0-alpha.1", "artifact": "ghcr.io/anrcm0/txboard-gateway@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb", "required": true},
    "deploy": {"version": "2.0.0-alpha.1", "archive_sha256": "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc", "required": true},
    "node": {"version": "2.0.0-alpha.1", "archive_sha256": "dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd", "required": true},
    "credential_provider": {"version": "2.0.0-alpha.1", "archive_sha256": "eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee", "required": false}
  },
  "compatibility": {
    "tested_tuple": {"core": "2.0.0-alpha.1", "gateway": "2.0.0-alpha.1", "deploy": "2.0.0-alpha.1", "node": "2.0.0-alpha.1"},
    "core_schema_read_epochs": [1, 2],
    "core_schema_write_epochs": [2],
    "internal_api_major": 1,
    "secure_channel_major": 2,
    "node_protocol_majors": [1, 2],
    "credential_provider_major": 1,
    "required_node_capabilities": ["usage_receipt_query", "authorization_lease", "revocation_version"]
  },
  "schema": {"family": "native_tx", "allowed_source_epochs": [1], "expanded_epoch": 2, "target_epoch": 2},
  "migrations": [
    {"id": "fixture-subscription-expand", "phase": "expand", "from_epoch": 1, "to_epoch": 2, "checkpoint": "ddl-step", "probe": "fixture-subscription-shape", "recovery": "probe_then_continue"},
    {"id": "fixture-subscription-backfill", "phase": "backfill", "checkpoint": "legacy-user-id", "probe": "fixture-subscription-progress", "recovery": "resume_cursor"},
    {"id": "fixture-subscription-verify", "phase": "verify", "probe": "fixture-finance-usage-reconcile", "recovery": "read_only_repeat"},
    {"id": "fixture-subscription-switch", "phase": "switch", "checkpoint": "writer-generation", "probe": "fixture-writer-generation", "recovery": "probe_then_continue"}
  ],
  "upgrade_policy": {"mode": "maintenance", "order": ["expand", "backfill", "verify", "switch", "activate", "observe"], "old_image_allowed_after_migration": false, "contract_deferred": true},
  "backup_policy": {"required": ["mysql", "core-key-material", "plugin-private-data", "persistent-files", "deployment-config", "usage-receipts"], "checksum_required": true, "restore_rehearsal_required": true, "receipt_recovery_coverage_required": true}
}
```

`required:false` 表示没有安装则无需安装，有安装仍必须检查兼容和排空；Core 可无 Provider 运行，但发行新代理凭据需要可用 Provider。正式矩阵需列出 expand 期间旧/新 binary 混合组合及禁止组合，不可用最终 tuple 推断过渡兼容。没有 matrix 证据的组合一律预检失败，不能为匹配示例版本修改实际版本号。

## 3. 五阶段数据迁移

| 阶段 | 必须保证 |
| --- | --- |
| expand | 仅增加兼容结构/索引；记录每条 DDL 前后探测。MySQL DDL 可能隐式提交，不假设一整个 migration 可回滚 |
| backfill | 小批、固定来源游标；每批数据和游标同事务提交，幂等映射唯一键；不得把并发变更覆盖成旧值 |
| verify | 在一致性边界对账账户/余额/订单/有效期/配额/usage ledger/归属；失败停在本阶段，不能进入 switch |
| switch | 写入栅栏后切换权威读写 generation；旧 Writer/Worker 拒绝写，唯一模型是真相；恢复时探测已生效 generation |
| contract | 单独发布、单独预检；全部消费者退役、旧批次排空、回填对账和回退窗口关闭后才删除旧结构 |

expand/backfill 期间必须在 Manifest 中选定一种策略：停写维护，或可验证的兼容读写与变更追踪；不能仅声称“双写”而没有事务/补偿/对账定义。第一轮默认 maintenance，明确停 Core HTTP mutations、支付写入、所有 queue consumers/scheduler、Node 接收写入和 Plugin callbacks；入口返回有界重试，Node 保留 pending。支付上游重试期限必须覆盖停机窗口或先持久接收回调。Redis 中未落地业务写入必须先排空；v1 queued 批次不能被当作已持久记录跳过。

不能用用户/订单数量和金额总和替代逐实体对账，方案迁移须证明旧 user 套餐、剩余流量、有效期和订单归属保留。使用不泄露 PII 的计数、哈希或不变量报告并保存校验版本。

## 4. 单升级互斥与持久检查点

单机沿用 install scope 的 flock；所有工具刷新、apply、recover、restore 必须使用相同安装身份和锁。Cluster 再取得共享升级租约和单调 fencing token；只有取得本机锁与集群栅栏的执行者可修改服务/schema。心跳失联立即停新 mutation；旧执行者即便恢复也不能提交新检查点。禁止两台主机各有 flock 就认为集群互斥已成立。

检查点必须在每个副作用前写 intent，之后写已验证结果，原子替换并 fsync，在受限权限本地卷保存，Cluster 同步保存可核对的共享记录。字段包含 `upgrade_id`、安装实例 ID、Manifest SHA-256、实际 source/target digests、epoch、当前 phase/step、attempt、intent、observed result、writer generation、lease/fencing token、backup ID/checksum/演练证据、backfill cursor、时间和错误码；不记录秘密、完整业务行或任意执行文本。检查点版本不支持或损坏时停在待调查状态，不根据容器健康猜测成功。

```text
planned -> checked -> fenced -> backup_verified -> expanding -> backfilling
        -> verifying -> switching -> activating -> observing -> completed
任一非终态 -> paused_failed -> recovering -> 原阶段（探测后）
无法证明结果 -> recovery_required（保持写入栅栏）
```

`checked` 表示签名、digest、版本矩阵、权限、磁盘和恢复覆盖通过，不表示已升级。`completed` 必须包括权威读写切换、全部必需角色健康、协议联调及对账成功，插件可选故障单独记录。contract 延后时本次发布可 completed，但不能把旧结构标记已删除。

## 5. 崩溃探测与恢复边界

重新启动先读取锁、检查点、Manifest，随后只读探测：实际容器/镜像 digest、migration history 和真实表/列/索引形状、schema epoch、backfill 游标与行级校验、writer generation、Worker 实例和 queue drain、Node 未决批次、备份完整性。intent 存在而 result 缺失意味着“结果未知”，不是“尚未执行”。每个迁移必须提供可判定 `not_started/partial/applied/unknown` 的内置 probe；partial 使用声明的前向完成路径，unknown 保持暂停，不盲目重跑 `migrate --force`。

优先以已固定目标制品完成前向恢复。恢复旧 binary 仅在未迁移，或 Manifest 明确证明当前实际 schema 与旧 binary 的读写兼容且 writer 已排空时允许；不允许仅按 epoch 数字大小回滚。switch 之后即使 expand 是兼容 DDL，旧 Writer 是否理解新权威和新增业务数据仍需验证。旧镜像不适配已迁移数据时保持维护模式。

恢复旧数据是另一条操作：先隔离全部 Writer，验证备份和 secrets/插件私有数据的同一恢复点，在隔离环境演练，然后审计恢复。必须列明自备份以来的支付、钱包、订单、流量回执及凭据撤销变更；需通过外部支付对账、不可变日志/接收归档或 Node 重放补回，禁止自动覆盖新交易。receipt generation 要更新，撤销版本不能回退到恢复前节点已应用版本以下，租约需重新发行并同步。无法覆盖增量时必须报告恢复点造成的数据损失界限，由恢复流程进行明确处置；“备份可解压”不能证明可无损回退。详见 [Node Usage 恢复约束](../node-usage/README.md)。

备份验证包括必备文件集合、checksum、解压检查、受保护 key material、数据库一致性位置、APP_KEY 与 Provider secrets 对应关系；隔离恢复演练还需 MySQL 导入、migration/epoch/账本对账和应用只读启动。保留当前恢复点直到观察窗口通过并存在后续验证备份，不能在升级中触发滚动保留规则删掉它。备份、检查点和恢复报告权限默认仅运维身份可读。

## 6. 拟议 CLI 与故障验收

以下命令是 **B2 待实现的接口设计**，不能在现有 `txboard`/`update.sh` 上假定可用：

| 拟议命令 | 行为 |
| --- | --- |
| `upgrade plan` | 只读生成具体版本、阶段、兼容缺口和停机/恢复边界报告 |
| `upgrade check` | 校验计划的当前环境和制品，生成绑定 Manifest/实例的预检证据，不迁移 |
| `upgrade apply` | 获取互斥，重做时效性预检，逐阶段执行与持久记录 |
| `upgrade status` | 展示检查点与当前观测差异，不触发副作用 |
| `upgrade recover` | 先探测未知结果，输出具体前向/兼容 binary 恢复选择，按声明路径恢复 |

| 失败注入 | 验收证据 |
| --- | --- |
| 错误签名、digest、未知 epoch/离线 Node/Installer | preflight 拒绝；当前服务和数据未变 |
| 同机双进程、双主机、过期持锁者恢复 | 只有一个有效 fencing token 可执行/推进 |
| 拉取失败或备份缺 APP_KEY/Provider 文件 | 不进入 expand；备份集合与恢复演练分别报告 |
| 写 intent 后 SIGKILL、DDL 提交后掉电 | probe 识别真实形状，未知时暂停，不重复破坏性 DDL |
| backfill 批次提交前后崩溃 | cursor 与数据一致，唯一映射及权益/流量不重复 |
| verify 余额/配额/订单归属差异 | 保持写入栅栏，无法 switch |
| switch 成功而 result 未写、新 Worker 未启动 | generation probe 识别已切换，只启动兼容角色 |
| 新版启动失败且 schema 已变 | 保持维护或前向修复，旧 image 不盲目启动 |
| receipt ACK 后恢复旧备份、支付增量存在 | generation 更新并重放/对账；覆盖不足明确阻断无损恢复声明 |
| 观察期内可选 Plugin 崩溃 | Core 角色健康单独可见，Provider 授权失败关闭，账本不删除 |
| 未退役 v1 消费者或仍有 pending 时 contract | contract 被拒绝，旧字段和去重证据保留 |

B2 完成需要在真实 MySQL/Redis、单机与多执行者环境提交这些故障证据。当前脚本测试通过只能证明现有升级路径，不能计作本契约已实现。
