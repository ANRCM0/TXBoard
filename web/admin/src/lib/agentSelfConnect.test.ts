import { describe, expect, it } from 'vitest'
import {
  AGENT_SELF_CONNECT_GUIDE_PATH,
  agentSelfConnectGuideUrl,
  buildAgentSelfConnectPrompt,
} from './agentSelfConnect'

describe('Agent self-connect prompt', () => {
  it('builds the version-matched guide URL from the current panel origin', () => {
    expect(agentSelfConnectGuideUrl('https://panel.example.com')).toBe(
      'https://panel.example.com' + AGENT_SELF_CONNECT_GUIDE_PATH,
    )
    expect(agentSelfConnectGuideUrl('https://panel.example.com/')).toBe(
      'https://panel.example.com' + AGENT_SELF_CONNECT_GUIDE_PATH,
    )
  })

  it('directs the Agent to the hosted guide without embedding a credential', () => {
    const prompt = buildAgentSelfConnectPrompt(
      'https://panel.example.com/.well-known/txboard-agent-connect.md',
    )

    expect(prompt).toContain('TXBoard 官方 Agent 自助接入文档')
    expect(prompt).toContain('https://panel.example.com/.well-known/txboard-agent-connect.md')
    expect(prompt).toContain('不要安装或启动第二套 TXBoard MCP Server')
    expect(prompt).toContain('txboard_system_status')
    expect(prompt).not.toContain('Bearer ')
    expect(prompt).not.toContain('plain_text_token')
  })
})
