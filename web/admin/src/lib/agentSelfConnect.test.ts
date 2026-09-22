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

  it('builds a one-sentence pairing prompt without the long-lived token', () => {
    const prompt = buildAgentSelfConnectPrompt(
      'https://panel.example.com/.well-known/txboard-agent-connect.md',
      'txbp_abcdefghijklmnopqrstuvwx',
    )

    expect(prompt).toBe(
      '请按 https://panel.example.com/.well-known/txboard-agent-connect.md 自助接入 TXBoard；一次性配对码：txbp_abcdefghijklmnopqrstuvwx',
    )
    expect(prompt).not.toContain('Bearer ')
    expect(prompt).not.toContain('plain_text_token')
  })

  it('keeps the v1 manual-secret fallback when pairing is unavailable', () => {
    const prompt = buildAgentSelfConnectPrompt(
      'https://panel.example.com/.well-known/txboard-agent-connect.md',
      null,
    )

    expect(prompt).toContain('当前没有可用的一次性配对码')
    expect(prompt).toContain('本地 secret / env')
    expect(prompt).not.toContain('Bearer ')
  })
})
