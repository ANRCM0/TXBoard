import { describe, expect, it } from 'vitest'
import { coreNavigationGroups } from './core'

function group(key: string) {
  const value = coreNavigationGroups.find(item => item.key === key)
  if (!value) throw new Error(`missing navigation group: ${key}`)
  return value
}

function routes(key: string) {
  return group(key).items.map(([path, title]) => [path, title])
}

describe('core admin navigation', () => {
  it('uses the agreed top-level information architecture', () => {
    expect(coreNavigationGroups.map(item => item.title)).toEqual([
      '系统管理',
      '扩展中心',
      '智能运维',
      '节点管理',
      '商业管理',
      '用户管理',
      '内容管理',
    ])
  })

  it('keeps system management focused on core settings and audit', () => {
    expect(routes('system')).toEqual([
      ['/config/system', '系统配置'],
      ['/config/frontend', '前端设置'],
      ['/system/audit-log', '审计日志'],
    ])
  })

  it('groups module, plugin and theme management under extensions', () => {
    expect(routes('extensions')).toEqual([
      ['/system/modules', '模块中心'],
      ['/config/plugin', '插件管理'],
      ['/config/theme', '主题配置'],
    ])
  })

  it('separates Agent Ops, commerce and content management', () => {
    expect(routes('operations')).toEqual([
      ['/system/agent-ops', 'Agent 运维'],
    ])
    expect(routes('commerce')).toEqual([
      ['/finance/plan', '套餐管理'],
      ['/finance/order', '订单管理'],
      ['/config/payment', '支付配置'],
      ['/finance/coupon', '优惠券'],
      ['/finance/gift-card', '礼品卡'],
    ])
    expect(routes('content')).toEqual([
      ['/config/notice', '公告管理'],
      ['/config/knowledge', '知识库管理'],
    ])
  })

  it('does not duplicate host-owned routes across groups', () => {
    const paths = coreNavigationGroups.flatMap(item =>
      item.items.map(([path]) => path),
    )
    expect(new Set(paths).size).toBe(paths.length)
  })
})
