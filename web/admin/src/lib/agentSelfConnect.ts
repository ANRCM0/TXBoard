export const AGENT_SELF_CONNECT_GUIDE_PATH = '/.well-known/txboard-agent-connect.md'

export function agentSelfConnectGuideUrl(origin: string) {
  return origin.replace(/\/$/, '') + AGENT_SELF_CONNECT_GUIDE_PATH
}

export function buildAgentSelfConnectPrompt(guideUrl: string, pairingCode?: string | null) {
  if (pairingCode) {
    return `请按 ${guideUrl} 自助接入 TXBoard；一次性配对码：${pairingCode}`
  }

  return [
    '请帮我把当前 Agent 接入 TXBoard Agent Ops。',
    '',
    '请先读取并严格按照 TXBoard 官方 Agent 自助接入文档执行：',
    guideUrl,
    '',
    '当前没有可用的一次性配对码。需要凭据时请引导我通过当前 Agent 的本地 secret / env 机制提供长期 Agent Token，不要要求我把 Token 发到聊天里。',
    '配置完成后只做只读验证，并保持 TXBoard permission、target scope、approval 与 audit 边界。',
  ].join('\n')
}
