import { describe, expect, it } from 'vitest'
import { nodeConnectionLabel, nodeStatus } from './nodeListPresentation'

describe('node management presentation', () => {
  it('does not confuse missing status with offline, and disabled takes precedence', () => {
    expect(nodeStatus({})).toEqual({label:'未知',tone:'neutral'})
    expect(nodeStatus({enabled:true,online:true})).toEqual({label:'在线',tone:'success'})
    expect(nodeStatus({enabled:true,online:1})).toEqual({label:'在线',tone:'success'})
    expect(nodeStatus({enabled:true,online:false})).toEqual({label:'离线',tone:'warning'})
    expect(nodeStatus({enabled:false,online:true})).toEqual({label:'已禁用',tone:'neutral'})
  })
  it('handles missing and IPv6 addresses without ambiguous port separators', () => {
    expect(nodeConnectionLabel({})).toBe('未设置地址')
    expect(nodeConnectionLabel({host:'a.example.com',port:443})).toBe('a.example.com:443')
    expect(nodeConnectionLabel({host:'2001:db8::1',port:8443})).toBe('[2001:db8::1]:8443')
    expect(nodeConnectionLabel({host:'[::1]',port:8443})).toBe('[::1]:8443')
  })
})
