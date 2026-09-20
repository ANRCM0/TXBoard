# 节点审计 agent 部署指南

在**每台节点服务器**上部署一个 agent，解析 xray access log，命中名单后上报面板。

> ⚠️ **仅适用 xray 内核**。sing-box 内核请用 tx-node（内嵌 audit 模块，`config.yml` 加 `audit.enabled: true` 即可），无需本 agent。

## 1. 节点开启 access log + sniffing

xboard-node 默认把 xray access log 丢弃（`"access": ""`）且不开 sniffing。需要给节点加一份 custom config。

在节点服务器创建 `/usr/local/xboard-node/conf/custom-audit.json`（路径随意，能读到就行）：

```json
{
  "log": {
    "access": "/var/log/xray/access.log",
    "loglevel": "warning"
  }
}
```

> 说明：`log` 在 xboard-node 的 merge 保护名单里，custom config 的 `log.access` 是否生效取决于你部署的 xboard-node 版本。验证方法：改完后 `cat` 生成的运行时配置（通常在 `/usr/local/xboard-node/` 下，或用 `xbctl` 查看），确认 `log.access` 指向了文件。**如果自定义不生效，替代方案：直接给 xray 加一个独立 access log（见第 4 节 FAQ）。**

xboard-node 的 `config.yml` 里对应实例加：

```yaml
kernel:
  custom_config: "/usr/local/xboard-node/conf/custom-audit.json"
```

sniffing：xboard-node 生成的 inbound 默认**没有** sniffing 配置。由于 `inbounds` 在 merge 保护名单里，custom config 无法覆盖。两个办法：

- **办法 A（推荐）**：多数情况下不需要 sniffing——代理协议本身携带目标域名（SNI/HTTP host/vmess 地址），access log 里的 `accepted tcp:域名:端口` 就是真实目标。只有客户端直连 IP 且靠 TLS SNI 才知道域名的场景才需要 sniffing。
- **办法 B**：需要 sniffing 时，fork/补丁 xboard-node 的 `buildInbound` 加 `"sniffing": {"enabled": true, "destOverride": ["http", "tls", "quic"]}`。

改完重启节点：`systemctl restart xboard-node`（或 docker 重启）。

准备日志目录并配置轮转（防撑爆磁盘）：

```bash
mkdir -p /var/log/xray && chmod 755 /var/log/xray

cat > /etc/logrotate.d/xray-audit <<'EOF'
/var/log/xray/access.log {
    daily
    rotate 3
    maxsize 100M
    missingok
    notifempty
    copytruncate
}
EOF
```

`copytruncate` 模式下 agent 的 tailer 已处理（文件大小变小自动重开）。

## 2. 部署 agent

```bash
mkdir -p /opt/xboard-audit-agent
cd /opt/xboard-audit-agent

# 上传 audit-agent.py 和 audit-agent.yml.example
cp audit-agent.yml.example audit-agent.yml
vim audit-agent.yml   # 填 panel_url / server_token / node_id / log_path

# systemd 托管
cp audit-agent.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now audit-agent
systemctl status audit-agent
journalctl -u audit-agent -f
```

无 PyYAML 也能跑（内置降级解析器），有则更稳：`pip3 install pyyaml` 或 `apt install python3-yaml`。

## 3. 联调验证

1. 面板 `/plugin/access-audit` 添加一条测试规则：`domain_suffix` = `test-illegal.example.com`
2. 节点上看 agent 日志：`规则已刷新: 1 条`
3. 手动往 access log 写一条模拟记录（格式对齐真实日志）：
   ```bash
   echo "2026/09/16 10:00:00 from 1.2.3.4:5678 accepted tcp:test-illegal.example.com:443 [vmess-in >> direct] email: user@6" >> /var/log/xray/access.log
   ```
4. 15 秒内 agent 日志出现 `上报 1 条: matched=1 banned=0`
5. 面板「命中记录」出现该条记录
6. 连续写 3 条 → 用户 6 被封禁（测试用户！），TG 收到告警
7. 测完在面板删除测试规则、解封/删除测试用户、清空命中记录

## 4. 用户标识（user_id）说明

面板上报接口要求 `user_id` 是**数字**。agent 按以下顺序识别 access log 中的用户标识（`email:` 字段）：

| 日志中的形态 | agent 行为 |
|---|---|
| `user@123`（xboard-node 默认，user_id 编进 email） | 直接取 `123`，无需任何额外配置 |
| `任意前缀@123`（如 `vip7@88`） | 取 `88`（不强制前缀为 `user`） |
| `user@<uuid>` 或 `<uuid>` | 用**用户目录**（见下）反查为 user_id |
| 其他（如真实邮箱 `a@example.com`） | 跳过该行 |

### 用户目录（uuid → user_id 映射）

当 access log 里是 uuid 时，agent 会按 `user_refresh`（默认 600 秒）定期从面板拉取用户列表建映射：

- 旧版认证（token + node_id）：`GET /api/v1/server/UniProxy/user`
- machine 模式：`GET /api/v2/server/user`

配置项 `user_refresh`：
- `> 0`：启用目录，按此间隔刷新
- `= 0`：关闭。此时遇到 uuid 记录会跳过，并**告警一次**（不刷日志）

设计要点：
- 拉取失败时**保留上一次的映射**，网络抖动不会导致全部无法上报
- 查不到的 uuid 只告警一次（避免日志刷屏），下次刷新目录时重置
- 只在确实出现 uuid 时才触发目录刷新，纯数字场景零额外请求

验证映射是否生效：agent 启动日志应出现 `用户目录已刷新: N 条 uuid 映射`。

## 5. FAQ

**Q: access log 里 email 不是 user@ID 格式？**
A: 先看是 `user@<uuid>` 还是真实邮箱。若是 uuid，确认 `user_refresh > 0` 且 agent 日志有 `用户目录已刷新`；若目录刷新失败（面板路径/认证问题），日志会有 `用户目录刷新失败(保留旧映射)`。若确实是真实邮箱（其他面板如 V2bX），需要改正则 `EMAIL_RE`。

**Q: custom config 的 log.access 不生效？**
A: 老版本 xboard-node 可能硬编码丢弃。检查 xboard-node 运行时生成的 xray 配置；实在不行，用 sidecar 方案：iptables/nflog 或 eBPF 抓 SNI（超纲，需要再说）。

**Q: 性能影响？**
A: agent 是单线程逐行正则 + 内存匹配，实测万级规则下单行匹配 <1ms；上报攒批 15s 一次。用户目录默认 10 分钟拉一次，开销可忽略。瓶颈通常在 xray 写日志本身——所以规则只配"需要审计的"，不要追求全量。

**Q: 用户量大时会不会漏？**
A: 队列在内存里，agent 崩溃会丢未上报的命中。封禁是阈值制（窗口内 N 次），丢一两条只会延迟封禁不会漏判。

**Q: machine 模式怎么配？**
A: 填 `machine_id`，认证自动切成 machine 模式（`machine_id + token`），用户列表也自动走 `/api/v2/server/user`。
