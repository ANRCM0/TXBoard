export const AGENT_SELF_CONNECT_GUIDE_PATH = '/.well-known/txboard-agent-connect.md'

export function agentSelfConnectGuideUrl(origin: string) {
  return origin.replace(/\/$/, '') + AGENT_SELF_CONNECT_GUIDE_PATH
}

export function buildAgentSelfConnectPrompt(guideUrl: string) {
  return [
    '请帮我把当前 Agent 接入 TXBoard Agent Ops。',
    '',
    '请先读取并严格按照 TXBoard 官方 Agent 自助接入文档执行：',
    guideUrl,
    '',
    '要求：',
    '1. 自动识别当前 Agent / CLI 环境，并使用它原生的 Remote HTTP / Streamable HTTP MCP 配置方式。',
    '2. TXBoard 已托管 MCP Gateway；不要安装或启动第二套 TXBoard MCP Server。',
    '3. 需要凭据时优先使用当前 Agent 的本地 secret / env 机制；不要要求我把长期 Agent Token 发到聊天里，也不要把 Token 写进仓库。',
    '4. 配置完成后只做只读连接验证，优先使用 txboard_system_status 或 txboard_fleet_health。',
    '5. 不要绕过 TXBoard permission、target scope、approval 或 audit；不要把 MySQL、Redis、TX-Node 直连、SSH、Docker 或通用远程 Shell 当作替代控制通道。',
    '6. 最后告诉我检测到的 Agent、修改/使用的配置位置、MCP endpoint、认证与工具发现结果，以及是否需要 reload / restart。',
  ].join('\n')
}
