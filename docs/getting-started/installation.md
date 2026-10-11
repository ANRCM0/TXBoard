# 部署准备与安装

> **权威安装说明位于 [TXBoard-Deploy](https://github.com/ANRCM0/TXBoard-Deploy)**。本页只提供选择路径和上线前检查，不复制部署脚本的详细选项。

## 选择部署方式

| 场景 | 推荐 |
| --- | --- |
| 新服务器首次安装 | 使用 TXBoard-Deploy 交互安装向导 |
| Linux + Docker / 1Panel 现有 MySQL | 在安装器中选择已支持的数据库连接方式；目标必须为空 |
| 生产环境部署 | 先在隔离预发环境固定版本/镜像 digest 并完成验收 |
| 从 XBoard 导入数据 | 使用 Deploy 的 **独立空目标数据库**导入流程；保留源库备份 |
| 已存在 TXBoard | 走安全升级路径，不要复用全新安装流程 |

部署环境需 Linux amd64/arm64、可访问 GitHub/GHCR、Docker Engine 与 Docker Compose v2。实际网络、反向代理、存储及权限要求以部署工具当前说明为准。

## 新安装

1. 阅读 [部署仓库 README](https://github.com/ANRCM0/TXBoard-Deploy#readme)，决定 HTTPS 与数据库连接方式。
2. 下载并审查部署脚本；从可信主机执行安装器。
3. 按向导完成管理员邮箱、访问入口和数据库配置；保存首次输出的凭据。
4. 验证容器和应用健康状态，再打开后台完成 [首次配置](first-setup.md)。
5. 正式接入业务前执行 [隔离预发验收](../operations/release-staging-acceptance.md)。

```bash
curl -fL https://raw.githubusercontent.com/ANRCM0/TXBoard-Deploy/main/install.sh -o install.sh
less install.sh
sudo bash install.sh
```

## 不允许省略的保护

- **不要**直接使用开发版镜像运营真实支付与用户数据；`latest` 也不是开发分支最新提交的同义词。见 [镜像发布通道](../operations/image-release-channels.md)。
- **不要**用全新安装器覆盖已有 TXBoard/XBoard 生产目录、数据卷或数据库。
- **不要**认为原生 `tx_*` 结构会自动转换历史表；需在离线快照及独立目标库验证，详见 [数据库边界](../operations/native-schema.md)。
- 开启第三方 Node、Agent、插件、外部支付商之前按具体合同和运行时分别验证鉴权与行为。

完整安装参数、升级/回滚与旧版导入以 [TXBoard-Deploy 文档](https://github.com/ANRCM0/TXBoard-Deploy#readme) 为准。