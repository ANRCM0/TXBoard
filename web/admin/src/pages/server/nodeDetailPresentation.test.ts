import { describe, expect, it } from 'vitest'
import { nodeDetailAddress, nodeDetailMachineStatus, nodeDetailPresence, nodeDetailTraffic, redactActionResult } from './nodeDetailPresentation'

describe('Node Detail UI v3 presentation', () => {
  it('keeps unknown online state separate from offline and disabled', () => {
    expect(nodeDetailPresence({})).toMatchObject({label:'状态未知',tone:'neutral'})
    expect(nodeDetailPresence({enabled:true,online:false})).toMatchObject({label:'离线',tone:'warning'})
    expect(nodeDetailPresence({online:true})).toMatchObject({label:'在线',tone:'success'})
    expect(nodeDetailPresence({enabled:false,online:1})).toMatchObject({label:'已停用',tone:'neutral'})
  })
  it('handles addresses, IPv6 and missing host', () => {
    expect(nodeDetailAddress({})).toBe('未配置')
    expect(nodeDetailAddress({host:'::1',port:443})).toBe('[::1]:443')
    expect(nodeDetailAddress({host:'[::1]',port:443})).toBe('[::1]:443')
    expect(nodeDetailAddress({host:'node.example.com',port:443})).toBe('node.example.com:443')
  })
  it('does not turn unknown machine heartbeat into offline', () => {
    const now=Date.UTC(2026,9,11)
    expect(nodeDetailMachineStatus(undefined,now)).toBe('未关联机器')
    expect(nodeDetailMachineStatus({},now)).toBe('机器未连接')
    expect(nodeDetailMachineStatus({is_active:false},now)).toBe('机器已停用')
    expect(nodeDetailMachineStatus({last_seen_at:now-20_000},now)).toBe('机器在线')
    expect(nodeDetailMachineStatus({last_seen_at:now-900_000},now)).toBe('机器离线')
  })
  it('distinguishes zero bytes, small usage and missing usage', () => {
    expect(nodeDetailTraffic({id:1}).value).toBe('未上报')
    expect(nodeDetailTraffic({id:1,u:0,d:0}).value).toBe('0 B')
    expect(nodeDetailTraffic({id:1,u:500,d:524288}).value).toContain('KB')
    expect(nodeDetailTraffic({id:1,u:0,d:1048576,transfer_enable:1073741824}).value)
      .toBe('1 MB / 1 GB')
  })
  it('redacts sensitive diagnostics recursively before printing them', () => {
    expect(redactActionResult({token:'raw-secret',nested:{password:'mypassword'},ok:true}))
      .not.toContain('raw-secret')
    expect(redactActionResult({token:'raw-secret',nested:{password:'mypassword'},ok:true}))
      .not.toContain('mypassword')
    expect(redactActionResult({ok:true})).toContain('true')
    const circular:Record<string,unknown>={};circular.self=circular
    expect(redactActionResult(circular)).toContain('循环引用')
  })
})
